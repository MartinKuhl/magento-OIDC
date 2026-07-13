<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper\OAuth;

use M2Oidc\OAuth\Helper\OAuth\AuthorizationRequest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuthorizationRequest query-string generation.
 *
 * Verifies:
 *  - An authorize endpoint without a query string gets a '?' separator
 *  - An authorize endpoint that already carries a query string (e.g. Azure AD
 *    B2C policy URLs like ...?p=policy) gets a '&' separator instead of the
 *    parameters being concatenated onto the existing query value
 *
 * @covers \M2Oidc\OAuth\Helper\OAuth\AuthorizationRequest
 */
class AuthorizationRequestTest extends TestCase
{
    private const CLIENT_ID    = 'my-client';
    private const SCOPE        = 'openid profile email';
    private const RESPONSE     = 'code';
    private const REDIRECT_URL = 'https://store.example.com/m2oidc/actions/readauthresponse';
    private const RELAY_STATE  = 'state-token-abc';

    /**
     * Build an AuthorizationRequest for the given authorize endpoint URL.
     *
     * @param string $authorizeUrl Authorization endpoint URL
     */
    private function buildRequest(string $authorizeUrl): AuthorizationRequest
    {
        return new AuthorizationRequest(
            self::CLIENT_ID,
            self::SCOPE,
            $authorizeUrl,
            self::RESPONSE,
            self::REDIRECT_URL,
            self::RELAY_STATE,
            []
        );
    }

    public function testEndpointWithoutQueryStringGetsQuestionMarkSeparator(): void
    {
        $authorizeUrl = 'https://idp.example.com/authorize';
        $requestStr   = $this->buildRequest($authorizeUrl)->build();

        $this->assertStringStartsWith(
            '?client_id=' . self::CLIENT_ID,
            $requestStr,
            'Endpoint without a query string must start the parameters with "?"'
        );

        $fullUrl = $authorizeUrl . $requestStr;
        $this->assertStringContainsString(
            '/authorize?client_id=',
            $fullUrl,
            'Full URL must contain "...?client_id="'
        );
    }

    public function testEndpointWithExistingQueryStringGetsAmpersandSeparator(): void
    {
        $authorizeUrl = 'https://idp.example.com/authorize?p=policy';
        $requestStr   = $this->buildRequest($authorizeUrl)->build();

        $this->assertStringStartsWith(
            '&client_id=' . self::CLIENT_ID,
            $requestStr,
            'Endpoint with an existing query string must join the parameters with "&"'
        );

        $fullUrl = $authorizeUrl . $requestStr;
        $this->assertStringContainsString(
            '?p=policy&client_id=',
            $fullUrl,
            'Full URL must keep the existing query string and append with "&"'
        );
        $this->assertStringNotContainsString(
            'policyclient_id',
            $fullUrl,
            'client_id must not be concatenated directly onto the existing query value'
        );
    }

    public function testGeneratedRequestContainsAllCoreParameters(): void
    {
        $requestStr = $this->buildRequest('https://idp.example.com/authorize')->build();

        $this->assertStringContainsString('&scope=' . urlencode(self::SCOPE), $requestStr);
        $this->assertStringContainsString('&state=' . urlencode(self::RELAY_STATE), $requestStr);
        $this->assertStringContainsString('&redirect_uri=' . urlencode(self::REDIRECT_URL), $requestStr);
        $this->assertStringContainsString('&response_type=' . self::RESPONSE, $requestStr);
    }
}
