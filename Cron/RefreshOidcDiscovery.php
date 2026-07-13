<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Logger\OidcLogger;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory as ProviderCollectionFactory;
use M2Oidc\OAuth\Model\Validation\SsrfUrlValidator;

/**
 * Periodically re-fetches the OIDC discovery document for every active provider
 * that has a well_known_config_url configured.
 *
 * This keeps authorization_endpoint, token_endpoint, userinfo_endpoint, jwks_uri,
 * end_session_endpoint, and revocation_endpoint up-to-date without requiring a
 * manual provider save. Only fields whose values have changed are written to the DB
 * (dirty-check to avoid unnecessary I/O).
 *
 * Scheduled every 6 hours via etc/crontab.xml (m2oidc_refresh_oidc_discovery).
 */
class RefreshOidcDiscovery
{
    /**
     * Map from OIDC discovery document key → m2oidc_oauth_client_apps column name.
     * Only these fields are updated by the cron; other provider configuration is left untouched.
     *
     * @var array<string, string>
     */
    private const DISCOVERY_FIELD_MAP = [
        'authorization_endpoint' => 'authorize_endpoint',
        'token_endpoint'         => 'access_token_endpoint',
        'userinfo_endpoint'      => 'user_info_endpoint',
        'jwks_uri'               => 'jwks_endpoint',
        'end_session_endpoint'   => 'endsession_endpoint',
        'revocation_endpoint'    => 'revocation_endpoint',
        'issuer'                 => 'issuer',
    ];

    /** @var ProviderCollectionFactory */
    private readonly ProviderCollectionFactory $collectionFactory;

    /** @var Curl */
    private readonly Curl $curl;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var OidcLogger */
    private readonly OidcLogger $oidcLogger;

    /** @var SsrfUrlValidator */
    private readonly SsrfUrlValidator $ssrfUrlValidator;

    /**
     * @param ProviderCollectionFactory $collectionFactory
     * @param Curl                      $curl
     * @param OAuthUtility              $oauthUtility
     * @param OidcLogger                $oidcLogger
     * @param SsrfUrlValidator          $ssrfUrlValidator
     */
    public function __construct(
        ProviderCollectionFactory $collectionFactory,
        Curl $curl,
        OAuthUtility $oauthUtility,
        OidcLogger $oidcLogger,
        SsrfUrlValidator $ssrfUrlValidator
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->curl = $curl;
        $this->oauthUtility = $oauthUtility;
        $this->oidcLogger = $oidcLogger;
        $this->ssrfUrlValidator = $ssrfUrlValidator;
    }

    /**
     * Re-fetch discovery documents for all active providers with a well_known_config_url.
     *
     * Each provider is processed independently; failures are logged and skipped.
     */
    public function execute(): void
    {
        $this->oidcLogger->customlog('RefreshOidcDiscovery: Starting scheduled discovery refresh');

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->addFieldToFilter('well_known_config_url', ['neq' => '']);
        $collection->addFieldToFilter('well_known_config_url', ['notnull' => true]);

        $processed = 0;
        $updated   = 0;

        foreach ($collection as $provider) {
            $data           = $provider->getData();
            $providerId     = (int) ($data['id'] ?? 0);
            $discoveryUrl   = trim((string) ($data['well_known_config_url'] ?? ''));
            $appName        = (string) ($data['app_name'] ?? "provider #{$providerId}");

            if ($providerId <= 0 || $discoveryUrl === '') {
                continue;
            }

            $processed++;

            $discovered = $this->fetchDiscovery($discoveryUrl, $appName);
            if ($discovered === null) {
                continue;
            }

            $changes = $this->computeChanges($data, $discovered);
            if ($changes === []) {
                $this->oidcLogger->customlog(
                    "RefreshOidcDiscovery: [{$appName}] No endpoint changes detected — skipping DB write"
                );
                continue;
            }

            $this->oauthUtility->saveProviderData($providerId, $changes);
            $changedFields = implode(', ', array_keys($changes));
            $this->oidcLogger->customlog(
                "RefreshOidcDiscovery: [{$appName}] Updated fields: {$changedFields}"
            );
            $updated++;
        }

        $this->oidcLogger->customlog(
            "RefreshOidcDiscovery: Done — {$processed} provider(s) processed, {$updated} updated"
        );
    }

    /**
     * Fetch and decode the OIDC discovery document.
     *
     * @param  string $url      Discovery URL
     * @param  string $appName  Provider label for logging
     * @return \stdClass|null   Decoded document, or null on error
     */
    private function fetchDiscovery(string $url, string $appName): ?\stdClass
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $validated = filter_var($url, FILTER_VALIDATE_URL);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        if ($validated === false || parse_url($validated, PHP_URL_SCHEME) !== 'https') {
            $this->oidcLogger->customlog(
                "RefreshOidcDiscovery: [{$appName}] Skipping — discovery URL is not a valid HTTPS URL: {$url}"
            );
            return null;
        }

        // SSRF protection — never fetch discovery documents from private hosts.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $host = (string) parse_url($validated, PHP_URL_HOST);
        if ($this->ssrfUrlValidator->isPrivateHost($host)) {
            $this->oidcLogger->customlog(
                "RefreshOidcDiscovery: [{$appName}] WARNING: Blocked SSRF attempt — "
                . "discovery URL points to a private or internal host: {$url}"
            );
            return null;
        }

        try {
            $body = $this->curl->sendUserInfoRequest($validated, []);
            $obj  = json_decode($body);
        } catch (\Exception $e) {
            $this->oidcLogger->customlog(
                "RefreshOidcDiscovery: [{$appName}] Fetch error: " . $e->getMessage()
            );
            return null;
        }

        if (!$obj instanceof \stdClass || !isset($obj->authorization_endpoint, $obj->token_endpoint)) {
            $this->oidcLogger->customlog(
                "RefreshOidcDiscovery: [{$appName}] Invalid discovery document (missing required fields)"
            );
            return null;
        }

        return $obj;
    }

    /**
     * Compare discovered values against stored values; return only changed fields.
     *
     * @param  mixed[]   $storedData Current provider DB row
     * @param  \stdClass $discovered Decoded discovery document
     * @return array<string, string> Column => new value (only fields that changed)
     */
    private function computeChanges(array $storedData, \stdClass $discovered): array
    {
        $changes = [];

        foreach (self::DISCOVERY_FIELD_MAP as $discoveryKey => $dbColumn) {
            if (!isset($discovered->{$discoveryKey})) {
                continue;
            }

            $newValue    = trim((string) $discovered->{$discoveryKey});
            $storedValue = trim((string) ($storedData[$dbColumn] ?? ''));

            if ($newValue !== '' && $newValue !== $storedValue) {
                $changes[$dbColumn] = $newValue;
            }
        }

        return $changes;
    }
}
