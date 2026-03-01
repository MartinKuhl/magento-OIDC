<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Service;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Encryption\EncryptorInterface;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Token refresh service for OIDC access token renewal (FEAT-03).
 *
 * Implements RFC 6749 §6 — Refreshing an Access Token.
 *
 * At login time, the caller should persist the refresh_token (encrypted) into
 * the customer session via `storeRefreshToken()`. When a request needs a fresh
 * access token, call `refreshIfNeeded()`. The service sends a
 * `grant_type=refresh_token` request to the provider's token endpoint,
 * updates the stored tokens, and returns the new access token.
 *
 * Storage: Magento customer session keys
 *   oidc_access_token           — current access token (plain text, short-lived)
 *   oidc_access_token_expires   — Unix timestamp when it expires
 *   oidc_refresh_token          — Magento-encrypted refresh token
 *   oidc_provider_id            — provider row ID (set by CheckAttributeMappingAction)
 *
 * Design notes:
 *  - The service is stateless (no DB writes beyond the session).
 *  - Refresh failures are logged but do not throw; callers get null and must
 *    redirect to re-authentication.
 *  - A configurable threshold (default: 60 s) controls how early the refresh
 *    is triggered before actual expiry to account for clock skew.
 */
class TokenRefreshService
{
    /** Session key for the encrypted refresh token. */
    public const SESSION_REFRESH_TOKEN   = 'oidc_refresh_token';

    /** Session key for the current access token. */
    public const SESSION_ACCESS_TOKEN    = 'oidc_access_token';

    /** Session key for the access token expiry Unix timestamp. */
    public const SESSION_TOKEN_EXPIRES   = 'oidc_access_token_expires';

    /** Refresh this many seconds before the token actually expires. */
    private const REFRESH_THRESHOLD_SECS = 60;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var Curl */
    private readonly Curl $curl;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var CustomerSession */
    private readonly CustomerSession $customerSession;

    /**
     * Initialize token refresh service.
     *
     * @param OAuthUtility       $oauthUtility
     * @param Curl               $curl
     * @param EncryptorInterface $encryptor
     * @param CustomerSession    $customerSession
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        Curl $curl,
        EncryptorInterface $encryptor,
        CustomerSession $customerSession
    ) {
        $this->oauthUtility   = $oauthUtility;
        $this->curl           = $curl;
        $this->encryptor      = $encryptor;
        $this->customerSession = $customerSession;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Persist a refresh token in the customer session (encrypted).
     *
     * Call this immediately after a successful token exchange in
     * ReadAuthorizationResponse, when an `refresh_token` is present in
     * the token response.
     *
     * @param string $refreshToken Plain-text refresh token from the IdP
     * @param int    $expiresIn    Access token lifetime in seconds (from `expires_in`)
     * @param string $accessToken  Current access token
     */
    public function storeTokens(string $refreshToken, int $expiresIn, string $accessToken): void
    {
        if ($refreshToken !== '') {
            $this->customerSession->setData(
                self::SESSION_REFRESH_TOKEN,
                $this->encryptor->encrypt($refreshToken)
            );
        }
        if ($accessToken !== '') {
            $this->customerSession->setData(self::SESSION_ACCESS_TOKEN, $accessToken);
        }
        if ($expiresIn > 0) {
            $this->customerSession->setData(
                self::SESSION_TOKEN_EXPIRES,
                time() + $expiresIn
            );
        }
    }

    /**
     * Return a valid access token, refreshing if the current one is near expiry.
     *
     * Returns null when:
     *  - No refresh token is stored in the session (single-token flow).
     *  - The refresh request to the IdP failed.
     *  - The customer is not logged in.
     *
     * @return string|null Fresh access token, or null if unavailable
     */
    public function refreshIfNeeded(): ?string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        $expiresAt   = (int) $this->customerSession->getData(self::SESSION_TOKEN_EXPIRES);
        $accessToken = (string) ($this->customerSession->getData(self::SESSION_ACCESS_TOKEN) ?? '');

        // Token is still fresh enough
        if ($accessToken !== '' && $expiresAt > 0 && time() < ($expiresAt - self::REFRESH_THRESHOLD_SECS)) {
            return $accessToken;
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
        $encryptedRefresh = (string) ($this->customerSession->getData(self::SESSION_REFRESH_TOKEN) ?? '');
        if ($encryptedRefresh === '') {
            return null;
        }

        // Decrypt stored refresh token
        try {
            $refreshToken = $this->encryptor->decrypt($encryptedRefresh);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('TokenRefreshService: Failed to decrypt refresh token.');
            return null;
        }

        if ($refreshToken === '') {
            return null;
        }

        // Load provider details
        $providerId = (int) $this->customerSession->getData('oidc_provider_id');
        $provider   = $providerId > 0
            ? $this->oauthUtility->getClientDetailsById($providerId)
            : null;

        if ($provider === null) {
            // Fallback to first configured provider
            $collection = $this->oauthUtility->getOAuthClientApps();
            $provider   = count($collection) > 0 ? $collection->getFirstItem()->getData() : null;
        }

        if ($provider === null) {
            $this->oauthUtility->customlog('TokenRefreshService: No provider configured for refresh.');
            return null;
        }

        return $this->sendRefreshRequest($provider, $refreshToken);
    }

    /**
     * Clear all stored token data from the session (call on logout).
     */
    public function clearTokens(): void
    {
        $this->customerSession->unsetData(self::SESSION_REFRESH_TOKEN);
        $this->customerSession->unsetData(self::SESSION_ACCESS_TOKEN);
        $this->customerSession->unsetData(self::SESSION_TOKEN_EXPIRES);
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
            $this->oauthUtility->customlog('TokenRefreshService: No token endpoint configured.');
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
                'provider' => $provider['app_name'] ?? '',
                'ms'       => $elapsed,
            ]);
            return null;
        }

        // Persist updated tokens
        $newAccessToken  = (string) $decoded['access_token'];
        $newRefreshToken = (string) ($decoded['refresh_token'] ?? '');
        $expiresIn       = (int) ($decoded['expires_in'] ?? 3600);

        $this->storeTokens($newRefreshToken, $expiresIn, $newAccessToken);

        $this->oauthUtility->customlogContext('oidc.token_refreshed', [
            'provider'   => $provider['app_name'] ?? '',
            'expires_in' => $expiresIn,
            'ms'         => $elapsed,
        ]);

        return $newAccessToken;
    }
}
