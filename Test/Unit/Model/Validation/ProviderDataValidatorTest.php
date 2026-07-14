<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Validation;

use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Model\Validation\ProviderDataValidator;
use M2Oidc\OAuth\Model\Validation\SsrfUrlValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared provider-data validator.
 *
 * Verifies that:
 *  - invalid enum values (login_type, claim_encoding, pkce_flow) are
 *    auto-normalized to their safe defaults with a warning
 *  - endpoint URLs pointing to private hosts are stripped with a warning
 *  - the OIDC-only lockout guard reverts the flag when no OIDC users exist
 *
 * @covers \M2Oidc\OAuth\Model\Validation\ProviderDataValidator
 * @covers \M2Oidc\OAuth\Model\Validation\ProviderValidationResult
 */
class ProviderDataValidatorTest extends TestCase
{
    /** @var UserProviderResource&MockObject */
    private UserProviderResource $userProviderResource;

    /** @var ProviderDataValidator */
    private ProviderDataValidator $validator;

    protected function setUp(): void
    {
        $this->userProviderResource = $this->createMock(UserProviderResource::class);
        $this->validator            = new ProviderDataValidator(
            new SsrfUrlValidator(),
            $this->userProviderResource
        );
    }

    // -------------------------------------------------------------------------
    // Enum whitelists
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidEnumProvider
     */
    public function testInvalidEnumValueIsNormalizedWithWarning(
        string $field,
        string $badValue,
        string $expectedDefault
    ): void {
        $result = $this->validator->validate([$field => $badValue], 0);

        $this->assertSame($expectedDefault, $result->getData()[$field]);
        $this->assertCount(1, $result->getWarnings());
        $this->assertSame([], $result->getErrors());
        $this->assertTrue($result->isValid());
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidEnumProvider(): array
    {
        return [
            'login_type superadmin'    => ['login_type', 'superadmin', 'customer'],
            'login_type uppercase'     => ['login_type', 'ADMIN', 'customer'],
            'claim_encoding hex'       => ['claim_encoding', 'hex', 'none'],
            'claim_encoding uppercase' => ['claim_encoding', 'BASE64', 'none'],
            'pkce_flow sha1'           => ['pkce_flow', 'sha1', ''],
            'pkce_flow lowercase s256' => ['pkce_flow', 's256', ''],
        ];
    }

    /**
     * @dataProvider validEnumProvider
     */
    public function testValidEnumValueIsKeptWithoutWarning(string $field, string $goodValue): void
    {
        $result = $this->validator->validate([$field => $goodValue], 0);

        $this->assertSame($goodValue, $result->getData()[$field]);
        $this->assertSame([], $result->getWarnings());
    }

    /** @return array<string, array{string, string}> */
    public static function validEnumProvider(): array
    {
        return [
            'login_type customer'    => ['login_type', 'customer'],
            'login_type admin'       => ['login_type', 'admin'],
            'login_type both'        => ['login_type', 'both'],
            'claim_encoding none'    => ['claim_encoding', 'none'],
            'claim_encoding base64'  => ['claim_encoding', 'base64'],
            'pkce_flow S256'         => ['pkce_flow', 'S256'],
            'pkce_flow plain'        => ['pkce_flow', 'plain'],
            'pkce_flow disabled'     => ['pkce_flow', ''],
        ];
    }

    public function testMissingEnumFieldsAreLeftUntouched(): void
    {
        $result = $this->validator->validate(['app_name' => 'My IdP'], 0);

        $this->assertSame(['app_name' => 'My IdP'], $result->getData());
        $this->assertSame([], $result->getWarnings());
    }

    // -------------------------------------------------------------------------
    // SSRF endpoint stripping
    // -------------------------------------------------------------------------

    /**
     * @dataProvider unsafeEndpointProvider
     */
    public function testUnsafeEndpointUrlIsStrippedWithWarning(string $field, string $badUrl): void
    {
        $result = $this->validator->validate([$field => $badUrl], 0);

        $this->assertArrayNotHasKey($field, $result->getData(), "Unsafe $field must be removed");
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString($field, $result->getWarnings()[0]);
    }

    /** @return array<string, array{string, string}> */
    public static function unsafeEndpointProvider(): array
    {
        return [
            'private authorize_endpoint'     => ['authorize_endpoint', 'https://192.168.1.10/auth'],
            'loopback access_token_endpoint' => ['access_token_endpoint', 'https://127.0.0.1/token'],
            'http user_info_endpoint'        => ['user_info_endpoint', 'http://idp.example.com/userinfo'],
            'private jwks_endpoint'          => ['jwks_endpoint', 'https://10.1.2.3/jwks'],
            'localhost endsession_endpoint'  => ['endsession_endpoint', 'https://localhost/logout'],
            'private revocation_endpoint'    => ['revocation_endpoint', 'https://172.16.5.5/revoke'],
            'private well_known_config_url'  => [
                'well_known_config_url',
                'https://192.168.0.1/.well-known/openid-configuration',
            ],
            'private health_alert_webhook_url' => ['health_alert_webhook_url', 'https://127.0.0.1/hooks/alert'],
            'http health_alert_webhook_url'    => [
                'health_alert_webhook_url',
                'http://hooks.slack.com/services/T000/B000/XXXX',
            ],
        ];
    }

    public function testSafeWebhookUrlIsKeptWithoutWarning(): void
    {
        $data = ['health_alert_webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX'];

        $result = $this->validator->validate($data, 0);

        $this->assertSame($data, $result->getData());
        $this->assertSame([], $result->getWarnings());
    }

    public function testSafeEndpointUrlsAreKeptWithoutWarning(): void
    {
        $data = [
            'authorize_endpoint'    => 'https://idp.example.com/auth',
            'access_token_endpoint' => 'https://idp.example.com/token',
            'jwks_endpoint'         => 'https://idp.example.com/jwks',
        ];

        $result = $this->validator->validate($data, 0);

        $this->assertSame($data, $result->getData());
        $this->assertSame([], $result->getWarnings());
    }

    public function testEmptyEndpointFieldsAreIgnored(): void
    {
        $result = $this->validator->validate(['authorize_endpoint' => ''], 0);

        $this->assertSame(['authorize_endpoint' => ''], $result->getData());
        $this->assertSame([], $result->getWarnings());
    }

    // -------------------------------------------------------------------------
    // Lockout-prevention guard
    // -------------------------------------------------------------------------

    public function testAdminLockoutFlagIsRevertedWhenNoOidcAdminsExist(): void
    {
        $this->userProviderResource->method('countByTypeAndProvider')
            ->with('admin', 5)
            ->willReturn(0);

        $result = $this->validator->validate(['m2oidc_disable_non_oidc_admin_login' => 1], 5);

        $this->assertSame(0, $result->getData()['m2oidc_disable_non_oidc_admin_login']);
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString(
            'Admin OIDC-only login was automatically disabled',
            $result->getWarnings()[0]
        );
    }

    public function testCustomerLockoutFlagIsRevertedWhenNoOidcCustomersExist(): void
    {
        $this->userProviderResource->method('countByTypeAndProvider')
            ->with('customer', 7)
            ->willReturn(0);

        $result = $this->validator->validate(['m2oidc_disable_non_oidc_customer_login' => 1], 7);

        $this->assertSame(0, $result->getData()['m2oidc_disable_non_oidc_customer_login']);
        $this->assertCount(1, $result->getWarnings());
        $this->assertStringContainsString(
            'Customer OIDC-only login was automatically disabled',
            $result->getWarnings()[0]
        );
    }

    public function testLockoutFlagIsKeptWhenOidcUsersExist(): void
    {
        $this->userProviderResource->method('countByTypeAndProvider')
            ->with('admin', 5)
            ->willReturn(3);

        $result = $this->validator->validate(['m2oidc_disable_non_oidc_admin_login' => 1], 5);

        $this->assertSame(1, (int) $result->getData()['m2oidc_disable_non_oidc_admin_login']);
        $this->assertSame([], $result->getWarnings());
    }

    public function testLockoutGuardIsSkippedForNewProviders(): void
    {
        // Matches Provider/Save.php: the guard only applies when $providerId > 0.
        $this->userProviderResource->expects($this->never())
            ->method('countByTypeAndProvider');

        $result = $this->validator->validate(['m2oidc_disable_non_oidc_admin_login' => 1], 0);

        $this->assertSame(1, (int) $result->getData()['m2oidc_disable_non_oidc_admin_login']);
        $this->assertSame([], $result->getWarnings());
    }

    public function testDisabledLockoutFlagDoesNotQueryUserCounts(): void
    {
        $this->userProviderResource->expects($this->never())
            ->method('countByTypeAndProvider');

        $result = $this->validator->validate(['m2oidc_disable_non_oidc_admin_login' => 0], 5);

        $this->assertSame(0, (int) $result->getData()['m2oidc_disable_non_oidc_admin_login']);
        $this->assertSame([], $result->getWarnings());
    }
}
