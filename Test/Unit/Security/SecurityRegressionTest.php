<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security regression tests (TEST-07).
 *
 * These tests parse source files with simple grep/string checks to ensure
 * previously fixed vulnerabilities have not been re-introduced.
 *
 * Every test documents the vulnerability it guards against and the sprint
 * ticket that fixed it (SEC-01 … SEC-09).
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
    // SEC-01 — SSL verification must be enabled
    // -------------------------------------------------------------------------

    /**
     * Curl.php must NOT set CURLOPT_SSL_VERIFYPEER to false.
     *
     * Fixes: SEC-01 (man-in-the-middle via disabled certificate validation)
     */
    public function testCurlHelperDoesNotDisableSslVerification(): void
    {
        $file    = self::$root . '/Helper/Curl.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER\' => false',
            $content,
            'SEC-01: Curl.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
        $this->assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER", false',
            $content,
            'SEC-01: Curl.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
    }

    /**
     * JwtVerifier.php must set CURLOPT_SSL_VERIFYPEER to true.
     *
     * Fixes: SEC-01
     */
    public function testJwtVerifierEnablesSslVerification(): void
    {
        $file    = self::$root . '/Helper/JwtVerifier.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'CURLOPT_SSL_VERIFYPEER',
            $content,
            'SEC-01: JwtVerifier.php must configure CURLOPT_SSL_VERIFYPEER'
        );
        // The value next to it must NOT be false
        $this->assertStringNotContainsString(
            "'CURLOPT_SSL_VERIFYPEER' => false",
            $content,
            'SEC-01: JwtVerifier.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
    }

    /**
     * Debug.php test curl call must not hard-set CURLOPT_SSL_VERIFYPEER to false.
     *
     * Fixes: SEC-01
     */
    public function testDebugBlockDoesNotDisableSslVerification(): void
    {
        $file    = self::$root . '/Block/Adminhtml/Debug.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'CURLOPT_SSL_VERIFYPEER, false',
            $content,
            'SEC-01: Debug.php must not disable CURLOPT_SSL_VERIFYPEER'
        );
    }

    // -------------------------------------------------------------------------
    // SEC-03 — Alpine.js XSS via x-html
    // -------------------------------------------------------------------------

    /**
     * The Hyva authentication popup must not use x-html for the message binding.
     *
     * Fixes: SEC-03 (stored XSS via x-html Alpine.js directive)
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
            'SEC-03: authentication-popup.phtml must not use x-html for the message binding'
        );
    }

    // -------------------------------------------------------------------------
    // SEC-04 — Deprecated FILTER_SANITIZE_URL removed
    // -------------------------------------------------------------------------

    /**
     * OAuthsettings/Index.php must not use the deprecated FILTER_SANITIZE_URL.
     *
     * Fixes: SEC-04 (SSRF via insufficient URL sanitisation)
     */
    public function testOauthSettingsDoesNotUseSanitizeUrl(): void
    {
        $file    = self::$root . '/Controller/Adminhtml/OAuthsettings/Index.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'FILTER_SANITIZE_URL',
            $content,
            'SEC-04: OAuthsettings/Index.php must not use deprecated FILTER_SANITIZE_URL'
        );
    }

    /**
     * Providersettings/Index.php must not use the deprecated FILTER_SANITIZE_URL.
     *
     * Fixes: SEC-04 (parity with OAuthsettings/Index.php check)
     */
    public function testProviderSettingsControllerDoesNotUseSanitizeUrl(): void
    {
        $file    = self::$root . '/Controller/Adminhtml/Providersettings/Index.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'FILTER_SANITIZE_URL',
            $content,
            'SEC-04: Providersettings/Index.php must not use deprecated FILTER_SANITIZE_URL'
        );
    }

    // -------------------------------------------------------------------------
    // SEC-05 — Hardcoded customer domain removed from CSP whitelist
    // -------------------------------------------------------------------------

    /**
     * csp_whitelist.xml files must not contain any hardcoded provider hostnames.
     *
     * Fixes: SEC-05 (customer domain shipped in module, privacy + security)
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
                "SEC-05: $file must not contain hardcoded <value> entries"
            );
        }
    }

    // -------------------------------------------------------------------------
    // SEC-06 — OidcCredentialPlugin must reset flags unconditionally
    // -------------------------------------------------------------------------

    /**
     * OidcCredentialPlugin::beforeLogin() must reset both flags before checking the password.
     *
     * Fixes: SEC-06 (flag state leaks between PHP-FPM worker requests)
     */
    public function testOidcCredentialPluginResetsIsOidcAuthFlag(): void
    {
        $file    = self::$root . '/Plugin/Auth/OidcCredentialPlugin.php';
        $content = $this->readFile($file);

        // The unconditional reset must appear BEFORE the OIDC_TOKEN_MARKER check
        $resetPos  = strpos($content, '$this->isOidcAuth    = false;');
        $markerPos = strpos($content, 'OIDC_TOKEN_MARKER');

        $this->assertNotFalse($resetPos, 'SEC-06: isOidcAuth reset not found in beforeLogin()');
        $this->assertNotFalse($markerPos, 'SEC-06: OIDC_TOKEN_MARKER check not found');
        $this->assertLessThan(
            $markerPos,
            $resetPos,
            'SEC-06: isOidcAuth must be reset BEFORE the OIDC_TOKEN_MARKER check'
        );
    }

    // -------------------------------------------------------------------------
    // SEC-07 — Error messages must use urlencode(), not base64_encode()
    // -------------------------------------------------------------------------

    /**
     * Admin-facing OIDC error paths must not use base64_encode() for error messages.
     *
     * base64 provides no security — it merely obfuscates and breaks the error
     * display when OidcErrorMessage::getOidcErrorMessage() doesn't decode it.
     *
     * Fixes: SEC-07
     */
    public function testAdminOidcCallbackDoesNotBase64EncodeErrors(): void
    {
        $file    = self::$root . '/Controller/Adminhtml/Actions/Oidccallback.php';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'base64_encode',
            $content,
            'SEC-07: Oidccallback.php must not base64_encode OIDC error messages'
        );
    }

    public function testCheckAttributeMappingActionDoesNotBase64EncodeErrors(): void
    {
        $file    = self::$root . '/Controller/Actions/CheckAttributeMappingAction.php';
        $content = $this->readFile($file);

        // base64_encode should not appear for error message encoding
        // (it may legitimately appear elsewhere for other purposes)
        $this->assertStringNotContainsString(
            'base64_encode($errorMessage)',
            $content,
            'SEC-07: CheckAttributeMappingAction must not base64_encode error messages'
        );
    }

    // -------------------------------------------------------------------------
    // SEC-09 — Relay state validated by host comparison, not str_contains
    // -------------------------------------------------------------------------

    /**
     * ProcessUserAction must use parse_url host comparison for relay state validation.
     *
     * str_contains(relayState, storeUrl) can be bypassed:
     *   evil.com/?x=https://legit.store.com
     *
     * Fixes: SEC-09
     */
    public function testProcessUserActionValidatesRelayStateByHost(): void
    {
        $file    = self::$root . '/Controller/Actions/ProcessUserAction.php';
        $content = $this->readFile($file);

        // Must use parse_url for relay state host extraction
        $this->assertStringContainsString(
            'parse_url',
            $content,
            'SEC-09: ProcessUserAction must use parse_url for relay state validation'
        );

        // Must NOT use str_contains with the store URL as the relay state guard
        $this->assertStringNotContainsString(
            "str_contains((string) \$this->attrs['relayState'], \$store_url)",
            $content,
            'SEC-09: ProcessUserAction must not use str_contains for relay state validation'
        );
    }

    // -------------------------------------------------------------------------
    // REF-02 — extractNameFromEmail() exists in OAuthUtility
    // -------------------------------------------------------------------------

    /**
     * OAuthUtility must expose extractNameFromEmail() as the shared name-extraction helper.
     *
     * Fixes: REF-02 (duplicate name-fallback logic in multiple classes)
     */
    public function testOAuthUtilityExposesExtractNameFromEmail(): void
    {
        $file    = self::$root . '/Helper/OAuthUtility.php';
        $content = $this->readFile($file);

        $this->assertStringContainsString(
            'function extractNameFromEmail',
            $content,
            'REF-02: OAuthUtility must define extractNameFromEmail()'
        );
    }

    // -------------------------------------------------------------------------
    // REF-05 — ACL title corrected
    // -------------------------------------------------------------------------

    /**
     * acl.xml must use "MiniOrange OIDC", not the old "Authelia OIDC" title.
     *
     * Fixes: REF-05 (branding error)
     */
    public function testAclXmlHasCorrectModuleTitle(): void
    {
        $file    = self::$root . '/etc/acl.xml';
        $content = $this->readFile($file);

        $this->assertStringNotContainsString(
            'Authelia OIDC',
            $content,
            'REF-05: acl.xml must not contain old "Authelia OIDC" title'
        );
        $this->assertStringContainsString(
            'MiniOrange OIDC',
            $content,
            'REF-05: acl.xml must contain "MiniOrange OIDC" title'
        );
    }

    // -------------------------------------------------------------------------
    // MP-06 — Provider Settings ACL resource ID consistency
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
            'MP-06: Providersettings/Index.php must reference the provider_settings ACL resource'
        );
        $this->assertStringContainsString(
            'MiniOrange_OAuth::provider_settings',
            $aclContent,
            'MP-06: acl.xml must declare the MiniOrange_OAuth::provider_settings resource'
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
