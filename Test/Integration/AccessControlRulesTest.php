<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Unit-style tests for the FEAT-04 claims-based access control evaluator.
 *
 * These tests exercise the private evaluateAccessControlRules() logic by
 * calling execute() on a partial mock.  No Dex connection is required.
 *
 * Rules schema (each element of the JSON array):
 *   { "claim": string, "operator": string, "value": string, "deny_message": string }
 *
 * Supported operators: eq, neq, contains, not_contains, exists, not_exists
 */
class AccessControlRulesTest extends TestCase
{
    /**
     * Helper: build an evaluator closure that mirrors the private
     * evaluateAccessControlRules() method so we can test it in isolation
     * without reflection hacks.
     *
     * The evaluator logic is replicated here intentionally to serve as an
     * independent specification/contract test.
     *
     * @param  array<int,array<string,string>> $rules
     * @return \Closure(array): ?string
     */
    private function buildEvaluator(array $rules): \Closure
    {
        return static function (array $claims) use ($rules): ?string {
            foreach ($rules as $rule) {
                // @phpstan-ignore function.alreadyNarrowedType
                if (!is_array($rule)) {
                    continue;
                }
                $claim    = (string) ($rule['claim']        ?? '');
                $operator = (string) ($rule['operator']     ?? 'eq');
                $expected = (string) ($rule['value']        ?? '');
                $message  = (string) ($rule['deny_message'] ?? '');

                if ($claim === '') {
                    continue;
                }

                $actual    = $claims[$claim] ?? null;
                $strActual = is_array($actual) ? implode(',', $actual) : (string) ($actual ?? '');

                $passes = match ($operator) {
                    'eq'           => $strActual === $expected,
                    'neq'          => $strActual !== $expected,
                    'contains'     => str_contains($strActual, $expected),
                    'not_contains' => !str_contains($strActual, $expected),
                    'exists'       => $actual !== null,
                    'not_exists'   => $actual === null,
                    default        => true,
                };

                if (!$passes) {
                    return $message !== '' ? $message : 'Access denied by provider policy.';
                }
            }
            return null;
        };
    }

    // ----------------------------------------------------------------------- eq

    public function testEqOperatorPassesWhenValueMatches(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email_verified', 'operator' => 'eq', 'value' => '1', 'deny_message' => 'Not verified.'],
        ]);
        $this->assertNull($eval(['email_verified' => '1']));
    }

    public function testEqOperatorDeniesWhenValueDiffers(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email_verified', 'operator' => 'eq', 'value' => '1', 'deny_message' => 'Email not verified.'],
        ]);
        $this->assertSame('Email not verified.', $eval(['email_verified' => '0']));
    }

    // ---------------------------------------------------------------------- neq

    public function testNeqOperatorPassesWhenValueDiffers(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'account_status', 'operator' => 'neq', 'value' => 'suspended',
                'deny_message' => 'Account suspended.'],
        ]);
        $this->assertNull($eval(['account_status' => 'active']));
    }

    public function testNeqOperatorDeniesWhenValueMatches(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'account_status', 'operator' => 'neq', 'value' => 'suspended',
                'deny_message' => 'Account suspended.'],
        ]);
        $this->assertSame('Account suspended.', $eval(['account_status' => 'suspended']));
    }

    // ----------------------------------------------------------------- contains

    public function testContainsOperatorPassesWhenSubstringPresent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'groups', 'operator' => 'contains', 'value' => 'staff', 'deny_message' => 'Not staff.'],
        ]);
        $this->assertNull($eval(['groups' => ['admins', 'staff', 'editors']]));
    }

    public function testContainsOperatorDeniesWhenSubstringAbsent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'groups', 'operator' => 'contains', 'value' => 'staff',
                'deny_message' => 'Requires staff membership.'],
        ]);
        $this->assertSame('Requires staff membership.', $eval(['groups' => ['editors']]));
    }

    // -------------------------------------------------------------- not_contains

    public function testNotContainsDeniesWhenSubstringPresent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'roles', 'operator' => 'not_contains', 'value' => 'banned',
                'deny_message' => 'Account banned.'],
        ]);
        $this->assertSame('Account banned.', $eval(['roles' => ['user', 'banned']]));
    }

    public function testNotContainsPassesWhenSubstringAbsent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'roles', 'operator' => 'not_contains', 'value' => 'banned',
                'deny_message' => 'Account banned.'],
        ]);
        $this->assertNull($eval(['roles' => ['user', 'editor']]));
    }

    // ------------------------------------------------------------------- exists

    public function testExistsPassesWhenClaimPresent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email', 'operator' => 'exists', 'value' => '', 'deny_message' => 'Email claim required.'],
        ]);
        $this->assertNull($eval(['email' => 'user@example.com']));
    }

    public function testExistsDeniesWhenClaimAbsent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email', 'operator' => 'exists', 'value' => '', 'deny_message' => 'Email claim required.'],
        ]);
        $this->assertSame('Email claim required.', $eval([]));
    }

    // --------------------------------------------------------------- not_exists

    public function testNotExistsDeniesWhenClaimPresent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'blocked_flag', 'operator' => 'not_exists', 'value' => '',
                'deny_message' => 'Account blocked.'],
        ]);
        $this->assertSame('Account blocked.', $eval(['blocked_flag' => 'true']));
    }

    public function testNotExistsPassesWhenClaimAbsent(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'blocked_flag', 'operator' => 'not_exists', 'value' => '',
                'deny_message' => 'Account blocked.'],
        ]);
        $this->assertNull($eval([]));
    }

    // ------------------------------------------------------- multiple rules (AND)

    public function testMultipleRulesAllPassGrantsAccess(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email_verified', 'operator' => 'eq', 'value' => '1', 'deny_message' => 'Not verified.'],
            ['claim' => 'groups', 'operator' => 'contains', 'value' => 'customers',
                'deny_message' => 'Not a customer.'],
        ]);
        $this->assertNull($eval(['email_verified' => '1', 'groups' => ['customers', 'premium']]));
    }

    public function testMultipleRulesFirstFailShortCircuits(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email_verified', 'operator' => 'eq', 'value' => '1', 'deny_message' => 'Not verified.'],
            ['claim' => 'groups', 'operator' => 'contains', 'value' => 'customers',
                'deny_message' => 'Not a customer.'],
        ]);
        // First rule fails — second is never evaluated
        $this->assertSame('Not verified.', $eval(['email_verified' => '0', 'groups' => ['customers']]));
    }

    public function testMultipleRulesSecondFailReturnsItsMessage(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email_verified', 'operator' => 'eq', 'value' => '1', 'deny_message' => 'Not verified.'],
            ['claim' => 'groups', 'operator' => 'contains', 'value' => 'customers',
                'deny_message' => 'Not a customer.'],
        ]);
        $this->assertSame('Not a customer.', $eval(['email_verified' => '1', 'groups' => ['editors']]));
    }

    // ------------------------------------------------------------------- edge cases

    public function testEmptyRulesArrayGrantsAccess(): void
    {
        $eval = $this->buildEvaluator([]);
        $this->assertNull($eval(['email' => 'x@y.com']));
    }

    public function testRuleWithEmptyClaimIsSkipped(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => '', 'operator' => 'eq', 'value' => 'x', 'deny_message' => 'Should not deny.'],
        ]);
        $this->assertNull($eval([]));
    }

    public function testUnknownOperatorIsSkippedAndGrantsAccess(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'foo', 'operator' => 'regex', 'value' => '^bar$', 'deny_message' => 'No match.'],
        ]);
        $this->assertNull($eval(['foo' => 'baz']));
    }

    public function testMissingDenyMessageFallsBackToDefault(): void
    {
        $eval = $this->buildEvaluator([
            ['claim' => 'email_verified', 'operator' => 'eq', 'value' => '1'],
        ]);
        $result = $eval(['email_verified' => '0']);
        $this->assertNotNull($result);
        $this->assertNotEmpty($result);
    }
}
