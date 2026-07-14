<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\Math\Random;

/**
 * Generates random placeholder passwords for SSO-provisioned users.
 *
 * Shared by AdminUserCreator and CustomerUserCreator so both use the same
 * character-class guarantees: 28 alphanumeric characters plus at least
 * 2 special characters and 2 digits, shuffled to avoid a predictable
 * character-class ordering. Total length: 32 characters.
 *
 * Stateless; safe to share between consumers.
 */
class RandomPasswordGenerator
{
    /** Number of characters drawn from Magento's default alphanumeric set */
    private const ALPHANUMERIC_LENGTH = 28;

    /** Number of guaranteed special characters */
    private const SPECIAL_LENGTH = 2;

    /** Special-character pool */
    private const SPECIAL_CHARS = '!@#$%^&*';

    /** Number of guaranteed digits */
    private const DIGIT_LENGTH = 2;

    /** Digit pool */
    private const DIGIT_CHARS = '0123456789';

    /**
     * @param Random $randomUtility
     */
    public function __construct(
        private readonly Random $randomUtility
    ) {
    }

    /**
     * Generate a 32-character random password.
     *
     * All characters are drawn from Magento's CSPRNG-backed Random utility;
     * str_shuffle() only permutes the already-random characters so that the
     * guaranteed special/digit characters do not sit at predictable positions.
     */
    public function generate(): string
    {
        return str_shuffle(
            $this->randomUtility->getRandomString(self::ALPHANUMERIC_LENGTH)
            . $this->randomUtility->getRandomString(self::SPECIAL_LENGTH, self::SPECIAL_CHARS)
            . $this->randomUtility->getRandomString(self::DIGIT_LENGTH, self::DIGIT_CHARS)
        );
    }
}
