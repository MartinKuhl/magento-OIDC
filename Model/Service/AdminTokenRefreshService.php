<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Token refresh service for OIDC access token renewal in admin sessions (FEAT-03 admin).
 *
 * Mirrors TokenRefreshService but operates on the admin AuthSession instead of
 * the customer session. Tokens are stored under identical session keys so the
 * same log messages and constants apply to both services.
 *
 * Called automatically by AdminTokenAutoRefreshObserver on every admin request.
 *
 * Storage: Magento admin auth session keys
 *   oidc_access_token           — Magento-encrypted access token (M-01)
 *   oidc_access_token_expires   — Unix timestamp when it expires
 *   oidc_refresh_token          — Magento-encrypted refresh token
 *   oidc_provider_id            — provider row ID (set by Oidccallback)
 */
class AdminTokenRefreshService
{
    /** Session key for the encrypted refresh token. */
    public const SESSION_REFRESH_TOKEN = 'oidc_refresh_token';

    /** Session key for the current access token. */
    public const SESSION_ACCESS_TOKEN  = 'oidc_access_token';

    /** Session key for the access token expiry Unix timestamp. */
    public const SESSION_TOKEN_EXPIRES = 'oidc_access_token_expires';

    /** Refresh this many seconds before the token actually expires. */
    private const REFRESH_THRESHOLD_SECS = 60;

    /**
     * @param AuthSession        $authSession
     * @param OAuthUtility       $oauthUtility
     * @param Curl               $curl
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly AuthSession       $authSession,
        private readonly OAuthUtility      $oauthUtility,
        private readonly Curl              $curl,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Persist tokens in the admin auth session (encrypted).
     *
     * Call this after a successful token exchange in Oidccallback, when
     * access_token and optionally refresh_token are present.
     *
     * @param string $refreshToken Plain-text refresh token from the IdP (may be empty)
     * @param int    $expiresIn    Access token lifetime in seconds (from `expires_in`)
     * @param string $accessToken  Current access token
     */
    public function storeTokens(string $refreshToken, int $expiresIn, string $accessToken): void
    {
        if ($refreshToken !== '') {
            $this->authSession->setData(
                self::SESSION_REFRESH_TOKEN,
                $this->encryptor->encrypt($refreshToken)
            );
        }
        if ($accessToken !== '') {
            $this->authSession->setData(
                self::SESSION_ACCESS_TOKEN,
                $this->encryptor->encrypt($accessToken)
            );
        }
        if ($expiresIn > 0) {
            $this->authSession->setData(
                self::SESSION_TOKEN_EXPIRES,
                time() + $expiresIn
            );
        }
    }

    /**
     * Return a valid access token, refreshing if the current one is near expiry.
     *
     * Returns null when:
     *  - No admin is logged in.
     *  - No refresh token is stored in the session (single-token flow).
     *  - The refresh request to the IdP failed.
     *
     * @return string|null Fresh access token, or null if unavailable
     */
    public function refreshIfNeeded(): ?string
    {
        if (!$this->authSession->isLoggedIn()) {
            return null;
        }

        $expiresAt       = (int) $this->authSession->getData(self::SESSION_TOKEN_EXPIRES);
        $encryptedAccess = (string) ($this->authSession->getData(self::SESSION_ACCESS_TOKEN) ?? '');

        // Token is still fresh enough — decrypt and return
        if ($encryptedAccess !== '' && $expiresAt > 0 && time() < ($expiresAt - self::REFRESH_THRESHOLD_SECS)) {
            try {
                return $this->encryptor->decrypt($encryptedAccess);
            } catch (\Exception $e) {
                $this->oauthUtility->customlog('AdminTokenRefreshService: Failed to decrypt access token.');
                // Fall through to refresh below
            }
        }

        return $this->refresh();
    }

    /**
     * Force a token refresh regardless of current expiry.
     *
     * @return string|null New access token, or null on failure
     */
    public function refresh(): ?string
    {
        $encryptedRefresh = (string) ($this->authSession->getData(self::SESSION_REFRESH_TOKEN) ?? '');
        if ($encryptedRefresh === '') {
            return null;
        }

        try {
            $refreshToken = $this->encryptor->decrypt($encryptedRefresh);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('AdminTokenRefreshService: Failed to decrypt refresh token.');
            return null;
        }

        if ($refreshToken === '') {
            return null;
        }

        $providerId = (int) $this->authSession->getData('oidc_provider_id');
        $provider   = $providerId > 0
            ? $this->oauthUtility->getClientDetailsById($providerId)
            : null;

        if ($provider === null) {
            $collection = $this->oauthUtility->getOAuthClientApps();
            $provider   = count($collection) > 0 ? $collection->getFirstItem()->getData() : null;
        }

        if ($provider === null) {
            $this->oauthUtility->customlog('AdminTokenRefreshService: No provider configured for refresh.');
            return null;
        }

        return $this->sendRefreshRequest($provider, $refreshToken);
    }

    /**
     * Clear all stored token data from the admin session (call on logout).
     */
    public function clearTokens(): void
    {
        $this->authSession->unsetData(self::SESSION_REFRESH_TOKEN);
        $this->authSession->unsetData(self::SESSION_ACCESS_TOKEN);
        $this->authSession->unsetData(self::SESSION_TOKEN_EXPIRES);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Send the RFC 6749 §6 refresh token grant request.
     *
     * @param  array  $provider     Provider data row
     * @param  string $refreshToken Plain-text refresh token
     * @return string|null          New access token or null on failure
     */
    private function sendRefreshRequest(array $provider, string $refreshToken): ?string
    {
        $tokenEndpoint = (string) ($provider['access_token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            $this->oauthUtility->customlog('AdminTokenRefreshService: No token endpoint configured.');
            return null;
        }

        $clientId     = (string) ($provider['clientID'] ?? '');
        $clientSecret = (string) ($provider['client_secret'] ?? '');
        $header       = (int) ($provider['values_in_header'] ?? 1);
        $body         = (int) ($provider['values_in_body'] ?? 0);

        $postData = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientId,
        ];

        $startMs  = (int) round((float) hrtime(true) / 1e6);
        $response = $this->curl->sendAccessTokenRequest(
            $postData,
            $tokenEndpoint,
            $clientId,
            $clientSecret,
            $header,
            $body
        );
        $elapsed  = (int) round((float) hrtime(true) / 1e6) - $startMs;

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            $this->oauthUtility->customlogContext('oidc.token_refresh_failed', [
                'context'  => 'admin',
                'provider' => $provider['app_name'] ?? '',
                'ms'       => $elapsed,
            ]);
            return null;
        }

        $newAccessToken  = (string) $decoded['access_token'];
        $newRefreshToken = (string) ($decoded['refresh_token'] ?? '');
        $expiresIn       = (int) ($decoded['expires_in'] ?? 3600);

        $this->storeTokens($newRefreshToken, $expiresIn, $newAccessToken);

        $this->oauthUtility->customlogContext('oidc.token_refreshed', [
            'context'    => 'admin',
            'provider'   => $provider['app_name'] ?? '',
            'expires_in' => $expiresIn,
            'ms'         => $elapsed,
        ]);

        return $newAccessToken;
    }
}
