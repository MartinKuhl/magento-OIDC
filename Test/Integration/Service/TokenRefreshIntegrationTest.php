<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration\Service;

use MiniOrange\OAuth\Test\Integration\AbstractOidcIntegrationTest;

/**
 * Integration tests for FEAT-03: Token Refresh Service (TEST-06).
 *
 * These tests exercise the token refresh flow end-to-end against the live
 * Dex OIDC provider, without invoking Magento framework code.
 *
 * The token_endpoint is called directly (simulating what TokenRefreshService
 * does internally) to verify that Dex's refresh_token grant works as expected.
 */
class TokenRefreshIntegrationTest extends AbstractOidcIntegrationTest
{
    /**
     * A password-grant request should return both an access_token and a
     * refresh_token when the offline_access scope is requested.
     */
    public function testPasswordGrantReturnsRefreshToken(): void
    {
        $response = $this->fetchTokenViaPassword('testuser@example.com', 'testpass');

        $this->assertArrayHasKey('access_token', $response, 'Token response must include access_token.');
        $this->assertArrayHasKey(
            'refresh_token',
            $response,
            'Token response must include refresh_token when offline_access is requested.'
        );
        $this->assertArrayHasKey('id_token', $response, 'Token response must include id_token.');
        $this->assertNotEmpty($response['access_token']);
        $this->assertNotEmpty($response['refresh_token']);
    }

    /**
     * Exchanging the refresh_token must produce a new access_token.
     */
    public function testRefreshTokenGrantReturnsNewAccessToken(): void
    {
        $initial = $this->fetchTokenViaPassword('testuser@example.com', 'testpass');
        $this->assertArrayHasKey('refresh_token', $initial);

        $refreshed = $this->fetchRefreshedToken((string) $initial['refresh_token']);

        $this->assertArrayHasKey('access_token', $refreshed, 'Refresh response must include a new access_token.');
        $this->assertNotEmpty($refreshed['access_token']);
        // The new access token may differ from the original (Dex rotates tokens)
        // We only assert it is a non-empty JWT string.
        $this->assertStringContainsString(
            '.',
            $refreshed['access_token'],
            'access_token must be a JWT (contains dots).'
        );
    }

    /**
     * The refreshed ID token must contain the original subject claim.
     */
    public function testRefreshedIdTokenPreservesSubjectClaim(): void
    {
        $initial   = $this->fetchTokenViaPassword('testuser@example.com', 'testpass');
        $origSub   = $this->decodeJwtPayload((string) $initial['id_token'])['sub'] ?? null;
        $this->assertNotEmpty($origSub, 'Original id_token must have a sub claim.');

        $refreshed  = $this->fetchRefreshedToken((string) $initial['refresh_token']);
        if (!isset($refreshed['id_token'])) {
            $this->markTestSkipped(
                'Refresh response did not include id_token — Dex config may not return it on refresh.'
            );
        }

        $newSub = $this->decodeJwtPayload((string) $refreshed['id_token'])['sub'] ?? null;
        $this->assertSame($origSub, $newSub, 'Subject claim must not change after token refresh.');
    }

    /**
     * An expired / invalid refresh token must be rejected by Dex.
     */
    public function testInvalidRefreshTokenIsRejected(): void
    {
        $tokenEndpoint = (string) ($this->discovery['token_endpoint'] ?? '');

        $postFields = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => 'this-is-an-invalid-refresh-token',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'ignore_errors' => true,
            ],
        ]);

        $body     = file_get_contents($tokenEndpoint, false, $ctx);
        $response = json_decode((string) $body, true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('error', $response, 'Invalid refresh token must produce an error response.');
    }

    /**
     * The expires_in field in the token response must be present and positive.
     */
    public function testTokenResponseIncludesExpiresIn(): void
    {
        $response = $this->fetchTokenViaPassword('testuser@example.com', 'testpass');

        $this->assertArrayHasKey('expires_in', $response, 'Token response must include expires_in.');
        $this->assertGreaterThan(0, (int) $response['expires_in'], 'expires_in must be a positive integer.');
    }
}
