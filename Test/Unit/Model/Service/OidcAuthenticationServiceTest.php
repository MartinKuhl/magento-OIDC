<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use M2Oidc\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OidcAuthenticationService.
 *
 * Covers:
 *  - validateUserInfo() error detection
 *  - flattenAttributes() with claim_encoding=none (default)
 *  - flattenAttributes() with claim_encoding=base64 (Zitadel Base64 support)
 *  - flattenAttributes() depth-limit enforcement (MAX_RECURSION_DEPTH = 5)
 *  - normalizeGroups() — plain string, flat array, and Zitadel nested object format
 *
 * @covers \M2Oidc\OAuth\Model\Service\OidcAuthenticationService
 */
class OidcAuthenticationServiceTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var OidcAuthenticationService */
    private OidcAuthenticationService $service;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->oauthUtility->method('customlog');

        $this->service = new OidcAuthenticationService($this->oauthUtility);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Configure the OAuthUtility mock to report the given claim_encoding for the
     * lifetime of a single test.
     */
    private function withEncoding(string $encoding): void
    {
        $this->oauthUtility->method('getStoreConfig')
            ->with(OAuthConstants::CLAIM_ENCODING)
            ->willReturn($encoding);
    }

    // =========================================================================
    // validateUserInfo()
    // =========================================================================

    public function testValidateUserInfoPassesForValidArray(): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->validateUserInfo(['sub' => 'user123', 'email' => 'user@example.com']);
    }

    public function testValidateUserInfoThrowsForErrorKeyInArray(): void
    {
        $this->expectException(IncorrectUserInfoDataException::class);
        $this->service->validateUserInfo(['error' => 'invalid_token']);
    }

    public function testValidateUserInfoThrowsForErrorKeyInObject(): void
    {
        $this->expectException(IncorrectUserInfoDataException::class);
        $obj        = new \stdClass();
        $obj->error = 'invalid_token';
        $this->service->validateUserInfo($obj);
    }

    public function testValidateUserInfoThrowsForEmptyArray(): void
    {
        $this->expectException(IncorrectUserInfoDataException::class);
        $this->service->validateUserInfo([]);
    }

    public function testValidateUserInfoThrowsForEmptyString(): void
    {
        $this->expectException(IncorrectUserInfoDataException::class);
        $this->service->validateUserInfo('');
    }

    // =========================================================================
    // flattenAttributes() — encoding=none
    // =========================================================================

    public function testFlattenAttributesNonePreservesBase64EncodedString(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_NONE);

        $result = [];
        $this->service->flattenAttributes('', ['given_name' => 'QWxpY2U='], $result);

        // With encoding=none the base64 string must NOT be decoded
        $this->assertSame('QWxpY2U=', $result['given_name']);
    }

    public function testFlattenAttributesNoneFlattensByDotNotation(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_NONE);

        $result = [];
        $this->service->flattenAttributes('', ['address' => ['city' => 'Berlin']], $result);

        $this->assertArrayHasKey('address.city', $result);
        $this->assertSame('Berlin', $result['address.city']);
    }

    public function testFlattenAttributesNoneHandlesEmptyInput(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_NONE);

        $result = [];
        $this->service->flattenAttributes('', [], $result);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // flattenAttributes() — encoding=base64 (Zitadel)
    // =========================================================================

    /**
     * A properly Base64-encoded leaf value must be decoded.
     * base64_encode('Alice') = 'QWxpY2U='
     */
    public function testFlattenAttributesBase64DecodesLeafValue(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_BASE64);

        $result = [];
        $this->service->flattenAttributes('', ['given_name' => 'QWxpY2U='], $result);

        $this->assertSame('Alice', $result['given_name']);
    }

    /**
     * A value that contains characters outside the Base64 alphabet (e.g. '!')
     * must be kept unchanged when strict decode fails.
     */
    public function testFlattenAttributesBase64KeepsInvalidBase64Unchanged(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_BASE64);

        $result = [];
        $this->service->flattenAttributes('', ['given_name' => 'not!!!base64'], $result);

        $this->assertSame('not!!!base64', $result['given_name']);
    }

    /**
     * Keys are also decoded: base64_encode('name') = 'bmFtZQ=='
     * The decoded key must appear in the result, not the raw Base64 key.
     */
    public function testFlattenAttributesBase64DecodesBothKeyAndValue(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_BASE64);

        $result = [];
        // 'bmFtZQ==' → 'name', 'QWxpY2U=' → 'Alice'
        $this->service->flattenAttributes('', ['bmFtZQ==' => 'QWxpY2U='], $result);

        $this->assertArrayHasKey('name', $result);
        $this->assertSame('Alice', $result['name']);
        $this->assertArrayNotHasKey('bmFtZQ==', $result);
    }

    /**
     * A nested claim must be flattened first, then the leaf value decoded.
     * base64_encode('Berlin') = 'QmVybGlu'
     */
    public function testFlattenAttributesBase64DecodesFlattenedNestedLeaf(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_BASE64);

        $result = [];
        $this->service->flattenAttributes('', ['address' => ['city' => 'QmVybGlu']], $result);

        $this->assertArrayHasKey('address.city', $result);
        $this->assertSame('Berlin', $result['address.city']);
    }

    /**
     * A value that decodes to non-UTF-8 bytes must be kept as-is.
     * '/w==' is the strict Base64 for "\xFF" which is invalid UTF-8.
     */
    public function testFlattenAttributesBase64KeepsValueDecodingToNonUtf8(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_BASE64);

        $result = [];
        $this->service->flattenAttributes('', ['binary_claim' => '/w=='], $result);

        $this->assertSame('/w==', $result['binary_claim']);
    }

    // =========================================================================
    // flattenAttributes() — depth limit (MAX_RECURSION_DEPTH = 5)
    // =========================================================================

    /**
     * A leaf at exactly depth 5 (6 levels of nesting) must be captured.
     *
     * Call at depth 0 → 'a' is nested → recurse depth 1
     * ...
     * Call at depth 5 → 'f' is a scalar → captured ✓
     */
    public function testFlattenAttributesCapturesLeafAtMaxDepth(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_NONE);

        $data   = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep_value']]]]]];
        $result = [];
        $this->service->flattenAttributes('', $data, $result);

        $this->assertArrayHasKey('a.b.c.d.e.f', $result);
        $this->assertSame('deep_value', $result['a.b.c.d.e.f']);
    }

    /**
     * A leaf at depth 6 (7 levels of nesting) must NOT be captured — the recursion
     * guard (depth > MAX_RECURSION_DEPTH) kicks in at depth 6.
     */
    public function testFlattenAttributesTruncatesBeyondMaxDepth(): void
    {
        $this->withEncoding(OAuthConstants::CLAIM_ENCODING_NONE);

        $data   = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'too_deep']]]]]]];
        $result = [];
        $this->service->flattenAttributes('', $data, $result);

        $this->assertArrayNotHasKey('a.b.c.d.e.f.g', $result);
    }

    // =========================================================================
    // normalizeGroups()
    // =========================================================================

    public function testNormalizeGroupsReturnsSingleElementForString(): void
    {
        $this->assertSame(['admin'], $this->service->normalizeGroups('admin'));
    }

    public function testNormalizeGroupsHandlesFlatScalarArray(): void
    {
        $input = ['admin', 'member', 'editor'];
        $this->assertSame($input, $this->service->normalizeGroups($input));
    }

    public function testNormalizeGroupsFiltersEmptyStringsFromArray(): void
    {
        $groups = $this->service->normalizeGroups(['admin', '', 'editor', '']);
        $this->assertSame(['admin', 'editor'], $groups);
    }

    /**
     * Zitadel nested format: {"role_name": {"org_id": "some_org"}}
     * The KEYS of the outer object are the role names.
     */
    public function testNormalizeGroupsHandlesZitadelNestedObjectWithSingleRole(): void
    {
        $raw = ['admin' => ['org_123456789012345' => 'some_org']];

        $groups = $this->service->normalizeGroups($raw);

        $this->assertSame(['admin'], $groups);
    }

    /**
     * Multiple Zitadel role entries → all role keys extracted.
     */
    public function testNormalizeGroupsHandlesZitadelNestedObjectWithMultipleRoles(): void
    {
        $raw = [
            'admin'    => ['org_123456789012345' => 'some_org'],
            'editor'   => ['org_123456789012345' => 'some_org'],
            'reviewer' => ['org_999999999999999' => 'other_org'],
        ];

        $groups = $this->service->normalizeGroups($raw);

        $this->assertCount(3, $groups);
        $this->assertContains('admin', $groups);
        $this->assertContains('editor', $groups);
        $this->assertContains('reviewer', $groups);
    }

    /**
     * Zitadel nested format as stdClass object (decoded JSON object).
     */
    public function testNormalizeGroupsHandlesZitadelNestedStdClassObject(): void
    {
        $orgData        = new \stdClass();
        $orgData->orgId = 'some_org';

        $raw        = new \stdClass();
        $raw->admin = $orgData;

        $groups = $this->service->normalizeGroups($raw);

        $this->assertSame(['admin'], $groups);
    }

    public function testNormalizeGroupsReturnsEmptyArrayForNull(): void
    {
        $this->assertSame([], $this->service->normalizeGroups(null));
    }

    public function testNormalizeGroupsReturnsEmptyArrayForEmptyString(): void
    {
        $this->assertSame([], $this->service->normalizeGroups(''));
    }

    public function testNormalizeGroupsReturnsEmptyArrayForEmptyArray(): void
    {
        $this->assertSame([], $this->service->normalizeGroups([]));
    }
}
