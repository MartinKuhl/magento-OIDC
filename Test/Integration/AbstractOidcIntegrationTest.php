<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for MiniOrange OIDC integration tests (TEST-06).
 *
 * Integration tests require a running Dex OIDC provider accessible at the
 * URL defined by the OIDC_TEST_ISSUER environment variable.
 *
 * Tests that extend this class are automatically skipped when Dex is not
 * reachable — allowing the same phpunit.xml to be used both locally (unit
 * tests only) and in CI (unit + integration with docker-compose.test.yml).
 *
 * Dex connection details are read from environment variables:
 *   OIDC_TEST_ISSUER        — e.g. http://dex.local:5556/dex
 *   OIDC_TEST_CLIENT_ID     — OAuth2 client ID registered in Dex
 *   OIDC_TEST_CLIENT_SECRET — OAuth2 client secret
 *   OIDC_TEST_REDIRECT_URI  — Callback URL (must match Dex config)
 */
abstract class AbstractOidcIntegrationTest extends TestCase
{
    /** @var string Dex issuer URL (base for all OIDC endpoints) */
    protected string $issuer;

    /** @var string OAuth2 client ID */
    protected string $clientId;

    /** @var string OAuth2 client secret */
    protected string $clientSecret;

    /** @var string Redirect / callback URI */
    protected string $redirectUri;

    /** @var array<string,string> Parsed OIDC discovery document */
    protected array $discovery = [];

    /**
     * Bootstrap: read env vars, skip when Dex is unreachable.
     */
    protected function setUp(): void
    {
        $this->issuer = (string) (
            $_ENV['OIDC_TEST_ISSUER'] ?? getenv('OIDC_TEST_ISSUER') ?: 'http://dex.local:5556/dex'
        );
        $this->clientId = (string) (
            $_ENV['OIDC_TEST_CLIENT_ID'] ?? getenv('OIDC_TEST_CLIENT_ID') ?: 'miniorange-test-client'
        );
        $this->clientSecret = (string) (
            $_ENV['OIDC_TEST_CLIENT_SECRET'] ?? getenv('OIDC_TEST_CLIENT_SECRET') ?: 'miniorange-test-secret'
        );
        $this->redirectUri  = (string) ($_ENV['OIDC_TEST_REDIRECT_URI']  ?? getenv('OIDC_TEST_REDIRECT_URI')  ?: '');

        // Attempt to fetch the discovery document — skip the test when unreachable
        $discoveryUrl = rtrim($this->issuer, '/') . '/.well-known/openid-configuration';
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        $json = @file_get_contents($discoveryUrl);
        if ($json === false) {
            $this->markTestSkipped(
                "Dex OIDC provider not reachable at {$discoveryUrl}. " .
                "Start it with: docker compose -f docker-compose.test.yml up -d dex"
            );
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->markTestSkipped("Could not parse OIDC discovery document from {$discoveryUrl}.");
        }

        $this->discovery = $decoded;
    }

    /**
     * Perform the Resource Owner Password Credentials (ROPC) token request.
     *
     * Dex supports ROPC only via the password connector in test mode.
     * Returns the decoded token response array, or fails the test on error.
     *
     * @param  string $username
     * @param  string $password
     * @param  string $scope    Space-separated scope string
     * @return array<string,mixed> Token response payload
     */
    protected function fetchTokenViaPassword(
        string $username,
        string $password,
        string $scope = 'openid email profile offline_access'
    ): array {
        $tokenEndpoint = (string) ($this->discovery['token_endpoint'] ?? '');
        $this->assertNotEmpty($tokenEndpoint, 'Discovery document missing token_endpoint.');

        $postFields = http_build_query([
            'grant_type'    => 'password',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username'      => $username,
            'password'      => $password,
            'scope'         => $scope,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
            ],
        ]);

        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        $body = @file_get_contents($tokenEndpoint, false, $ctx);
        $this->assertNotFalse($body, "Token request to {$tokenEndpoint} failed.");

        $response = json_decode($body, true);
        $this->assertIsArray($response, "Token response is not valid JSON: {$body}");
        $this->assertArrayNotHasKey(
            'error',
            $response,
            "Token request returned error: " . ($response['error_description'] ?? $response['error'] ?? '')
        );

        return $response;
    }

    /**
     * Perform a token refresh request and return the new token response.
     *
     * @param  string $refreshToken
     * @return array<string,mixed>
     */
    protected function fetchRefreshedToken(string $refreshToken): array
    {
        $tokenEndpoint = (string) ($this->discovery['token_endpoint'] ?? '');
        $this->assertNotEmpty($tokenEndpoint, 'Discovery document missing token_endpoint.');

        $postFields = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
            ],
        ]);

        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        $body = @file_get_contents($tokenEndpoint, false, $ctx);
        $this->assertNotFalse($body, "Refresh request to {$tokenEndpoint} failed.");

        $response = json_decode($body, true);
        $this->assertIsArray($response);
        $this->assertArrayNotHasKey(
            'error',
            $response,
            "Refresh request returned error: " . ($response['error_description'] ?? $response['error'] ?? '')
        );

        return $response;
    }

    /**
     * Decode a JWT payload without signature verification (for test assertions).
     *
     * @param  string $jwt
     * @return array<string,mixed>
     */
    protected function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have 3 parts separated by dots.');
        $payload = base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT));
        $this->assertNotFalse($payload);
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}
