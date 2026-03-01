<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration\Resolver;

use PHPUnit\Framework\TestCase;
use MiniOrange\OAuth\Model\Resolver\OidcLoginUrl;
use MiniOrange\OAuth\Model\Resolver\OidcProviders;

/**
 * Unit tests for the FEAT-08 GraphQL resolvers (TEST-06).
 *
 * The resolvers are tested via mock OAuthUtility objects so no Dex connection
 * or Magento framework is required.
 *
 * Each test exercises the public resolve() contract:
 *   - Correct return structure
 *   - Legacy fallback path (no multi-provider rows)
 *   - Input validation (invalid login_type, non-positive provider_id)
 */
class OidcProvidersResolverTest extends TestCase
{
    // ----------------------------------------------------------------- helpers

    /**
     * Build a stub Field object (only getId() is called by the framework).
     */
    private function makeField(): \Magento\Framework\GraphQl\Config\Element\Field
    {
        /** @var \Magento\Framework\GraphQl\Config\Element\Field&\PHPUnit\Framework\MockObject\MockObject $field */
        $field = $this->createMock(\Magento\Framework\GraphQl\Config\Element\Field::class);
        return $field;
    }

    /**
     * Build a stub ResolveInfo.
     */
    private function makeInfo(): \Magento\Framework\GraphQl\Schema\Type\ResolveInfo
    {
        return $this->createMock(\Magento\Framework\GraphQl\Schema\Type\ResolveInfo::class);
    }

    // --------------------------------------------------------- OidcProviders

    public function testOidcProvidersReturnsEmptyArrayWhenNoProvidersExist(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([]);

        $resolver = new OidcProviders($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['login_type' => 'customer']);

        $this->assertSame([], $result);
    }

    public function testOidcProvidersReturnsCorrectStructure(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([
            [
                'id'           => 1,
                'display_name' => 'Google',
                'button_label' => 'Login with Google',
                'button_color' => '#4285F4',
                'login_type'   => 'customer',
            ],
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->willReturn('https://example.com/sso?provider_id=1');

        $resolver = new OidcProviders($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, []);

        $this->assertCount(1, $result);
        $provider = $result[0];
        $this->assertSame(1, $provider['id']);
        $this->assertSame('Google', $provider['display_name']);
        $this->assertSame('Login with Google', $provider['button_label']);
        $this->assertSame('#4285F4', $provider['button_color']);
        $this->assertSame('https://example.com/sso?provider_id=1', $provider['login_url']);
    }

    public function testOidcProvidersThrowsOnInvalidLoginType(): void
    {
        $utility  = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $resolver = new OidcProviders($utility);

        $this->expectException(\Magento\Framework\GraphQl\Exception\GraphQlInputException::class);
        $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['login_type' => 'invalid']);
    }

    public function testOidcProvidersStripsInvalidHexColor(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([
            ['id' => 2, 'display_name' => 'IdP', 'button_label' => null, 'button_color' => 'not-a-hex'],
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->willReturn('https://example.com/sso?provider_id=2');

        $resolver = new OidcProviders($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, []);

        $this->assertNull($result[0]['button_color'], 'Invalid hex colour should be normalised to null.');
    }

    /**
     * When Providersettings/Index.php stores '' for an invalid colour, the resolver
     * must still return null (not empty string) in the GraphQL output.
     */
    public function testOidcProvidersNormalizesEmptyButtonColorToNull(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([
            ['id' => 3, 'display_name' => 'IdP', 'button_label' => null, 'button_color' => ''],
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->willReturn('https://example.com/sso?provider_id=3');

        $resolver = new OidcProviders($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, []);

        $this->assertNull(
            $result[0]['button_color'],
            'Empty string button_color (stored by controller for invalid colours) must resolve to null.'
        );
    }

    /**
     * When display_name is an empty string (trimmed by Providersettings controller),
     * the resolver must return null, not an empty string.
     */
    public function testOidcProvidersNormalizesEmptyDisplayNameToNull(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([
            ['id' => 4, 'display_name' => '', 'button_label' => '', 'button_color' => '#000000'],
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->willReturn('https://example.com/sso?provider_id=4');

        $resolver = new OidcProviders($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, []);

        $this->assertNull($result[0]['display_name'], 'Empty display_name must resolve to null, not empty string.');
        $this->assertNull($result[0]['button_label'], 'Empty button_label must resolve to null, not empty string.');
    }

    /**
     * login_type "both" is a valid filter value (declared in ALLOWED_LOGIN_TYPES)
     * and must not throw — providers with this type are returned when explicitly
     * requested. This became reachable once Provider Settings allows login_type=both.
     */
    public function testOidcProvidersAcceptsLoginTypeBoth(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([
            [
                'id' => 5, 'display_name' => 'Dual IdP', 'button_label' => null,
                'button_color' => null, 'login_type' => 'both',
            ],
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->willReturn('https://example.com/sso?provider_id=5');

        $resolver = new OidcProviders($utility);

        // Must not throw GraphQlInputException
        $result = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['login_type' => 'both']);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['id']);
    }

    // ---------------------------------------------------------- OidcLoginUrl

    public function testOidcLoginUrlReturnsUrlForExplicitProvider(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getClientDetailsById')->with(5)->willReturn([
            'id'           => 5,
            'button_label' => 'Sign in with Okta',
            'button_color' => '#007DC1',
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->with(5)->willReturn('https://example.com/sso?provider_id=5');

        $resolver = new OidcLoginUrl($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['provider_id' => 5]);

        $this->assertSame('https://example.com/sso?provider_id=5', $result['url']);
        $this->assertSame('Sign in with Okta', $result['label']);
        $this->assertSame(5, $result['provider_id']);
    }

    public function testOidcLoginUrlThrowsWhenProviderNotFound(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getClientDetailsById')->willReturn(null);

        $resolver = new OidcLoginUrl($utility);

        $this->expectException(\Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException::class);
        $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['provider_id' => 999]);
    }

    public function testOidcLoginUrlThrowsOnNonPositiveProviderId(): void
    {
        $utility  = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $resolver = new OidcLoginUrl($utility);

        $this->expectException(\Magento\Framework\GraphQl\Exception\GraphQlInputException::class);
        $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['provider_id' => 0]);
    }

    public function testOidcLoginUrlDefaultsToFirstActiveProvider(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([
            ['id' => 3, 'button_label' => 'Google SSO'],
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->with(3)->willReturn('https://example.com/sso?provider_id=3');

        $resolver = new OidcLoginUrl($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, []);

        $this->assertSame('https://example.com/sso?provider_id=3', $result['url']);
        $this->assertSame(3, $result['provider_id']);
    }

    /**
     * When button_label is '' (empty string stored by Providersettings controller),
     * the resolver must return null for the label field, not an empty string.
     */
    public function testOidcLoginUrlNormalizesEmptyButtonLabelToNull(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getClientDetailsById')->with(6)->willReturn([
            'id'           => 6,
            'button_label' => '',   // stored as '' by Providersettings controller
            'button_color' => '#000000',
        ]);
        $utility->method('getSPInitiatedUrlForProvider')->with(6)->willReturn('https://example.com/sso?provider_id=6');

        $resolver = new OidcLoginUrl($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, ['provider_id' => 6]);

        $this->assertNull($result['label'], 'Empty string button_label must resolve to null.');
    }

    public function testOidcLoginUrlFallsBackToLegacyWhenNoProviders(): void
    {
        $utility = $this->createMock(\MiniOrange\OAuth\Helper\OAuthUtility::class);
        $utility->method('getAllActiveProviders')->willReturn([]);
        $utility->method('getSPInitiatedUrl')->willReturn('https://example.com/sso');

        $resolver = new OidcLoginUrl($utility);
        $result   = $resolver->resolve($this->makeField(), null, $this->makeInfo(), null, []);

        $this->assertSame('https://example.com/sso', $result['url']);
        $this->assertNull($result['provider_id']);
        $this->assertNull($result['label']);
    }
}
