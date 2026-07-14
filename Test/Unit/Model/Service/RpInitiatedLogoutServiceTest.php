<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Service\RpInitiatedLogoutService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RpInitiatedLogoutService.
 *
 * Verifies:
 *  - Regression: an invalid per-provider post_logout_url override is
 *    rejected (FILTER_VALIDATE_URL) and the caller fallback is used instead
 *  - A valid post_logout_url override is honored verbatim
 *  - Authelia detection heuristic: /logout yes, /oauth2/logout and
 *    /oidc/logout no, non-logout endpoints no
 *  - buildLogoutUrl: standard OIDC shape (id_token_hint/state/
 *    post_logout_redirect_uri) vs Authelia ?rd= shape
 *  - revokeToken guards (no provider / no endpoint / no token) and
 *    non-fatal error handling
 *
 * @covers \M2Oidc\OAuth\Model\Service\RpInitiatedLogoutService
 */
class RpInitiatedLogoutServiceTest extends TestCase
{
    private const FALLBACK_URI = 'https://store.example.com/m2oidc/actions/postlogout';

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var CurlFactory&MockObject */
    private CurlFactory $curlFactory;

    /** @var RpInitiatedLogoutService */
    private RpInitiatedLogoutService $service;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->curlFactory  = $this->createMock(CurlFactory::class);

        $this->oauthUtility->method('customlog');

        $this->service = new RpInitiatedLogoutService($this->oauthUtility, $this->curlFactory);
    }

    // -------------------------------------------------------------------------
    // resolvePostLogoutRedirectUri — regression
    // -------------------------------------------------------------------------

    /**
     * Invalid post_logout_url override values that must be rejected.
     *
     * @return array<string, array{string}>
     */
    public static function invalidOverrideProvider(): array
    {
        return [
            'plain text'         => ['not a url'],
            'javascript scheme'  => ['javascript:alert(1)'],
            'relative path'      => ['/customer/account/login'],
            'missing scheme'     => ['store.example.com/loggedout'],
        ];
    }

    #[DataProvider('invalidOverrideProvider')]
    public function testInvalidPostLogoutUrlOverrideFallsBack(string $invalidOverride): void
    {
        $provider = ['post_logout_url' => $invalidOverride];

        $result = $this->service->resolvePostLogoutRedirectUri($provider, self::FALLBACK_URI);

        $this->assertSame(
            self::FALLBACK_URI,
            $result,
            'Invalid post_logout_url override must be rejected in favor of the fallback'
        );
    }

    public function testValidPostLogoutUrlOverrideIsUsedVerbatim(): void
    {
        $provider = ['post_logout_url' => 'https://custom.example.com/logged-out'];

        $result = $this->service->resolvePostLogoutRedirectUri($provider, self::FALLBACK_URI);

        $this->assertSame('https://custom.example.com/logged-out', $result);
    }

    public function testNullProviderUsesFallback(): void
    {
        $this->assertSame(
            self::FALLBACK_URI,
            $this->service->resolvePostLogoutRedirectUri(null, self::FALLBACK_URI)
        );
    }

    public function testProviderWithoutOverrideKeyUsesFallback(): void
    {
        $provider = ['endsession_endpoint' => 'https://idp.example.com/connect/endsession'];

        $this->assertSame(
            self::FALLBACK_URI,
            $this->service->resolvePostLogoutRedirectUri($provider, self::FALLBACK_URI)
        );
    }

    // -------------------------------------------------------------------------
    // isAutheliaForwardAuthLogout — detection heuristic
    // -------------------------------------------------------------------------

    /**
     * Endpoint → expected Authelia detection result.
     *
     * @return array<string, array{string, bool}>
     */
    public static function autheliaDetectionProvider(): array
    {
        return [
            'authelia /logout'              => ['https://auth.example.com/logout', true],
            'authelia /logout trailing /'   => ['https://auth.example.com/logout/', true],
            'oauth2 proxy logout'           => ['https://idp.example.com/oauth2/logout', false],
            'keycloak oidc logout'          => ['https://idp.example.com/realms/x/protocol/oidc/logout', false],
            'standard endsession'           => ['https://idp.example.com/connect/endsession', false],
            'no path'                       => ['https://idp.example.com', false],
        ];
    }

    #[DataProvider('autheliaDetectionProvider')]
    public function testAutheliaForwardAuthDetection(string $endpoint, bool $expected): void
    {
        $this->assertSame($expected, $this->service->isAutheliaForwardAuthLogout($endpoint));
    }

    // -------------------------------------------------------------------------
    // buildLogoutUrl — standard OIDC vs Authelia shape
    // -------------------------------------------------------------------------

    public function testBuildLogoutUrlStandardOidcShape(): void
    {
        $endpoint = 'https://idp.example.com/connect/endsession';
        $idToken  = 'eyJhbGciOiJSUzI1NiJ9.payload.sig';
        $state    = 'customer:abcdef0123456789';

        $url = $this->service->buildLogoutUrl($endpoint, $idToken, $state, self::FALLBACK_URI);

        $expected = $endpoint . '?' . http_build_query([
            'id_token_hint'            => $idToken,
            'state'                    => $state,
            'post_logout_redirect_uri' => self::FALLBACK_URI,
        ]);
        $this->assertSame($expected, $url);
    }

    public function testBuildLogoutUrlStandardOmitsEmptyIdTokenAndInvalidRedirect(): void
    {
        $url = $this->service->buildLogoutUrl(
            'https://idp.example.com/connect/endsession',
            '',
            'admin:abcdef0123456789',
            'not a url'
        );

        $this->assertStringNotContainsString('id_token_hint', $url);
        $this->assertStringNotContainsString('post_logout_redirect_uri', $url);
        $this->assertStringContainsString('state=admin%3Aabcdef0123456789', $url);
    }

    public function testBuildLogoutUrlStandardUsesAmpersandWhenEndpointHasQuery(): void
    {
        $url = $this->service->buildLogoutUrl(
            'https://idp.example.com/endsession?tenant=x',
            'tok',
            'admin:abc',
            ''
        );

        $this->assertStringStartsWith('https://idp.example.com/endsession?tenant=x&', $url);
    }

    public function testBuildLogoutUrlAutheliaShape(): void
    {
        $redirect = 'https://store.example.com/customer/account/login/';

        $url = $this->service->buildLogoutUrl(
            'https://auth.example.com/logout',
            'some.jwt.token',
            'customer:abcdef0123456789',
            $redirect
        );

        $this->assertSame('https://auth.example.com/logout?rd=' . urlencode($redirect), $url);
        $this->assertStringNotContainsString('id_token_hint', $url);
        $this->assertStringNotContainsString('post_logout_redirect_uri', $url);
        $this->assertStringNotContainsString('state=', $url);
    }

    public function testBuildLogoutUrlAutheliaWithoutRedirectReturnsBareEndpoint(): void
    {
        $url = $this->service->buildLogoutUrl('https://auth.example.com/logout', 'tok', 'admin:abc', '');

        $this->assertSame('https://auth.example.com/logout', $url);
    }

    // -------------------------------------------------------------------------
    // revokeToken — RFC 7009 fire-and-forget
    // -------------------------------------------------------------------------

    public function testRevokeTokenSendsPostToRevocationEndpoint(): void
    {
        $revocationEndpoint = 'https://idp.example.com/connect/revocation';
        $provider           = [
            'revocation_endpoint' => $revocationEndpoint,
            'clientID'            => 'my_client',
            'client_secret'       => 'my_secret',
        ];

        $curlAdapter = $this->createMock(CurlAdapter::class);
        $curlAdapter->method('setConfig')->willReturnSelf();
        $curlAdapter->expects($this->once())
            ->method('write')
            ->with(
                'POST',
                $revocationEndpoint,
                $this->anything(),
                $this->anything(),
                $this->stringContains('token=access_token_value')
            );
        $curlAdapter->method('read')->willReturn('');
        $curlAdapter->method('close');
        $this->curlFactory->method('create')->willReturn($curlAdapter);

        $this->service->revokeToken($provider, 'access_token_value', 'OidcLogoutPlugin');
    }

    public function testRevokeTokenSkippedWhenProviderIsNull(): void
    {
        $this->curlFactory->expects($this->never())->method('create');

        $this->service->revokeToken(null, 'access_token_value');
    }

    public function testRevokeTokenSkippedWhenRevocationEndpointIsEmpty(): void
    {
        $this->curlFactory->expects($this->never())->method('create');

        $this->service->revokeToken(['revocation_endpoint' => ''], 'access_token_value');
    }

    public function testRevokeTokenSkippedWhenAccessTokenIsEmpty(): void
    {
        $this->curlFactory->expects($this->never())->method('create');

        $this->service->revokeToken(
            ['revocation_endpoint' => 'https://idp.example.com/connect/revocation'],
            ''
        );
    }

    public function testRevokeTokenFailureIsNonFatal(): void
    {
        // Reaching the end of the test without an exception is the assertion.
        $this->expectNotToPerformAssertions();

        $curlAdapter = $this->createMock(CurlAdapter::class);
        $curlAdapter->method('setConfig')->willReturnSelf();
        $curlAdapter->method('write')->willThrowException(new \RuntimeException('network down'));
        $this->curlFactory->method('create')->willReturn($curlAdapter);

        $this->service->revokeToken(
            ['revocation_endpoint' => 'https://idp.example.com/connect/revocation'],
            'access_token_value'
        );
    }
}
