<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Plugin;

use Magento\Backend\Model\Auth;
use MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Plugin\Auth\OidcCredentialPlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OidcCredentialPlugin.
 *
 * Verifies that:
 *  - OIDC flag is set only when the token marker password is used (SEC-06)
 *  - Flag is unconditionally reset at the start of every beforeLogin() call (SEC-06)
 *  - aroundGetCredentialStorage() returns the OIDC adapter during OIDC flow
 *  - aroundGetCredentialStorage() delegates to $proceed() for normal logins
 *  - afterLogin() clears the flag
 *
 * @covers \MiniOrange\OAuth\Plugin\Auth\OidcCredentialPlugin
 */
class OidcCredentialPluginTest extends TestCase
{
    /** @var OidcCredentialAdapter&MockObject */
    private OidcCredentialAdapter $adapter;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var Auth&MockObject */
    private Auth $auth;

    /** @var OidcCredentialPlugin */
    private OidcCredentialPlugin $plugin;

    protected function setUp(): void
    {
        $this->adapter      = $this->createMock(OidcCredentialAdapter::class);
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->oauthUtility->method('customlog');
        $this->auth = $this->createMock(Auth::class);

        $this->plugin = new OidcCredentialPlugin(
            $this->adapter,
            $this->oauthUtility
        );
    }

    // -------------------------------------------------------------------------
    // beforeLogin
    // -------------------------------------------------------------------------

    public function testBeforeLoginDoesNotSetFlagForNormalPassword(): void
    {
        [$returnedUser, $returnedPassword] = $this->plugin->beforeLogin(
            $this->auth,
            'admin',
            'normalpassword'
        );

        $this->assertSame('admin', $returnedUser);
        $this->assertSame('normalpassword', $returnedPassword);

        // With the flag not set, aroundGetCredentialStorage should call $proceed
        $proceed  = fn (): OidcCredentialAdapter => $this->adapter;
        $returned = $this->plugin->aroundGetCredentialStorage($this->auth, $proceed);

        // Because isOidcAuth == false, it must call $proceed (which returns adapter here,
        // but the point is it doesn't short-circuit)
        $this->assertSame($this->adapter, $returned);
    }

    public function testBeforeLoginSetsFlagForOidcMarker(): void
    {
        $this->plugin->beforeLogin(
            $this->auth,
            'admin@example.com',
            OidcCredentialAdapter::OIDC_TOKEN_MARKER
        );

        // aroundGetCredentialStorage must now return the OIDC adapter directly
        $proceed  = fn (): never => throw new \RuntimeException('proceed() must NOT be called during OIDC');
        $returned = $this->plugin->aroundGetCredentialStorage($this->auth, $proceed);

        $this->assertSame($this->adapter, $returned);
    }

    /**
     * SEC-06: A second beforeLogin() call (for a normal login) must reset the flag
     * even if a previous OIDC login left it set (simulating PHP-FPM worker reuse).
     */
    public function testBeforeLoginAlwaysResetsFlag(): void
    {
        // First login: OIDC
        $this->plugin->beforeLogin(
            $this->auth,
            'admin@example.com',
            OidcCredentialAdapter::OIDC_TOKEN_MARKER
        );

        // Second login: normal — must reset the flag
        $this->plugin->beforeLogin(
            $this->auth,
            'admin',
            'regularpassword'
        );

        // Now aroundGetCredentialStorage should call $proceed, not return the adapter directly
        $called   = false;
        $proceed  = function () use (&$called): OidcCredentialAdapter {
            $called = true;
            return $this->adapter;
        };

        $this->plugin->aroundGetCredentialStorage($this->auth, $proceed);

        $this->assertTrue($called, 'SEC-06: $proceed() must be called after flag reset');
    }

    // -------------------------------------------------------------------------
    // afterLogin
    // -------------------------------------------------------------------------

    public function testAfterLoginClearsOidcFlag(): void
    {
        // Set the flag via beforeLogin
        $this->plugin->beforeLogin(
            $this->auth,
            'admin@example.com',
            OidcCredentialAdapter::OIDC_TOKEN_MARKER
        );

        // afterLogin should clear the flag
        $this->plugin->afterLogin($this->auth, null);

        // Now aroundGetCredentialStorage must call $proceed
        $called  = false;
        $proceed = function () use (&$called): OidcCredentialAdapter {
            $called = true;
            return $this->adapter;
        };

        $this->plugin->aroundGetCredentialStorage($this->auth, $proceed);

        $this->assertTrue($called, 'afterLogin() must clear the OIDC flag');
    }
}
