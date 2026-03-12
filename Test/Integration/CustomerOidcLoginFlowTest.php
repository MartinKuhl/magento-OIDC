<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration;

use MiniOrange\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\Service\OidcAuthenticationService;

/**
 * Integration tests for OidcAuthenticationService attribute flattening
 * and email extraction with realistic OIDC claim structures (TEST-06).
 *
 * Tests that require a live Dex provider are guarded with an early return
 * when $this->discovery is empty (Dex unreachable).  All other tests run
 * purely in memory using a PHPUnit mock for OAuthUtility.
 */
class CustomerOidcLoginFlowTest extends AbstractOidcIntegrationTest
{
    /** @var OAuthUtility&\PHPUnit\Framework\MockObject\MockObject */
    private OAuthUtility $oauthUtility;

    /** @var OidcAuthenticationService */
    private OidcAuthenticationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauthUtility = $this->createMock(OAuthUtility::class);

        $this->oauthUtility
            ->method('getStoreConfig')
            ->willReturn('email');

        $this->oauthUtility
            ->method('isBlank')
            ->willReturnCallback(static function (mixed $val): bool {
                return $val === null || $val === '';
            });

        $this->oauthUtility
            ->method('customlog');

        $this->service = new OidcAuthenticationService($this->oauthUtility);
    }

    // ---------------------------------------------------------------- flattenAttributes

    public function testFlattenAttributesProducesExpectedKeys(): void
    {
        $input  = ['profile' => ['email' => 'a@b.com', 'name' => 'Alice']];
        $result = [];
        $this->service->flattenAttributes('', $input, $result);

        $this->assertSame('a@b.com', $result['profile.email']);
        $this->assertSame('Alice', $result['profile.name']);
        $this->assertCount(2, $result);
    }

    public function testFlattenAttributesHandlesTopLevelScalars(): void
    {
        $input  = ['email' => 'x@y.com', 'sub' => '123'];
        $result = [];
        $this->service->flattenAttributes('', $input, $result);

        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('sub', $result);
        $this->assertSame('x@y.com', $result['email']);
        $this->assertSame('123', $result['sub']);
    }

    // ---------------------------------------------------------------- extractEmail

    public function testExtractEmailUsesConfiguredAttribute(): void
    {
        $flattened = ['email' => 'user@example.com', 'sub' => '42'];
        $email = $this->service->extractEmail($flattened, $flattened);

        $this->assertSame('user@example.com', $email);
    }

    public function testExtractEmailFallsBackToRecursiveSearch(): void
    {
        // The configured attribute ('email') is absent from the flattened map,
        // but a valid email is nested inside the raw response.
        $flattened   = ['sub' => '99'];
        $rawResponse = ['data' => ['user_email' => 'fallback@example.com']];

        $email = $this->service->extractEmail($flattened, $rawResponse);

        $this->assertSame('fallback@example.com', $email);
    }

    public function testExtractEmailReturnsEmptyStringWhenNoEmailFound(): void
    {
        $flattened   = ['sub' => '55', 'name' => 'No Email'];
        $rawResponse = ['sub' => '55', 'name' => 'No Email'];

        $email = $this->service->extractEmail($flattened, $rawResponse);

        $this->assertSame('', $email);
    }

    // ---------------------------------------------------------------- validateUserInfo

    public function testValidateUserInfoThrowsOnEmptyResponse(): void
    {
        $this->expectException(IncorrectUserInfoDataException::class);
        $this->service->validateUserInfo([]);
    }

    public function testValidateUserInfoThrowsOnErrorResponse(): void
    {
        $this->expectException(IncorrectUserInfoDataException::class);
        $this->service->validateUserInfo(['error' => 'invalid_token']);
    }

    public function testValidateUserInfoPassesOnValidResponse(): void
    {
        // No exception expected
        $this->service->validateUserInfo(['sub' => '123', 'email' => 'x@y.com']);
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------- Dex live test

    public function testFetchTokenFromDexAndExtractEmail(): void
    {
        if ($this->discovery === []) {
            return;
        }

        $tokenResponse = $this->fetchTokenViaPassword('admin@example.com', 'password');

        $this->assertArrayHasKey('id_token', $tokenResponse, 'Token response must include id_token.');

        $claims    = $this->decodeJwtPayload((string) $tokenResponse['id_token']);
        $flattened = [];
        $this->service->flattenAttributes('', $claims, $flattened);

        $email = $this->service->extractEmail($flattened, $claims);

        $this->assertNotEmpty($email, 'Email must be extractable from real Dex JWT claims.');
        $this->assertStringContainsString('@', $email, 'Extracted value must look like an email address.');
    }
}
