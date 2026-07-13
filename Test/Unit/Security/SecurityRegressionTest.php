<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security regression tests (TEST-07).
 *
 * These tests parse source files with simple grep/string checks to ensure
 * previously fixed vulnerabilities have not been re-introduced.
 *
 * Every test documents the vulnerability it guards against.
 */
class SecurityRegressionTest extends TestCase
{
    /**
     * Absolute path to the module root directory
     *
     * @var string
     */
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        // Test/Unit/Security/ -> three levels up -> module root
        self::$root = dirname(__DIR__, 3);
    }

    // -------------------------------------------------------------------------
    // SSL verification must be enabled
    // -------------------------------------------------------------------------

    /**
     * Curl.php must NOT set CURLOPT_SSL_VERIFYPEER to false.
     *
     * Fixes: man-in-the-middle via disabled certificate validation
     */
    public function testCurlHelperDoesNotDisableSslVerification(): void
    {
        $file    = self::$root . '/Helper/Curl.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER\' => false',
            $content,
            'Curl.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
        $this->assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER", false',
            $content,
            'Curl.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
    }

    /**
     * JwtVerifier.php must set CURLOPT_SSL_VERIFYPEER to true.
     *
     * Fixes: man-in-the-middle via disabled certificate validation
     */
    public function testJwtVerifierEnablesSslVerification(): void
    {
        $file    = self::$root . '/Helper/JwtVerifier.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'CURLOPT_SSL_VERIFYPEER',
            $content,
            'JwtVerifier.php must configure CURLOPT_SSL_VERIFYPEER'
        );
        // The value next to it must NOT be false
        $this->assertStringNotContainsString(
            "'CURLOPT_SSL_VERIFYPEER' => false",
            $content,
            'JwtVerifier.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
    }

    // -------------------------------------------------------------------------
    // Alpine.js XSS via x-html
    // -------------------------------------------------------------------------

    /**
     * The Hyva authentication popup must not use x-html for the message binding.
     *
     * Fixes: stored XSS via x-html Alpine.js directive
     */
    public function testAuthenticationPopupDoesNotUseXHtml(): void
    {
        $file = self::$root
            . '/view/frontend/templates/account/authentication-popup.phtml';

        if (!file_exists($file)) {
            $this->markTestSkipped('Hyva authentication-popup.phtml not found.');
        }

        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'x-html="message"',
            $content,
            'authentication-popup.phtml must not use x-html for the message binding'
        );
    }

    // -------------------------------------------------------------------------
    // Deprecated FILTER_SANITIZE_URL removed
    // -------------------------------------------------------------------------

    /**
     * OAuthsettings/Index.php must not use the deprecated FILTER_SANITIZE_URL.
     *
     * Fixes: SSRF via insufficient URL sanitisation
     */
    public function testOauthSettingsDoesNotUseSanitizeUrl(): void
    {
        $file    = self::$root . '/Controller/Adminhtml/OAuthsettings/Index.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'FILTER_SANITIZE_URL',
            $content,
            'OAuthsettings/Index.php must not use deprecated FILTER_SANITIZE_URL'
        );
    }

    /**
     * Providersettings/Index.php must not use the deprecated FILTER_SANITIZE_URL.
     *
     * Fixes: parity with OAuthsettings/Index.php check
     */
    public function testProviderSettingsControllerDoesNotUseSanitizeUrl(): void
    {
        $file    = self::$root . '/Controller/Adminhtml/Providersettings/Index.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'FILTER_SANITIZE_URL',
            $content,
            'Providersettings/Index.php must not use deprecated FILTER_SANITIZE_URL'
        );
    }

    // -------------------------------------------------------------------------
    // Hardcoded customer domain removed from CSP whitelist
    // -------------------------------------------------------------------------

    /**
     * csp_whitelist.xml files must not contain any hardcoded provider hostnames.
     *
     * Fixes: customer domain shipped in module, privacy + security
     */
    public function testCspWhitelistHasNoPolicyEntries(): void
    {
        $frontendCsp  = self::$root . '/etc/csp_whitelist.xml';
        $adminhtmlCsp = self::$root . '/etc/adminhtml/csp_whitelist.xml';

        foreach ([$frontendCsp, $adminhtmlCsp] as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $content = $this->readFile($file);
            $this->assertStringNotContainsString(
                '<value>',
                $content,
                "$file must not contain hardcoded <value> entries"
            );
        }
    }

    // -------------------------------------------------------------------------
    // OidcCredentialPlugin must reset flags unconditionally
    // -------------------------------------------------------------------------

    /**
     * OidcCredentialPlugin::beforeLogin() must reset both flags before checking the password.
     *
     * Fixes: flag state leaks between PHP-FPM worker requests
     */
    public function testOidcCredentialPluginResetsIsOidcAuthFlag(): void
    {
        $file    = self::$root . '/Plugin/Auth/OidcCredentialPlugin.php';
        $content = $this->readFile($file);

        // The unconditional reset must appear BEFORE the OIDC token detection check
        $resetPos  = strpos($content, '$this->isOidcAuth    = false;');
        $markerPos = strpos($content, 'isOidcAuthToken');

        $this->assertNotFalse($resetPos, 'isOidcAuth reset not found in beforeLogin()');
        $this->assertNotFalse($markerPos, 'isOidcAuthToken check not found');
        $this->assertLessThan(
            $markerPos,
            $resetPos,
            'isOidcAuth must be reset BEFORE the isOidcAuthToken check'
        );
    }

    // -------------------------------------------------------------------------
    // Error messages must be consistently base64-encoded on send and
    // base64-decoded on receive so human-readable text is shown.
    // -------------------------------------------------------------------------

    /**
     * All admin OIDC error senders must use base64_encode() and the admin error
     * block must use base64_decode() so the message round-trips correctly.
     *
     * Previously the senders used urlencode()/plaintext while the block did not
     * decode, producing raw base64 garbage in the UI. The fix standardises every
     * error path: encode with base64_encode() at source, decode with
     * base64_decode() in OidcErrorMessage::getOidcErrorMessage().
     */
    public function testAdminOidcCallbackBase64EncodesErrors(): void
    {
        $file    = self::$root . '/Controller/Adminhtml/Actions/Oidccallback.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'base64_encode',
            $content,
            'Oidccallback.php must base64_encode OIDC error messages for consistent encoding'
        );
    }

    public function testAdminErrorBlockBase64DecodesErrors(): void
    {
        $file    = self::$root . '/Block/Adminhtml/OidcErrorMessage.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'base64_decode',
            $content,
            'OidcErrorMessage.php must base64_decode the oidc_error URL parameter'
        );
    }

    public function testCheckAttributeMappingActionBase64EncodesErrors(): void
    {
        $file    = self::$root . '/Controller/Actions/CheckAttributeMappingAction.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'base64_encode($errorMessage)',
            $content,
            'CheckAttributeMappingAction must base64_encode error messages for consistent encoding'
        );
    }

    // -------------------------------------------------------------------------
    // Relay state validated by host comparison, not str_contains
    // -------------------------------------------------------------------------

    /**
     * ProcessUserAction must use parse_url host comparison for relay state validation.
     *
     * str_contains(relayState, storeUrl) can be bypassed:
     *   evil.com/?x=https://legit.store.com
     */
    public function testProcessUserActionValidatesRelayStateByHost(): void
    {
        $file    = self::$root . '/Controller/Actions/ProcessUserAction.php';
        $content = $this->readFile($file);

        // Must use parse_url for relay state host extraction
        $this->assertStringContainsString(
            'parse_url',
            $content,
            'ProcessUserAction must use parse_url for relay state validation'
        );

        // Must NOT use str_contains with the store URL as the relay state guard
        $this->assertStringNotContainsString(
            "str_contains((string) \$this->attrs['relayState'], \$store_url)",
            $content,
            'ProcessUserAction must not use str_contains for relay state validation'
        );
    }

    // -------------------------------------------------------------------------
    // extractNameFromEmail() exists in OAuthUtility
    // -------------------------------------------------------------------------

    /**
     * OAuthUtility must expose extractNameFromEmail() as the shared name-extraction helper.
     *
     * Fixes duplicate name-fallback logic that previously existed in multiple classes.
     */
    public function testOAuthUtilityExposesExtractNameFromEmail(): void
    {
        $file    = self::$root . '/Helper/OAuthUtility.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'function extractNameFromEmail',
            $content,
            'OAuthUtility must define extractNameFromEmail()'
        );
    }

    // -------------------------------------------------------------------------
    // ACL title corrected
    // -------------------------------------------------------------------------

    /**
     * acl.xml must use "M2Oidc OIDC", not the old "Authelia OIDC" title.
     *
     * Fixes a branding error in the ACL title.
     */
    public function testAclXmlHasCorrectModuleTitle(): void
    {
        $file    = self::$root . '/etc/acl.xml';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'Authelia OIDC',
            $content,
            'acl.xml must not contain old "Authelia OIDC" title'
        );
        $this->assertStringContainsString(
            'M2Oidc OIDC',
            $content,
            'acl.xml must contain "M2Oidc OIDC" title'
        );
    }

    // -------------------------------------------------------------------------
    // Provider Settings ACL resource ID consistency
    // -------------------------------------------------------------------------

    /**
     * Providersettings/Index.php must reference the same ACL resource ID
     * that is declared in acl.xml, preventing silent access-control drift.
     */
    public function testProviderSettingsAclResourceMatchesAclXml(): void
    {
        $controller = self::$root . '/Controller/Adminhtml/Providersettings/Index.php';
        $aclXml     = self::$root . '/etc/acl.xml';

        $controllerContent = $this->readFile($controller);
        $aclContent        = $this->readFile($aclXml);

        $this->assertStringContainsString(
            'provider_settings',
            $controllerContent,
            'Providersettings/Index.php must reference the provider_settings ACL resource'
        );
        $this->assertStringContainsString(
            'M2Oidc_OAuth::provider_settings',
            $aclContent,
            'acl.xml must declare the M2Oidc_OAuth::provider_settings resource'
        );
    }

    // -------------------------------------------------------------------------
    // IDP-INIT — IdP-Initiated Login security gates
    // -------------------------------------------------------------------------

    /**
     * IdpInitiatedLogin must call validateRedirectUrl to prevent open redirects.
     */
    public function testIdpInitiatedLoginValidatesRelayStateUrl(): void
    {
        $file    = self::$root . '/Controller/Actions/IdpInitiatedLogin.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'validateRedirectUrl',
            $content,
            'IDP-INIT: IdpInitiatedLogin.php must call validateRedirectUrl() to prevent open redirect'
        );
    }

    /**
     * IdpInitiatedLogin must apply rate limiting.
     */
    public function testIdpInitiatedLoginAppliesRateLimiting(): void
    {
        $file    = self::$root . '/Controller/Actions/IdpInitiatedLogin.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'isAllowed',
            $content,
            'IDP-INIT: IdpInitiatedLogin.php must call OidcRateLimiter::isAllowed()'
        );
    }

    /**
     * IdpInitiatedLogin must generate a CSRF state token.
     */
    public function testIdpInitiatedLoginGeneratesCsrfStateToken(): void
    {
        $file    = self::$root . '/Controller/Actions/IdpInitiatedLogin.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'createStateToken',
            $content,
            'IDP-INIT: IdpInitiatedLogin.php must call createStateToken() for CSRF protection'
        );
    }

    /**
     * IdpInitiatedLogin must check the idp_initiated_enabled gate.
     */
    public function testIdpInitiatedLoginChecksEnabledGate(): void
    {
        $file    = self::$root . '/Controller/Actions/IdpInitiatedLogin.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'idp_initiated_enabled',
            $content,
            'IDP-INIT: IdpInitiatedLogin.php must check idp_initiated_enabled before proceeding'
        );
    }

    /**
     * IdpInitiatedLogin must explicitly check is_active (getClientDetailsById does not filter on it).
     */
    public function testIdpInitiatedLoginChecksIsActive(): void
    {
        $file    = self::$root . '/Controller/Actions/IdpInitiatedLogin.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            "is_active",
            $content,
            'IDP-INIT: IdpInitiatedLogin.php must explicitly check is_active ' .
            '(getClientDetailsById does not filter on this)'
        );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function readFile(string $path): string
    {
        $this->assertFileExists($path, "Test file not found: $path");
        return (string) file_get_contents($path);
    }
}
