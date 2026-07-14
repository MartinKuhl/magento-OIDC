<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Shared implementation for OIDC access-token refresh services (FEAT-03).
 *
 * Implements RFC 6749 §6 — Refreshing an Access Token — for both the customer
 * session (TokenRefreshService) and the admin auth session
 * (AdminTokenRefreshService). Children only provide the concrete session
 * accessors and their logging identity; all token storage, expiry checking,
 * provider resolution, and refresh-request logic lives here.
 *
 * Both session classes extend \Magento\Framework\Session\SessionManager but
 * the shared code goes through the abstract primitive accessors below so each
 * child operates on its own concrete session type.
 *
 * Session keys (identical for both areas):
 *   oidc_access_token           — Magento-encrypted access token
 *   oidc_access_token_expires   — Unix timestamp when it expires
 *   oidc_refresh_token          — Magento-encrypted refresh token
 *   oidc_provider_id            — provider row ID
 *
 * Design notes:
 *  - The service is stateless (no DB writes beyond the session).
 *  - Refresh failures are logged but do not throw; callers get null and must
 *    redirect to re-authentication.
 *  - A fixed threshold (REFRESH_THRESHOLD_SECS) controls how early the refresh
 *    is triggered before actual expiry to account for clock skew.
 */
abstract class AbstractTokenRefreshService
{
    /** Session key for the encrypted refresh token. */
    public const SESSION_REFRESH_TOKEN = 'oidc_refresh_token';

    /** Session key for the current access token. */
    public const SESSION_ACCESS_TOKEN  = 'oidc_access_token';

    /** Session key for the access token expiry Unix timestamp. */
    public const SESSION_TOKEN_EXPIRES = 'oidc_access_token_expires';

    /** Refresh this many seconds before the token actually expires.
     * @var int */
    private const REFRESH_THRESHOLD_SECS = 60;

    /** @var OAuthUtility */
    protected readonly OAuthUtility $oauthUtility;

    /** @var Curl */
    protected readonly Curl $curl;

    /** @var EncryptorInterface */
    protected readonly EncryptorInterface $encryptor;

    /**
     * Initialize shared token refresh dependencies.
     *
     * @param OAuthUtility       $oauthUtility
     * @param Curl               $curl
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        Curl $curl,
        EncryptorInterface $encryptor
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->curl         = $curl;
        $this->encryptor    = $encryptor;
    }

    // -------------------------------------------------------------------------
    // Abstract session / logging primitives — implemented by children
    // -------------------------------------------------------------------------
    /**
     * Read a value from the underlying session.
     *
     * @param  string $key Session data key
     */
    abstract protected function getSessionData(string $key): mixed;

    /**
     * Write a value to the underlying session.
     *
     * @param string $key   Session data key
     * @param mixed  $value Value to store
     */
    abstract protected function setSessionData(string $key, mixed $value): void;

    /**
     * Remove a value from the underlying session.
     *
     * @param string $key Session data key
     */
    abstract protected function unsetSessionData(string $key): void;

    /**
     * Whether a user is currently logged in on the underlying session.
     */
    abstract protected function isUserLoggedIn(): bool;

    /**
     * Log message prefix identifying the concrete service (e.g. "TokenRefreshService").
     */
    abstract protected function getLogPrefix(): string;

    /**
     * Extra structured-log context fields (e.g. ['context' => 'admin']).
     *
     * @return array<string, string>
     */
    abstract protected function getLogContext(): array;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Persist tokens in the session (encrypted).
     *
     * Call this immediately after a successful token exchange, when an
     * access_token and optionally a refresh_token are present in the
     * token response.
     *
     * @param string $refreshToken Plain-text refresh token from the IdP (may be empty)
     * @param int    $expiresIn    Access token lifetime in seconds (from `expires_in`)
     * @param string $accessToken  Current access token
     */
    public function storeTokens(string $refreshToken, int $expiresIn, string $accessToken): void
    {
        if ($refreshToken !== '') {
            $this->setSessionData(
                self::SESSION_REFRESH_TOKEN,
                $this->encryptor->encrypt($refreshToken)
            );
        }
        if ($accessToken !== '') {
            // Encrypt access token before storing — mirrors how refresh token is stored
            $this->setSessionData(
                self::SESSION_ACCESS_TOKEN,
                $this->encryptor->encrypt($accessToken)
            );
        }
        if ($expiresIn > 0) {
            $this->setSessionData(
                self::SESSION_TOKEN_EXPIRES,
                time() + $expiresIn
            );
        }
    }

    /**
     * Return a valid access token, refreshing if the current one is near expiry.
     *
     * Returns null when:
     *  - No user is logged in on the underlying session.
     *  - No refresh token is stored in the session (single-token flow).
     *  - The refresh request to the IdP failed.
     *
     * @return string|null Fresh access token, or null if unavailable
     */
    public function refreshIfNeeded(): ?string
    {
        if (!$this->isUserLoggedIn()) {
            return null;
        }

        $expiresAt       = (int) $this->getSessionData(self::SESSION_TOKEN_EXPIRES);
        $encryptedAccess = (string) ($this->getSessionData(self::SESSION_ACCESS_TOKEN) ?? '');

        // Token is still fresh enough — decrypt before returning
        if ($encryptedAccess !== '' && $expiresAt > 0 && time() < ($expiresAt - self::REFRESH_THRESHOLD_SECS)) {
            try {
                return $this->encryptor->decrypt($encryptedAccess);
            } catch (\Exception $e) {
                $this->oauthUtility->customlog($this->getLogPrefix() . ': Failed to decrypt access token.');
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
        $encryptedRefresh = (string) ($this->getSessionData(self::SESSION_REFRESH_TOKEN) ?? '');
        if ($encryptedRefresh === '') {
            return null;
        }

        // Decrypt stored refresh token
        try {
            $refreshToken = $this->encryptor->decrypt($encryptedRefresh);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog($this->getLogPrefix() . ': Failed to decrypt refresh token.');
            return null;
        }

        if ($refreshToken === '') {
            return null;
        }

        // Load provider details
        $providerId = (int) $this->getSessionData('oidc_provider_id');
        $provider   = $providerId > 0
            ? $this->oauthUtility->getClientDetailsById($providerId)
            : null;

        if ($provider === null) {
            // Fallback to first configured provider
            $collection = $this->oauthUtility->getOAuthClientApps();
            $provider   = count($collection) > 0 ? $collection->getFirstItem()->getData() : null;
        }

        if ($provider === null) {
            $this->oauthUtility->customlog($this->getLogPrefix() . ': No provider configured for refresh.');
            return null;
        }

        return $this->sendRefreshRequest($provider, $refreshToken);
    }

    /**
     * Clear all stored token data from the session (call on logout).
     */
    public function clearTokens(): void
    {
        $this->unsetSessionData(self::SESSION_REFRESH_TOKEN);
        $this->unsetSessionData(self::SESSION_ACCESS_TOKEN);
        $this->unsetSessionData(self::SESSION_TOKEN_EXPIRES);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Send the RFC 6749 §6 refresh token grant request.
     *
     * @param  mixed[] $provider      Provider data row
     * @param  string  $refreshToken  Plain-text refresh token
     * @return string|null          New access token or null on failure
     */
    private function sendRefreshRequest(array $provider, string $refreshToken): ?string
    {
        $tokenEndpoint = (string) ($provider['access_token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            $this->oauthUtility->customlog($this->getLogPrefix() . ': No token endpoint configured.');
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
            $this->oauthUtility->customlogContext('oidc.token_refresh_failed', $this->getLogContext() + [
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

        $this->oauthUtility->customlogContext('oidc.token_refreshed', $this->getLogContext() + [
            'provider'   => $provider['app_name'] ?? '',
            'expires_in' => $expiresIn,
            'ms'         => $elapsed,
        ]);

        return $newAccessToken;
    }
}
