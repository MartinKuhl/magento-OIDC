<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration;

/**
 * Integration test: Dex OIDC discovery document validation (TEST-06).
 *
 * Verifies that the Dex provider is healthy and exposes all endpoints
 * that the MiniOrange OIDC module depends on.  This is the first test
 * to run in CI — if it fails, all other integration tests are irrelevant.
 */
class DexDiscoveryTest extends AbstractOidcIntegrationTest
{
    /**
     * Discovery document must expose all OIDC endpoints used by the module.
     */
    public function testDiscoveryDocumentContainsRequiredEndpoints(): void
    {
        $required = [
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
        ];

        foreach ($required as $key) {
            $this->assertArrayHasKey(
                $key,
                $this->discovery,
                "Discovery document is missing required key: {$key}"
            );
            $this->assertNotEmpty($this->discovery[$key], "Discovery key '{$key}' must not be empty.");
        }
    }

    /**
     * The issuer in the discovery document must match the configured issuer URL.
     */
    public function testDiscoveryIssuerMatchesConfiguredIssuer(): void
    {
        $this->assertSame(
            rtrim($this->issuer, '/'),
            rtrim((string) $this->discovery['issuer'], '/'),
            'Issuer in discovery document must match OIDC_TEST_ISSUER.'
        );
    }

    /**
     * The module requires RS256 to be listed in supported signing algorithms.
     */
    public function testDiscoveryAdvertisesRS256Signing(): void
    {
        $algos = $this->discovery['id_token_signing_alg_values_supported'] ?? [];
        $this->assertContains(
            'RS256',
            $algos,
            'Dex must advertise RS256 as a supported ID token signing algorithm.'
        );
    }

    /**
     * JWKS endpoint must return a non-empty key set.
     */
    public function testJwksEndpointReturnsKeys(): void
    {
        $jwksUri = (string) ($this->discovery['jwks_uri'] ?? '');
        $this->assertNotEmpty($jwksUri, 'Discovery document is missing jwks_uri.');

        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
        $body = @file_get_contents($jwksUri);
        $this->assertNotFalse($body, "Could not fetch JWKS from {$jwksUri}.");

        $jwks = json_decode($body, true);
        $this->assertIsArray($jwks, 'JWKS response is not valid JSON.');
        $this->assertArrayHasKey('keys', $jwks, 'JWKS response missing "keys" array.');
        $this->assertNotEmpty($jwks['keys'], 'JWKS key set must not be empty.');
    }
}
