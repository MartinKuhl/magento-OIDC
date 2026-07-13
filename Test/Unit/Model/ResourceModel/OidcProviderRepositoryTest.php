<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\ResourceModel;

use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\Collection;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory as ClientCollectionFactory;
use M2Oidc\OAuth\Model\ResourceModel\OidcProviderRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OidcProviderRepository (H10d, M22).
 *
 * H10d: getAllActiveProviders() must treat an empty/NULL `login_type` as a
 * wildcard — such a row matches any requested login type, not just 'both'.
 *
 * M22: every client_secret decrypt call site must log a WARNING when a
 * non-empty ciphertext (matching the `^\d+:\d+:` envelope) decrypts to an
 * empty string (a silent decryption failure).
 *
 * @covers \M2Oidc\OAuth\Model\ResourceModel\OidcProviderRepository
 */
class OidcProviderRepositoryTest extends TestCase
{
    private const ENCRYPTED_SECRET = '0:2:abcdefghijklmnop==';

    /** @var M2oidcOauthClientAppsFactory&MockObject */
    private M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var ClientCollectionFactory&MockObject */
    private ClientCollectionFactory $clientCollectionFactory;

    /** @var AppResource&MockObject */
    private AppResource $appResource;

    /** @var EncryptorInterface&MockObject */
    private EncryptorInterface $encryptor;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var OidcProviderRepository */
    private OidcProviderRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->clientAppsFactory = $this->createMock(M2oidcOauthClientAppsFactory::class);
        $this->clientCollectionFactory = $this->createMock(ClientCollectionFactory::class);
        $this->appResource = $this->createMock(AppResource::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->repository = new OidcProviderRepository(
            $this->clientAppsFactory,
            $this->clientCollectionFactory,
            $this->appResource,
            $this->encryptor,
            $this->logger
        );
    }

    /**
     * Build a mocked Collection whose iteration yields the given DataObject rows.
     *
     * @param DataObject[] $items
     */
    private function makeCollection(array $items): Collection
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'setOrder', 'getIterator'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        return $collection;
    }

    // -------------------------------------------------------------------------
    // H10d — empty/NULL login_type is a wildcard
    // -------------------------------------------------------------------------

    public function testGetAllActiveProvidersTreatsEmptyLoginTypeAsWildcardForCustomer(): void
    {
        $wildcard = new DataObject(['id' => 1, 'login_type' => '', 'sort_order' => 1]);
        $admin    = new DataObject(['id' => 2, 'login_type' => 'admin', 'sort_order' => 2]);
        $customer = new DataObject(['id' => 3, 'login_type' => 'customer', 'sort_order' => 3]);

        $this->clientCollectionFactory->method('create')
            ->willReturn($this->makeCollection([$wildcard, $admin, $customer]));

        $result = $this->repository->getAllActiveProviders('customer');

        $ids = array_column($result, 'id');
        $this->assertSame([1, 3], $ids, 'Empty login_type row must match "customer"; "admin" row must not');
    }

    public function testGetAllActiveProvidersTreatsEmptyLoginTypeAsWildcardForAdmin(): void
    {
        $wildcard = new DataObject(['id' => 1, 'login_type' => '', 'sort_order' => 1]);
        $customer = new DataObject(['id' => 2, 'login_type' => 'customer', 'sort_order' => 2]);

        $this->clientCollectionFactory->method('create')
            ->willReturn($this->makeCollection([$wildcard, $customer]));

        $result = $this->repository->getAllActiveProviders('admin');

        $ids = array_column($result, 'id');
        $this->assertSame([1], $ids, 'Empty login_type row must match "admin"; "customer" row must not');
    }

    public function testGetAllActiveProvidersMatchesBothExplicitly(): void
    {
        $both = new DataObject(['id' => 1, 'login_type' => 'both', 'sort_order' => 1]);

        $this->clientCollectionFactory->method('create')
            ->willReturn($this->makeCollection([$both]));

        $result = $this->repository->getAllActiveProviders('admin');

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['id']);
    }

    // -------------------------------------------------------------------------
    // M22 — decrypt logging
    // -------------------------------------------------------------------------

    public function testGetAllActiveProvidersLogsWarningWhenDecryptReturnsEmptyString(): void
    {
        $row = new DataObject(['id' => 1, 'login_type' => 'both', 'client_secret' => self::ENCRYPTED_SECRET]);

        $this->clientCollectionFactory->method('create')->willReturn($this->makeCollection([$row]));
        $this->encryptor->method('decrypt')->with(self::ENCRYPTED_SECRET)->willReturn('');

        $this->logger->expects($this->once())->method('warning');

        $result = $this->repository->getAllActiveProviders('customer');

        $this->assertSame('', $result[0]['client_secret']);
    }

    public function testGetAllActiveProvidersDoesNotLogWhenDecryptSucceeds(): void
    {
        $row = new DataObject(['id' => 1, 'login_type' => 'both', 'client_secret' => self::ENCRYPTED_SECRET]);

        $this->clientCollectionFactory->method('create')->willReturn($this->makeCollection([$row]));
        $this->encryptor->method('decrypt')->with(self::ENCRYPTED_SECRET)->willReturn('plain-secret');

        $this->logger->expects($this->never())->method('warning');

        $result = $this->repository->getAllActiveProviders('customer');

        $this->assertSame('plain-secret', $result[0]['client_secret']);
    }

    public function testGetClientDetailsByIdLogsWarningWhenDecryptReturnsEmptyString(): void
    {
        $row = new DataObject(['id' => 5, 'client_secret' => self::ENCRYPTED_SECRET]);

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($row);

        $this->clientCollectionFactory->method('create')->willReturn($collection);
        $this->encryptor->method('decrypt')->with(self::ENCRYPTED_SECRET)->willReturn('');

        $this->logger->expects($this->once())->method('warning');

        $result = $this->repository->getClientDetailsById(5);

        $this->assertNotNull($result);
        $this->assertSame('', $result['client_secret']);
    }

    public function testGetClientDetailsByAppNameLogsWarningWhenDecryptReturnsEmptyString(): void
    {
        $row = new DataObject(['id' => 5, 'app_name' => 'okta', 'client_secret' => self::ENCRYPTED_SECRET]);

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($row);

        $this->clientCollectionFactory->method('create')->willReturn($collection);
        $this->encryptor->method('decrypt')->with(self::ENCRYPTED_SECRET)->willReturn('');

        $this->logger->expects($this->once())->method('warning');

        $result = $this->repository->getClientDetailsByAppName('okta');

        $this->assertNotNull($result);
        $this->assertSame('', $result['client_secret']);
    }

    public function testGetClientDetailsByIdDoesNotDecryptPlaintextSecret(): void
    {
        $row = new DataObject(['id' => 5, 'client_secret' => 'plaintext-secret']);

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($row);

        $this->clientCollectionFactory->method('create')->willReturn($collection);
        $this->encryptor->expects($this->never())->method('decrypt');

        $result = $this->repository->getClientDetailsById(5);

        $this->assertSame('plaintext-secret', $result['client_secret']);
    }

    // -------------------------------------------------------------------------
    // public_client — stale ciphertext must never be decrypted or warned about
    // -------------------------------------------------------------------------

    public function testGetAllActiveProvidersSkipsDecryptionForPublicClient(): void
    {
        $row = new DataObject([
            'id' => 3,
            'login_type' => 'both',
            'public_client' => 1,
            'client_secret' => self::ENCRYPTED_SECRET,
        ]);

        $this->clientCollectionFactory->method('create')->willReturn($this->makeCollection([$row]));
        $this->encryptor->expects($this->never())->method('decrypt');
        $this->logger->expects($this->never())->method('warning');

        $result = $this->repository->getAllActiveProviders('customer');

        $this->assertSame(self::ENCRYPTED_SECRET, $result[0]['client_secret']);
    }

    public function testGetClientDetailsByIdSkipsDecryptionForPublicClient(): void
    {
        $row = new DataObject(['id' => 3, 'public_client' => 1, 'client_secret' => self::ENCRYPTED_SECRET]);

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($row);

        $this->clientCollectionFactory->method('create')->willReturn($collection);
        $this->encryptor->expects($this->never())->method('decrypt');
        $this->logger->expects($this->never())->method('warning');

        $result = $this->repository->getClientDetailsById(3);

        $this->assertSame(self::ENCRYPTED_SECRET, $result['client_secret']);
    }

    public function testGetClientDetailsByAppNameSkipsDecryptionForPublicClient(): void
    {
        $row = new DataObject([
            'id' => 3,
            'app_name' => 'zitadel',
            'public_client' => 1,
            'client_secret' => self::ENCRYPTED_SECRET,
        ]);

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($row);

        $this->clientCollectionFactory->method('create')->willReturn($collection);
        $this->encryptor->expects($this->never())->method('decrypt');
        $this->logger->expects($this->never())->method('warning');

        $result = $this->repository->getClientDetailsByAppName('zitadel');

        $this->assertSame(self::ENCRYPTED_SECRET, $result['client_secret']);
    }
}
