<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Setup\Patch\Data;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Data patch: encrypt legacy plain-text client secrets.
 *
 * Older module versions stored `client_secret` in plain text in
 * m2oidc_oauth_client_apps. This patch encrypts every non-empty secret that
 * is not yet in Magento's encryption envelope format (`<key>:<cipher>:...`).
 *
 * It also backfills empty/NULL `login_type` values to 'both' so that legacy
 * rows keep working with the login-type aware provider selection.
 */
class EncryptPlaintextClientSecrets implements DataPatchInterface
{
    /**
     * Provider table (unprefixed).
     *
     * @var string
     */
    private const TABLE_NAME = 'm2oidc_oauth_client_apps';

    /**
     * Magento encryption envelope prefix: "<key_number>:<cipher_version>:".
     *
     * @var string
     */
    private const ENCRYPTED_PATTERN = '/^\d+:\d+:/';

    /** @var ModuleDataSetupInterface */
    private readonly ModuleDataSetupInterface $moduleDataSetup;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var LoggerInterface */
    private readonly LoggerInterface $logger;

    /**
     * Initialize data patch.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EncryptorInterface       $encryptor
     * @param LoggerInterface          $logger
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->encryptor       = $encryptor;
        $this->logger          = $logger;
    }

    /**
     * Encrypt plain-text client secrets and backfill empty login_type values.
     *
     * @return $this
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $table      = $this->moduleDataSetup->getTable(self::TABLE_NAME);

        $select = $connection->select()->from($table, ['id', 'client_secret', 'login_type']);
        $rows   = $connection->fetchAll($select);

        $encrypted  = 0;
        $backfilled = 0;

        foreach ($rows as $row) {
            $update = [];

            $secret = (string) ($row['client_secret'] ?? '');
            if ($secret !== '' && !preg_match(self::ENCRYPTED_PATTERN, $secret)) {
                $update['client_secret'] = $this->encryptor->encrypt($secret);
                $encrypted++;
            }

            $loginType = (string) ($row['login_type'] ?? '');
            if ($loginType === '') {
                $update['login_type'] = 'both';
                $backfilled++;
            }

            if ($update !== []) {
                $connection->update($table, $update, ['id = ?' => (int) $row['id']]);
            }
        }

        $this->logger->info(sprintf(
            'M2Oidc EncryptPlaintextClientSecrets: %d client secret(s) encrypted, '
            . '%d login_type value(s) backfilled to "both".',
            $encrypted,
            $backfilled
        ));

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * No dependencies on other patches.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * No aliases.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
