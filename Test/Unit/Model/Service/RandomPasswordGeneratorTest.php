<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Framework\Math\Random;
use M2Oidc\OAuth\Model\Service\RandomPasswordGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RandomPasswordGenerator (M25).
 *
 * Verifies the shared password-generation logic previously duplicated in
 * AdminUserCreator and CustomerUserCreator: a 32-character password built from
 * Magento's Math\Random utility with guaranteed special-character and digit
 * character classes, shuffled to avoid predictable ordering (SEC-12).
 *
 * @covers \M2Oidc\OAuth\Model\Service\RandomPasswordGenerator
 */
class RandomPasswordGeneratorTest extends TestCase
{
    /**
     * Uses the real Magento\Framework\Math\Random utility (no external dependencies)
     * so the test exercises actual character-class guarantees end to end.
     */
    private function makeGenerator(): RandomPasswordGenerator
    {
        return new RandomPasswordGenerator(new Random());
    }

    public function testGeneratedPasswordIsThirtyTwoCharactersLong(): void
    {
        $password = $this->makeGenerator()->generate();

        $this->assertSame(32, strlen($password));
    }

    public function testGeneratedPasswordContainsAtLeastOneSpecialCharacter(): void
    {
        $password = $this->makeGenerator()->generate();

        $this->assertMatchesRegularExpression('/[!@#$%^&*]/', $password);
    }

    public function testGeneratedPasswordContainsAtLeastOneDigit(): void
    {
        $password = $this->makeGenerator()->generate();

        $this->assertMatchesRegularExpression('/[0-9]/', $password);
    }

    public function testConsecutiveCallsProduceDifferentPasswords(): void
    {
        $generator = $this->makeGenerator();

        $first  = $generator->generate();
        $second = $generator->generate();

        // Astronomically unlikely to collide with CSPRNG-backed randomness;
        // a match would indicate the generator is not actually randomizing.
        $this->assertNotSame($first, $second);
    }

    public function testGeneratorDelegatesToInjectedRandomUtilityWithExpectedCharacterClassLengths(): void
    {
        $random = $this->createMock(Random::class);
        $random->expects($this->exactly(3))->method('getRandomString')->willReturnMap([
            [28, null, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            [2, '!@#$%^&*', 'bb'],
            [2, '0123456789', 'cc'],
        ]);

        $generator = new RandomPasswordGenerator($random);
        $password  = $generator->generate();

        // str_shuffle() only permutes characters — the multiset must be preserved.
        $expectedChars = str_split('aaaaaaaaaaaaaaaaaaaaaaaaaaaabbcc');
        $actualChars   = str_split($password);
        sort($expectedChars);
        sort($actualChars);
        $this->assertSame($expectedChars, $actualChars);
    }
}
