<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use M2Oidc\OAuth\Setup\Patch\Data\EncryptPlaintextClientSecrets;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the C-02 data patch.
 *
 * Verifies that:
 *  - plain-text client secrets are encrypted in place
 *  - secrets already in Magento's encryption envelope (N:N:...) are untouched
 *  - empty/NULL login_type values are backfilled to 'both'
 *  - rows needing no change trigger no UPDATE
 *
 * @covers \M2Oidc\OAuth\Setup\Patch\Data\EncryptPlaintextClientSecrets
 */
class EncryptPlaintextClientSecretsTest extends TestCase
{
    /** @var ModuleDataSetupInterface&MockObject */
    private ModuleDataSetupInterface $moduleDataSetup;

    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;

    /** @var EncryptorInterface&MockObject */
    private EncryptorInterface $encryptor;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var EncryptPlaintextClientSecrets */
    private EncryptPlaintextClientSecrets $patch;

    /**
     * Captured update() calls: list of [table, bind, where].
     *
     * @var array<int, array{string, mixed[], mixed[]}>
     */
    private array $updates = [];

    protected function setUp(): void
    {
        $this->connection      = $this->createMock(AdapterInterface::class);
        $this->moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $this->encryptor       = $this->createMock(EncryptorInterface::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->updates         = [];

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('update')
            ->willReturnCallback(function (string $table, array $bind, array $where): int {
                $this->updates[] = [$table, $bind, $where];
                return 1;
            });

        $this->moduleDataSetup->method('getConnection')->willReturn($this->connection);
        $this->moduleDataSetup->method('getTable')->willReturnArgument(0);

        $this->encryptor->method('encrypt')
            ->willReturnCallback(static fn (string $value): string => '0:3:' . base64_encode($value));

        $this->patch = new EncryptPlaintextClientSecrets(
            $this->moduleDataSetup,
            $this->encryptor,
            $this->logger
        );
    }

    public function testPlaintextSecretIsEncryptedInPlace(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['id' => 1, 'client_secret' => 'plain-secret', 'login_type' => 'customer'],
        ]);

        $this->patch->apply();

        $this->assertCount(1, $this->updates);
        [$table, $bind, $where] = $this->updates[0];
        $this->assertSame('m2oidc_oauth_client_apps', $table);
        $this->assertSame(['client_secret' => '0:3:' . base64_encode('plain-secret')], $bind);
        $this->assertSame(['id = ?' => 1], $where);
    }

    public function testAlreadyEncryptedSecretIsLeftUntouched(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['id' => 2, 'client_secret' => '0:3:already-encrypted==', 'login_type' => 'both'],
            ['id' => 3, 'client_secret' => '12:2:other-envelope==', 'login_type' => 'admin'],
        ]);

        $this->encryptor->expects($this->never())->method('encrypt');

        $this->patch->apply();

        $this->assertSame([], $this->updates, 'Encrypted rows must not be updated');
    }

    public function testEmptySecretIsLeftUntouched(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['id' => 4, 'client_secret' => '', 'login_type' => 'customer'],
            ['id' => 5, 'client_secret' => null, 'login_type' => 'both'],
        ]);

        $this->encryptor->expects($this->never())->method('encrypt');

        $this->patch->apply();

        $this->assertSame([], $this->updates, 'Rows without a secret must not be updated');
    }

    public function testEmptyLoginTypeIsBackfilledToBoth(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['id' => 6, 'client_secret' => '0:3:encrypted==', 'login_type' => ''],
            ['id' => 7, 'client_secret' => '', 'login_type' => null],
        ]);

        $this->patch->apply();

        $this->assertCount(2, $this->updates);
        $this->assertSame(['login_type' => 'both'], $this->updates[0][1]);
        $this->assertSame(['id = ?' => 6], $this->updates[0][2]);
        $this->assertSame(['login_type' => 'both'], $this->updates[1][1]);
        $this->assertSame(['id = ?' => 7], $this->updates[1][2]);
    }

    public function testPlaintextSecretAndEmptyLoginTypeAreFixedInOneUpdate(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['id' => 8, 'client_secret' => 'legacy-secret', 'login_type' => ''],
        ]);

        $this->patch->apply();

        $this->assertCount(1, $this->updates);
        $this->assertSame(
            [
                'client_secret' => '0:3:' . base64_encode('legacy-secret'),
                'login_type'    => 'both',
            ],
            $this->updates[0][1]
        );
    }

    public function testApplyWrapsWorkInSetupAndLogsSummary(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->moduleDataSetup->expects($this->once())->method('startSetup');
        $this->moduleDataSetup->expects($this->once())->method('endSetup');
        $this->logger->expects($this->once())->method('info');

        $this->assertSame($this->patch, $this->patch->apply());
    }

    public function testPatchDeclaresNoDependenciesOrAliases(): void
    {
        $this->assertSame([], EncryptPlaintextClientSecrets::getDependencies());
        $this->assertSame([], $this->patch->getAliases());
    }
}
