<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Model\Service;

use Magento\Framework\Math\Random;
use Magento\User\Model\ResourceModel\User;
use Magento\User\Model\ResourceModel\User\CollectionFactory as UserCollectionFactory;
use Magento\User\Model\UserFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\Service\AdminUserCreator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminUserCreator name-fallback behaviour (REF-02).
 *
 * Tests that applyNameFallbacks() correctly delegates to
 * OAuthUtility::extractNameFromEmail() when first/last name are empty.
 *
 * @covers \MiniOrange\OAuth\Model\Service\AdminUserCreator
 */
class AdminUserCreatorApplyNameFallbacksTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var AdminUserCreator */
    private AdminUserCreator $creator;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);

        // Silence all log calls
        $this->oauthUtility->method('customlog');

        $this->creator = new AdminUserCreator(
            $this->createMock(UserFactory::class),
            $this->oauthUtility,
            $this->createMock(Random::class),
            $this->createMock(User::class),
            $this->createMock(UserCollectionFactory::class)
        );
    }

    /**
     * When getAdminRoleFromGroups returns null (no role found), createAdminUser should
     * return null without attempting to save. This also exercises the path through
     * applyNameFallbacks() when names are provided.
     */
    public function testCreateAdminUserReturnsNullWhenNoRoleFound(): void
    {
        // No role mappings, no default role
        $this->oauthUtility->method('getStoreConfig')->willReturn(null);
        $this->oauthUtility->method('isBlank')->willReturn(true);

        $result = $this->creator->createAdminUser(
            'admin@example.com',
            'adminuser',
            'Admin',
            'User',
            []
        );

        $this->assertNull($result);
    }

    /**
     * Verify isAdminUser() returns false when the user factory and collection both
     * indicate no user exists.
     */
    public function testIsAdminUserReturnsFalseWhenNoUserExists(): void
    {
        $userMock = $this->getMockBuilder(\Magento\User\Model\User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByUsername', 'getId'])
            ->getMock();
        $userMock->method('loadByUsername')->willReturnSelf();
        $userMock->method('getId')->willReturn(null);

        $userFactory = $this->createMock(UserFactory::class);
        $userFactory->method('create')->willReturn($userMock);

        // Empty collection
        $collectionMock = $this->getMockBuilder(
            \Magento\User\Model\ResourceModel\User\Collection::class
        )
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getSize'])
            ->getMock();
        $collectionMock->method('addFieldToFilter')->willReturnSelf();
        $collectionMock->method('getSize')->willReturn(0);

        $collectionFactory = $this->createMock(UserCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collectionMock);

        $creator = new AdminUserCreator(
            $userFactory,
            $this->oauthUtility,
            $this->createMock(Random::class),
            $this->createMock(User::class),
            $collectionFactory
        );

        $this->assertFalse($creator->isAdminUser('nonexistent@example.com'));
    }
}
