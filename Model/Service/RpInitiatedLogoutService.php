<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\HTTP\Adapter\CurlFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Shared RP-Initiated Logout logic for the admin and customer flows (M29).
 *
 * Extracted from OidcLogoutPlugin (admin) and OAuthLogoutObserver (customer),
 * which previously duplicated ~90% of this implementation. The callers keep
 * what is genuinely flow-specific — session source (AuthSession vs
 * CustomerSession), guard-cookie TTL (admin 120 s, customer 300 s), state
 * prefix (admin:<hex> vs customer:<hex>) and redirect mechanics — while this
 * service owns the provider-independent pieces:
 *
 *  - Authelia Forward-Auth detection heuristic
 *  - post_logout_redirect_uri resolution incl. the validated per-provider
 *    post_logout_url override (M28)
 *  - end_session logout URL construction (standard OIDC vs Authelia ?rd=)
 *  - RFC 7009 token revocation (fire-and-forget, non-fatal)
 */
class RpInitiatedLogoutService
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var CurlFactory */
    private readonly CurlFactory $curlFactory;

    /**
     * Initialize RP-Initiated Logout service.
     *
     * @param OAuthUtility $oauthUtility
     * @param CurlFactory  $curlFactory
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CurlFactory $curlFactory
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->curlFactory  = $curlFactory;
    }

    /**
     * Detect Authelia's Forward-Auth /logout endpoint.
     *
     * Heuristic: the path ends with /logout but contains neither /oauth2/ nor
     * /oidc/. This distinguishes Authelia Forward-Auth from a genuine OIDC
     * end_session_endpoint (e.g. Keycloak's /oidc/logout).
     *
     * @param string $endsessionEndpoint The end session endpoint URL to check
     */
    public function isAutheliaForwardAuthLogout(string $endsessionEndpoint): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $path = (string) parse_url($endsessionEndpoint, PHP_URL_PATH);
        return str_ends_with(rtrim($path, '/'), '/logout')
            && !str_contains($path, '/oauth2/')
            && !str_contains($path, '/oidc/');
    }

    /**
     * Resolve the post-logout redirect URI.
     *
     * Priority:
     *  1) provider.post_logout_url — pending-schema per-provider override; the
     *     column does not exist in etc/db_schema.xml yet, so the key is
     *     currently absent at runtime, but the read is kept intentionally so
     *     the override activates once the schema catches up.
     *  2) $fallbackUri — caller-computed default (unified postlogout callback
     *     for standard OIDC, static login/admin URL for Authelia ?rd= mode).
     *
     * M28: the override is only honored when it passes FILTER_VALIDATE_URL on
     * BOTH the admin and the customer path; an invalid value falls back.
     *
     * @param mixed[]|null $provider    Provider data array or null
     * @param string       $fallbackUri Caller-specific fallback URI
     */
    public function resolvePostLogoutRedirectUri(?array $provider, string $fallbackUri): string
    {
        if ($provider !== null && !empty($provider['post_logout_url'])) {
            $override = (string) $provider['post_logout_url'];
            if (filter_var($override, FILTER_VALIDATE_URL) !== false) {
                return $override;
            }
            $this->oauthUtility->customlog(
                'RpInitiatedLogoutService: Ignoring invalid post_logout_url override — using fallback.'
            );
        }

        return $fallbackUri;
    }

    /**
     * Build the full logout redirect URL.
     *
     * Standard OIDC: end_session_endpoint?id_token_hint=…&state=…&post_logout_redirect_uri=…
     * Authelia:      /logout?rd=<url> — NEVER the current request URL, which
     *                would contain a dynamic key/-token that cannot be
     *                registered as an allowed redirect URI.
     *
     * @param string $endSessionEndpoint    Validated end session endpoint URL
     * @param string $idToken               id_token for id_token_hint ('' to omit)
     * @param string $state                 Caller-prefixed state value (admin:<hex> / customer:<hex>)
     * @param string $postLogoutRedirectUri Resolved post-logout redirect URI ('' to omit)
     */
    public function buildLogoutUrl(
        string $endSessionEndpoint,
        string $idToken,
        string $state,
        string $postLogoutRedirectUri
    ): string {
        $endpoint = rtrim($endSessionEndpoint, '/');
        $params   = [];

        if ($this->isAutheliaForwardAuthLogout($endSessionEndpoint)) {
            // Authelia Forward-Auth: "rd" = static caller-provided URL
            if ($postLogoutRedirectUri !== '') {
                $params['rd'] = $postLogoutRedirectUri;
            }
        } else {
            // Standard OIDC RP-Initiated Logout
            if ($idToken !== '') {
                $params['id_token_hint'] = $idToken;
            }
            $params['state'] = $state;
            if ($postLogoutRedirectUri !== '' && filter_var($postLogoutRedirectUri, FILTER_VALIDATE_URL) !== false) {
                $params['post_logout_redirect_uri'] = $postLogoutRedirectUri;
            }
        }

        if ($params === []) {
            return $endpoint;
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        return $endpoint . $separator . http_build_query($params);
    }

    /**
     * Call the RFC 7009 token revocation endpoint (fire-and-forget).
     *
     * No-op when the provider is unknown, has no revocation_endpoint, or no
     * access token is available. Failure is non-fatal: we log and continue
     * the logout flow.
     *
     * @param mixed[]|null $provider    Provider data array or null
     * @param string       $accessToken Access token to revoke ('' skips)
     * @param string       $logContext  Log prefix of the calling flow (kept so existing log messages survive the move)
     */
    public function revokeToken(
        ?array $provider,
        string $accessToken,
        string $logContext = 'RpInitiatedLogoutService'
    ): void {
        if ($provider === null || empty($provider['revocation_endpoint']) || $accessToken === '') {
            return;
        }

        $revocationEndpoint = (string) $provider['revocation_endpoint'];

        try {
            $curl = $this->curlFactory->create();
            $curl->setConfig(['timeout' => 5]);
            $curl->write(
                'POST',
                $revocationEndpoint,
                '1.1',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'token'           => $accessToken,
                    'token_type_hint' => 'access_token',
                    'client_id'       => (string) ($provider['clientID'] ?? ''),
                    'client_secret'   => (string) ($provider['client_secret'] ?? ''),
                ])
            );
            $curl->read();
            $curl->close();
            $this->oauthUtility->customlog(
                $logContext . ': Token revocation request sent to ' . $revocationEndpoint
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                $logContext . ': Token revocation failed (non-fatal): ' . $e->getMessage()
            );
        }
    }
}
