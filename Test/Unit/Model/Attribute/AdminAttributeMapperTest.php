<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Attribute;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\AdminAttributeMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminAttributeMapper (Phase 3.2).
 *
 * @covers \M2Oidc\OAuth\Model\Attribute\AdminAttributeMapper
 */
class AdminAttributeMapperTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var AdminAttributeMapper */
    private AdminAttributeMapper $mapper;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->oauthUtility->method('customlog');

        $this->mapper = new AdminAttributeMapper($this->oauthUtility);
    }

    // -------------------------------------------------------------------------
    // Both names already present — no fallback needed
    // -------------------------------------------------------------------------

    public function testMapReturnsBothNamesWhenPresent(): void
    {
        $result = $this->mapper->map(
            ['firstname' => 'Jane', 'lastname' => 'Doe'],
            ['_email' => 'jane.doe@example.com']
        );

        $this->assertSame('Jane', $result['firstname']);
        $this->assertSame('Doe', $result['lastname']);
    }

    public function testMapDoesNotCallExtractNameFromEmailWhenBothNamesPresent(): void
    {
        $this->oauthUtility->expects($this->never())->method('extractNameFromEmail');

        $this->mapper->map(
            ['firstname' => 'Alice', 'lastname' => 'Smith'],
            ['_email' => 'alice@example.com']
        );
    }

    // -------------------------------------------------------------------------
    // Fallback from email prefix
    // -------------------------------------------------------------------------

    public function testMapFallsBackToEmailPrefixWhenFirstNameEmpty(): void
    {
        $this->oauthUtility->method('extractNameFromEmail')
            ->with('foo@bar.com')
            ->willReturn(['first' => 'foo', 'last' => 'bar']);

        $result = $this->mapper->map(
            ['firstname' => '', 'lastname' => 'bar'],
            ['_email' => 'foo@bar.com']
        );

        $this->assertSame('foo', $result['firstname']);
        $this->assertSame('bar', $result['lastname']);
    }

    public function testMapFallsBackToEmailDomainWhenLastNameEmpty(): void
    {
        $this->oauthUtility->method('extractNameFromEmail')
            ->with('alice@company.com')
            ->willReturn(['first' => 'alice', 'last' => 'company']);

        $result = $this->mapper->map(
            ['firstname' => 'Alice', 'lastname' => ''],
            ['_email' => 'alice@company.com']
        );

        $this->assertSame('Alice', $result['firstname']);
        $this->assertSame('company', $result['lastname']);
    }

    public function testMapDerivesBothNamesFromEmailWhenBothEmpty(): void
    {
        $this->oauthUtility->method('extractNameFromEmail')
            ->with('john.smith@example.com')
            ->willReturn(['first' => 'john.smith', 'last' => 'example']);

        $result = $this->mapper->map(
            ['firstname' => '', 'lastname' => ''],
            ['_email' => 'john.smith@example.com']
        );

        $this->assertSame('john.smith', $result['firstname']);
        $this->assertSame('example', $result['lastname']);
    }

    // -------------------------------------------------------------------------
    // Fallback when derived last name is also empty
    // -------------------------------------------------------------------------

    public function testMapReusesFirstNameWhenDerivedLastNameIsEmpty(): void
    {
        // extractNameFromEmail returns '' for last when email has no domain part or similar
        $this->oauthUtility->method('extractNameFromEmail')
            ->willReturn(['first' => 'solo', 'last' => '']);

        $result = $this->mapper->map(
            ['firstname' => '', 'lastname' => ''],
            ['_email' => 'solo@x.com']
        );

        $this->assertSame('solo', $result['firstname']);
        $this->assertSame('solo', $result['lastname']); // fallback: reuse firstname
    }

    // -------------------------------------------------------------------------
    // Missing flattenedAttrs keys are treated as empty
    // -------------------------------------------------------------------------

    public function testMapHandlesMissingFlattenedAttrKeys(): void
    {
        $this->oauthUtility->method('extractNameFromEmail')
            ->willReturn(['first' => 'derived', 'last' => 'name']);

        $result = $this->mapper->map(
            [],  // no keys at all
            ['_email' => 'derived.name@example.com']
        );

        $this->assertSame('derived', $result['firstname']);
        $this->assertSame('name', $result['lastname']);
    }

    // -------------------------------------------------------------------------
    // No email available — returns empty strings
    // -------------------------------------------------------------------------

    public function testMapReturnsEmptyStringsWhenNoNamesAndNoEmail(): void
    {
        $this->oauthUtility->expects($this->never())->method('extractNameFromEmail');

        $result = $this->mapper->map([], []);

        $this->assertSame('', $result['firstname']);
        $this->assertSame('', $result['lastname']);
    }
}
