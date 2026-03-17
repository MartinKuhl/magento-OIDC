<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Auth;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\AuthenticationException;
use Magento\User\Model\ResourceModel\User as UserResourceModel;
use Magento\User\Model\ResourceModel\User\Collection as UserCollection;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OidcCredentialAdapter authentication flow (Phase 1.1).
 *
 * @covers \M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter
 */
class OidcCredentialAdapterTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var ManagerInterface&MockObject */
    private ManagerInterface $eventManager;

    /** @var UserFactory&MockObject */
    private UserFactory $userFactory;

    /** @var UserResourceModel&MockObject */
    private UserResourceModel $userResource;

    /** @var UserCollectionFactory&MockObject */
    private UserCollectionFactory $userCollectionFactory;

    /** @var OAuthSecurityHelper&MockObject */
    private OAuthSecurityHelper $securityHelper;

    /** @var OidcCredentialAdapter */
    private OidcCredentialAdapter $adapter;

    protected function setUp(): void
    {
        $this->oauthUtility          = $this->createMock(OAuthUtility::class);
        $this->eventManager          = $this->createMock(ManagerInterface::class);
        $this->userFactory           = $this->createMock(UserFactory::class);
        $this->userResource          = $this->createMock(UserResourceModel::class);
        $this->userCollectionFactory = $this->createMock(UserCollectionFactory::class);
        $this->securityHelper        = $this->createMock(OAuthSecurityHelper::class);
        $this->securityHelper->method('validateAndConsumeOidcAuthToken')
            ->willReturnCallback(
                static fn(string $username, string $password): bool
                    => $password === OidcCredentialAdapter::OIDC_TOKEN_MARKER
            );

        $this->oauthUtility->method('customlog');

        $this->adapter = new OidcCredentialAdapter(
            $this->userFactory,
            $this->eventManager,
            $this->oauthUtility,
            $this->userResource,
            $this->userCollectionFactory,
            $this->securityHelper
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an active User mock with an assigned role.
     */
    private function makeActiveUserMock(int $id = 1): User&MockObject
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIsActive', 'hasAssigned2Role', 'getUsername'])
            ->getMock();

        $user->method('getId')->willReturn($id);
        $user->method('getIsActive')->willReturn(1);
        $user->method('hasAssigned2Role')->willReturn([['role_id' => 1]]);
        $user->method('getUsername')->willReturn('admin');

        return $user;
    }

    /**
     * Configure userCollectionFactory to return a collection with one user.
     */
    private function singleUserCollection(User $user): void
    {
        $collection = $this->getMockBuilder(UserCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize', 'getFirstItem'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getFirstItem')->willReturn($user);

        $this->userCollectionFactory->method('create')->willReturn($collection);
    }

    /**
     * Configure userCollectionFactory to return an empty collection.
     */
    private function emptyUserCollection(): void
    {
        $collection = $this->getMockBuilder(UserCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);

        $this->userCollectionFactory->method('create')->willReturn($collection);
    }

    // -------------------------------------------------------------------------
    // authenticate() — success path
    // -------------------------------------------------------------------------

    public function testAuthenticateSucceedsWithValidMarker(): void
    {
        $user = $this->makeActiveUserMock();
        $this->singleUserCollection($user);

        $result = $this->adapter->authenticate('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);

        $this->assertTrue($result);
    }

    public function testAuthenticateDispatchesBeforeAndAfterEvents(): void
    {
        $user = $this->makeActiveUserMock();
        $this->singleUserCollection($user);

        $calls = [];
        $this->eventManager->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (string $event, array $data) use (&$calls): void {
                $calls[] = ['event' => $event, 'data' => $data];
            });

        $this->adapter->authenticate('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);

        $this->assertSame('admin_user_authenticate_before', $calls[0]['event']);
        $this->assertArrayHasKey('oidc_auth', $calls[0]['data']);
        $this->assertSame('admin_user_authenticate_after', $calls[1]['event']);
        $this->assertArrayHasKey('oidc_auth', $calls[1]['data']);
    }

    // -------------------------------------------------------------------------
    // authenticate() — failure paths
    // -------------------------------------------------------------------------

    public function testAuthenticateThrowsForWrongMarker(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->adapter->authenticate('admin@example.com', 'WRONG_PASSWORD');
    }

    public function testAuthenticateThrowsForUnknownEmail(): void
    {
        $this->emptyUserCollection();

        $this->expectException(AuthenticationException::class);

        $this->adapter->authenticate('nobody@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);
    }

    public function testAuthenticateThrowsForInactiveUser(): void
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIsActive'])
            ->getMock();
        $user->method('getId')->willReturn(5);
        $user->method('getIsActive')->willReturn(0);

        $this->singleUserCollection($user);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/inactive/i');

        $this->adapter->authenticate('inactive@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);
    }

    public function testAuthenticateThrowsForUserWithNoRole(): void
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getIsActive', 'hasAssigned2Role'])
            ->getMock();
        $user->method('getId')->willReturn(6);
        $user->method('getIsActive')->willReturn(1);
        $user->method('hasAssigned2Role')->willReturn([]);  // empty = no role

        $this->singleUserCollection($user);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/role/i');

        $this->adapter->authenticate('norole@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);
    }

    // -------------------------------------------------------------------------
    // login()
    // -------------------------------------------------------------------------

    public function testLoginRecordsLoginAndReloadsUser(): void
    {
        $user = $this->makeActiveUserMock(10);
        $this->singleUserCollection($user);

        // userResource::recordLogin must be called once
        $this->userResource->expects($this->once())->method('recordLogin')->with($user);

        // userFactory::create is called by reload()
        $reloadedUser = $this->makeActiveUserMock(10);
        $this->userFactory->method('create')->willReturn($reloadedUser);
        $this->userResource->method('load');

        $result = $this->adapter->login('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);

        $this->assertSame($this->adapter, $result, 'login() should return $this');
    }

    // -------------------------------------------------------------------------
    // hasAvailableResources
    // -------------------------------------------------------------------------

    public function testHasAvailableResourcesDefaultsFalse(): void
    {
        $this->assertFalse($this->adapter->hasAvailableResources());
    }

    public function testSetHasAvailableResourcesReturnsSelf(): void
    {
        $result = $this->adapter->setHasAvailableResources(true);
        $this->assertSame($this->adapter, $result);
        $this->assertTrue($this->adapter->hasAvailableResources());
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    public function testSleepOnlySerializesUserAndHasAvailableResources(): void
    {
        $properties = $this->adapter->__sleep();

        $this->assertSame(['user', 'hasAvailableResources'], $properties);
    }

    public function testWakeupDoesNotCallObjectManager(): void
    {
        // __wakeup() is intentionally empty; dependencies are restored lazily.
        // We verify it runs without error and leaves DI properties null.
        $adapter = $this->getMockBuilder(OidcCredentialAdapter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['restoreDependencies'])
            ->getMock();

        // __wakeup() must NOT call restoreDependencies eagerly
        $adapter->expects($this->never())->method('restoreDependencies');

        $adapter->__wakeup();
    }

    public function testRestoreDependenciesSkipsWhenUserFactoryAlreadySet(): void
    {
        // The real adapter was constructed with dependencies — restoreDependencies()
        // should be a no-op (guard: if userFactory !== null, return early).
        // We verify by checking that our already-constructed adapter can still
        // call authenticate() without hitting ObjectManager.
        $user = $this->makeActiveUserMock();
        $this->singleUserCollection($user);

        // If ObjectManager were called it would fail since we're in unit test context.
        // The test passes if no error is thrown.
        $result = $this->adapter->authenticate('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // __call() proxy
    // -------------------------------------------------------------------------

    public function testCallProxiesToUserModelAfterAuthenticate(): void
    {
        $user = $this->makeActiveUserMock(7);
        $this->singleUserCollection($user);

        $this->adapter->authenticate('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);

        // Calling getUsername() should be proxied to the user mock
        $username = $this->adapter->__call('getUsername', []);
        $this->assertSame('admin', $username);
    }

    public function testCallThrowsBadMethodCallExceptionWhenUserNotLoaded(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/authenticate/i');

        // No authenticate() called → user is null → __call() must throw
        $this->adapter->__call('getSomeMethod', []);
    }

    // -------------------------------------------------------------------------
    // getIsActive() / getId() / getUser()
    // -------------------------------------------------------------------------

    public function testGetIsActiveReturnsFalseWhenUserNotLoaded(): void
    {
        $this->assertFalse($this->adapter->getIsActive());
    }

    public function testGetIsActiveReturnsTrueAfterAuthenticate(): void
    {
        $user = $this->makeActiveUserMock();
        $this->singleUserCollection($user);

        $this->adapter->authenticate('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);

        $this->assertTrue($this->adapter->getIsActive());
    }

    public function testGetIdReturnsNullBeforeAuthenticate(): void
    {
        $this->assertNull($this->adapter->getId());
    }

    public function testGetIdReturnsUserIdAfterAuthenticate(): void
    {
        $user = $this->makeActiveUserMock(99);
        $this->singleUserCollection($user);

        $this->adapter->authenticate('admin@example.com', OidcCredentialAdapter::OIDC_TOKEN_MARKER);

        $this->assertSame(99, $this->adapter->getId());
    }

    public function testGetUserReturnsNullBeforeAuthenticate(): void
    {
        $this->assertNull($this->adapter->getUser());
    }
}
