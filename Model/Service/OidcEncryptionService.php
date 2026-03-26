<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Thin service wrapper around Magento's EncryptorInterface.
 *
 * Provides named encrypt/decrypt methods so callers do not need to depend
 * directly on the framework encryptor interface.
 */
class OidcEncryptionService
{
    /**
     * @param EncryptorInterface $encryptor Magento encryption service
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Encrypt a plaintext value using Magento's encryption key.
     *
     * @param  string $value Plaintext value to encrypt
     * @return string        Encrypted ciphertext
     */
    public function encrypt(string $value): string
    {
        return $this->encryptor->encrypt($value);
    }

    /**
     * Decrypt a ciphertext value using Magento's encryption key.
     *
     * @param  string $value Ciphertext to decrypt
     * @return string        Decrypted plaintext
     */
    public function decrypt(string $value): string
    {
        return $this->encryptor->decrypt($value);
    }
}
