<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Health;

use M2Oidc\OAuth\Helper\Curl;

/**
 * Content-level reachability check for an OIDC provider.
 *
 * Prefers the JWKS endpoint, confirming the response decodes to JSON containing
 * a "keys" field — a genuine signal that the IdP is serving valid key material,
 * not just that something answered the TCP connection. Falls back to the
 * discovery document (checked for an "authorization_endpoint" field) when no
 * jwks_endpoint is configured. Mirrors the same JWKS check already used by
 * Controller/Adminhtml/Actions/HealthCheck.php's admin-facing health check, so
 * the automated alerting cron and the manual "Health Check" button agree on
 * what "reachable" means for a given provider.
 */
class ProviderReachabilityChecker
{
    /** @var Curl */
    private readonly Curl $curl;

    /**
     * @param Curl $curl
     */
    public function __construct(Curl $curl)
    {
        $this->curl = $curl;
    }

    /**
     * Probe the provider's JWKS endpoint, or its discovery document if unset.
     *
     * Returns null (nothing to check — callers should skip, not count as a failure)
     * when neither a jwks_endpoint nor a well_known_config_url is configured.
     *
     * @param mixed[] $clientDetails Provider row data
     */
    public function isReachable(array $clientDetails): ?bool
    {
        $jwksUrl = trim((string) ($clientDetails['jwks_endpoint'] ?? ''));
        if ($jwksUrl !== '') {
            return $this->probe($jwksUrl, 'keys');
        }

        $discoveryUrl = trim((string) ($clientDetails['well_known_config_url'] ?? ''));
        if ($discoveryUrl !== '') {
            return $this->probe($discoveryUrl, 'authorization_endpoint');
        }

        return null;
    }

    /**
     * Fetch $url and check the decoded JSON body contains $requiredKey.
     *
     * @param string $url
     * @param string $requiredKey
     */
    private function probe(string $url, string $requiredKey): bool
    {
        try {
            $body    = $this->curl->sendUserInfoRequest($url, []);
            $decoded = json_decode($body, true);
            return is_array($decoded) && isset($decoded[$requiredKey]);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
