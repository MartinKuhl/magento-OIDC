<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Helper;

use MiniOrange\OAuth\Helper\OAuthUtility;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthUtility::extractNameFromEmail() (REF-02).
 *
 * This method is the single source of truth for deriving first/last name
 * from an email address when OIDC attributes are missing.
 *
 * @covers \MiniOrange\OAuth\Helper\OAuthUtility::extractNameFromEmail
 */
class OAuthUtilityExtractNameTest extends TestCase
{
    /**
     * Create a partial mock of OAuthUtility exposing only extractNameFromEmail().
     *
     * OAuthUtility has a heavyweight constructor with many Magento dependencies,
     * so we mock all other methods and test only the pure string-logic method.
     */
    private function makeUtility(): OAuthUtility
    {
        // We only need extractNameFromEmail() which has no dependencies — mock
        // everything else so the constructor is not invoked.
        return $this->getMockBuilder(OAuthUtility::class)
            ->disableOriginalConstructor()
            ->onlyMethods([]) // don't override the real extractNameFromEmail
            ->getMock();
    }

    /**
     * @dataProvider provideEmailNameCases
     */
    public function testExtractNameFromEmail(
        string $email,
        string $expectedFirst,
        string $expectedLast
    ): void {
        $util   = $this->makeUtility();
        $result = $util->extractNameFromEmail($email);

        $this->assertArrayHasKey('first', $result);
        $this->assertArrayHasKey('last', $result);
        $this->assertSame($expectedFirst, $result['first'], "First name mismatch for email: $email");
        $this->assertSame($expectedLast, $result['last'], "Last name mismatch for email: $email");
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideEmailNameCases(): array
    {
        return [
            'dot-separated'   => ['john.doe@example.com',   'John',     'Doe'],
            'underscore'      => ['jane_smith@example.com', 'Jane',     'Smith'],
            'hyphen'          => ['bob-jones@example.com',  'Bob',      'Jones'],
            'single-word'     => ['alice@example.com',      'Alice',    ''],
            'all-lowercase'   => ['john.doe@example.com',   'John',     'Doe'],
            'all-uppercase'   => ['JOHN.DOE@EXAMPLE.COM',   'John',     'Doe'],
            'numbers-in-name' => ['user123@example.com',    'User123',  ''],
            'space-separator' => ['first last@example.com', 'First',    'Last'],
            'no-at-sign'      => ['nodomain',               'Nodomain', ''],
            'empty-local'     => ['@example.com',           'Example',  'Com'],
        ];
    }
}
