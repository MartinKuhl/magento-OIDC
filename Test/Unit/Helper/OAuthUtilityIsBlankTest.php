<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper;

use M2Oidc\OAuth\Helper\OAuthUtility;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthUtility::isBlank() (L38).
 *
 * empty() treats the literal string "0" as blank, which misclassifies a
 * legitimately non-empty claim value (username, employee ID, group name)
 * that happens to be "0". isBlank() must treat "0" as non-blank while still
 * treating null, "", whitespace-only strings, and empty arrays as blank.
 *
 * @covers \M2Oidc\OAuth\Helper\OAuthUtility::isBlank
 */
class OAuthUtilityIsBlankTest extends TestCase
{
    /**
     * Create a partial mock of OAuthUtility exposing only isBlank().
     *
     * OAuthUtility has a heavyweight constructor with many Magento dependencies,
     * so we mock all other methods and test only the pure logic method.
     */
    private function makeUtility(): OAuthUtility
    {
        return $this->getMockBuilder(OAuthUtility::class)
            ->disableOriginalConstructor()
            ->onlyMethods([]) // don't override the real isBlank()
            ->getMock();
    }

    /**
     * @dataProvider provideBlankCases
     */
    public function testIsBlank(mixed $value, bool $expectedBlank): void
    {
        $util = $this->makeUtility();

        $this->assertSame(
            $expectedBlank,
            $util->isBlank($value),
            'isBlank() mismatch for value: ' . var_export($value, true)
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function provideBlankCases(): array
    {
        return [
            'null is blank'                 => [null, true],
            'empty string is blank'         => ['', true],
            'whitespace-only is blank'      => ['   ', true],
            'empty array is blank'          => [[], true],
            'string zero is NOT blank'      => ['0', false],
            'int zero is NOT blank'         => [0, false],
            'non-empty string is not blank' => ['admin', false],
            'string with spaces is not blank' => [' admin ', false],
            'non-empty array is not blank'  => [['a'], false],
        ];
    }
}
