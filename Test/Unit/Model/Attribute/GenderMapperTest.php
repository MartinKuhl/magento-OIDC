<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Attribute;

use M2Oidc\OAuth\Model\Attribute\GenderMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GenderMapper.
 *
 * Verifies the unified gender recognizer shared by CustomerAttributeMapper
 * (customer creation) and CustomerProfileSyncService (profile re-sync) —
 * fixing the drift where CustomerProfileSyncService did not recognize the
 * German words ("mann"/"männlich", "frau"/"weiblich") that CustomerAttributeMapper
 * already handled, silently stopping re-sync for German-speaking IdPs.
 *
 * @covers \M2Oidc\OAuth\Model\Attribute\GenderMapper
 */
class GenderMapperTest extends TestCase
{
    /** @var GenderMapper */
    private GenderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new GenderMapper();
    }

    /** @dataProvider maleProvider */
    public function testMapsToMale(string $input): void
    {
        $this->assertSame(1, $this->mapper->map($input));
    }

    /** @return iterable<string, array{string}> */
    public static function maleProvider(): iterable
    {
        yield 'male'          => ['male'];
        yield 'Male mixed case' => ['Male'];
        yield 'MALE uppercase' => ['MALE'];
        yield 'm short'        => ['m'];
        yield 'M uppercase short' => ['M'];
        yield '1 numeric'      => ['1'];
        yield 'mann (German)'  => ['mann'];
        yield 'Mann (German capitalized)' => ['Mann'];
        yield 'männlich (German)' => ['männlich'];
        yield 'MÄNNLICH (German uppercase)' => ['MÄNNLICH'];
        yield 'padded whitespace' => ['  male  '];
    }

    /** @dataProvider femaleProvider */
    public function testMapsToFemale(string $input): void
    {
        $this->assertSame(2, $this->mapper->map($input));
    }

    /** @return iterable<string, array{string}> */
    public static function femaleProvider(): iterable
    {
        yield 'female'          => ['female'];
        yield 'Female mixed case' => ['Female'];
        yield 'FEMALE uppercase' => ['FEMALE'];
        yield 'f short'          => ['f'];
        yield 'F uppercase short' => ['F'];
        yield '2 numeric'        => ['2'];
        yield 'frau (German)'    => ['frau'];
        yield 'Frau (German capitalized)' => ['Frau'];
        yield 'weiblich (German)' => ['weiblich'];
        yield 'WEIBLICH (German uppercase)' => ['WEIBLICH'];
        yield 'padded whitespace' => ['  female  '];
    }

    /** @dataProvider unrecognizedProvider */
    public function testReturnsNullForUnrecognizedValues(string $input): void
    {
        $this->assertNull($this->mapper->map($input));
    }

    /** @return iterable<string, array{string}> */
    public static function unrecognizedProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'other'        => ['other'];
        yield 'diverse'      => ['diverse'];
        yield 'numeric 3'    => ['3'];
        yield 'non-binary'   => ['non-binary'];
        yield 'random word'  => ['xyz'];
        yield 'whitespace only' => ['   '];
    }
}
