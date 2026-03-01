<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Dynamically adds every active OIDC provider's hostname to Magento's
 * Content Security Policy at runtime (MP-09 — multi-provider CSP).
 *
 * This replaces the static csp_whitelist.xml approach, which required
 * hardcoding a customer-specific domain into the module — making the
 * package unsuitable for distribution.
 *
 * Single-provider fallback: when the `miniorange_oauth_client_apps` table
 * is empty (fresh install, pre-migration), the legacy store-config
 * `AUTHORIZE_URL` value is used so the page does not break.
 *
 * Registered as a collector via di.xml under
 * Magento\Csp\Model\Collector\MergeCollector.
 */
class OidcCspPolicyCollector implements PolicyCollectorInterface
{
    /**
     * CSP directives that every OIDC provider needs to be whitelisted for.
     *
     *  - form-action : browser POSTs the auth code back to IdP redirect endpoint
     *  - connect-src : JS fetch/XHR to IdP (discovery, token endpoint, JWKS)
     *  - frame-src   : some IdP flows use iframes for silent refresh
     *  - img-src     : IdP may serve logos or user avatars
     */
    private const DIRECTIVES = [
        'form-action',
        'connect-src',
        'frame-src',
        'img-src',
    ];

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /**
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Inject every active OIDC provider's host into all relevant fetch-directive policies.
     *
     * MP-09: Iterates `getAllActiveProviders('both')` to collect every configured
     * provider's authorize_endpoint host. De-duplicates hosts so a single IdP
     * shared across customer and admin login only appears once. Falls back to the
     * legacy store-config AUTHORIZE_URL when no provider rows exist.
     *
     * @inheritdoc
     */
    #[\Override]
    public function collect(array $defaultPolicies = []): array
    {
        $hosts = $this->collectProviderHosts();

        if ($hosts === []) {
            return $defaultPolicies;
        }

        foreach (self::DIRECTIVES as $directive) {
            $defaultPolicies[] = new FetchPolicy(
                $directive,
                false,    // nonce not allowed via this rule
                $hosts,
                []        // no scheme-only sources
            );
        }

        $this->oauthUtility->customlog(
            'OidcCspPolicyCollector: Added ' . count($hosts) . ' host(s) to CSP directives ['
            . implode(', ', self::DIRECTIVES) . ']: ' . implode(', ', $hosts)
        );

        return $defaultPolicies;
    }

    /**
     * Collect deduplicated HTTPS host strings from all active provider rows.
     *
     * MP-09: Falls back to store-config AUTHORIZE_URL when the provider table
     * has no rows yet (pre-migration / fresh install).
     *
     * @return list<string> e.g. ['https://login.microsoftonline.com', 'https://accounts.google.com']
     */
    private function collectProviderHosts(): array
    {
        $hosts = [];

        // Primary source: active provider rows (multi-provider, Sprint 5+)
        $providers = $this->oauthUtility->getAllActiveProviders('both');
        foreach ($providers as $provider) {
            // Collect hosts from endpoint URLs that touch the IdP network
            $endpointFields = [
                'authorize_endpoint',
                'access_token_endpoint',
                'user_info_endpoint',
                'jwks_endpoint',
            ];
            foreach ($endpointFields as $field) {
                $url = (string) ($provider[$field] ?? '');
                $host = $this->extractHttpsHost($url);
                if ($host !== '' && !in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
            }
        }

        // Fallback: legacy single-provider store-config (no provider rows yet)
        if ($hosts === []) {
            $legacyUrl  = (string) $this->oauthUtility->getStoreConfig(OAuthConstants::AUTHORIZE_URL);
            $legacyHost = $this->extractHttpsHost($legacyUrl);
            if ($legacyHost !== '') {
                $hosts[] = $legacyHost;
            }
        }

        return $hosts;
    }

    /**
     * Extract "https://hostname" from a URL, or return '' if not a valid HTTPS URL.
     *
     * @param  string $url
     * @return string e.g. 'https://idp.example.com' or ''
     */
    private function extractHttpsHost(string $url): string
    {
        if ($url === '') {
            return '';
        }
        // phpcs:disable Magento2.Functions.DiscouragedFunction.Discouraged
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);
        // phpcs:enable Magento2.Functions.DiscouragedFunction.Discouraged
        if (empty($host) || $scheme !== 'https') {
            return '';
        }
        return 'https://' . $host;
    }
}
