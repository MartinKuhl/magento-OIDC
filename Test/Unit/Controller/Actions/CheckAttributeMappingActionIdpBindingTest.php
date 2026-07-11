<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Controller\Actions;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\User\Model\User as AdminUser;
use Magento\User\Model\UserFactory;
use M2Oidc\OAuth\Controller\Actions\CheckAttributeMappingAction;
use M2Oidc\OAuth\Controller\Actions\ProcessUserAction;
use M2Oidc\OAuth\Controller\Actions\ShowTestResults;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Data\OidcAttributeMappingContext;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Model\Service\AdminProfileSyncService;
use M2Oidc\OAuth\Model\Service\AdminUserCreator;
use M2Oidc\OAuth\Model\Service\OidcAuthenticationService;
use M2Oidc\OAuth\Model\Service\UserProvisioningService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CheckAttributeMappingAction — IdP binding logic (admin path) only.
 *
 * Verifies:
 *  - Admin bound to the same provider is allowed through to the callback redirect
 *  - Admin bound to a different provider is rejected with an error redirect
 *  - Pre-existing admin with no binding gets a new binding created on first OIDC login
 *
 * @covers \M2Oidc\OAuth\Controller\Actions\CheckAttributeMappingAction
 */
class CheckAttributeMappingActionIdpBindingTest extends TestCase
{
    private const CURRENT_PROVIDER_ID = 5;
    private const OTHER_PROVIDER_ID   = 77;
    private const ADMIN_USER_ID       = 10;
    private const ADMIN_EMAIL         = 'admin@example.com';

    /** @var Context&MockObject */
    private Context $context;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var ProcessUserAction&MockObject */
    private ProcessUserAction $processUserAction;

    /** @var UserFactory&MockObject */
    private UserFactory $userFactory;

    /** @var BackendUrlInterface&MockObject */
    private BackendUrlInterface $backendUrl;

    /** @var AdminUserCreator&MockObject */
    private AdminUserCreator $adminUserCreator;

    /** @var CustomerSession&MockObject */
    private CustomerSession $customerSession;

    /** @var ShowTestResults&MockObject */
    private ShowTestResults $testAction;

    /** @var OAuthSecurityHelper&MockObject */
    private OAuthSecurityHelper $securityHelper;

    /** @var CookieManagerInterface&MockObject */
    private CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory&MockObject */
    private CookieMetadataFactory $cookieMetadataFactory;

    /** @var UserProvisioningService&MockObject */
    private UserProvisioningService $userProvisioningService;

    /** @var AdminProfileSyncService&MockObject */
    private AdminProfileSyncService $adminProfileSyncService;

    /** @var OidcAuthenticationService&MockObject */
    private OidcAuthenticationService $oidcAuthenticationService;

    /** @var UserProviderResource&MockObject */
    private UserProviderResource $userProviderResource;

    /** @var AdminUser&MockObject */
    private AdminUser $adminUser;

    /** @var RedirectFactory&MockObject */
    private RedirectFactory $redirectFactory;

    /** @var MessageManagerInterface&MockObject */
    private MessageManagerInterface $messageManager;

    protected function setUp(): void
    {
        $this->oauthUtility            = $this->createMock(OAuthUtility::class);
        $this->processUserAction       = $this->createMock(ProcessUserAction::class);
        $this->userFactory             = $this->createMock(UserFactory::class);
        $this->backendUrl              = $this->createMock(BackendUrlInterface::class);
        $this->adminUserCreator        = $this->createMock(AdminUserCreator::class);
        $this->customerSession         = $this->createMock(CustomerSession::class);
        $this->testAction              = $this->createMock(ShowTestResults::class);
        $this->securityHelper          = $this->createMock(OAuthSecurityHelper::class);
        $this->cookieManager           = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactory   = $this->createMock(CookieMetadataFactory::class);
        $this->userProvisioningService  = $this->createMock(UserProvisioningService::class);
        $this->adminProfileSyncService  = $this->createMock(AdminProfileSyncService::class);
        $this->oidcAuthenticationService = $this->createMock(OidcAuthenticationService::class);
        $this->userProviderResource    = $this->createMock(UserProviderResource::class);
        $this->messageManager          = $this->createMock(MessageManagerInterface::class);
        $this->redirectFactory         = $this->createMock(RedirectFactory::class);

        $this->adminUser = $this->createMock(AdminUser::class);
        $this->adminUser->method('getId')->willReturn((string) self::ADMIN_USER_ID);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('customlogContext');

        // Default attribute config stubs
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::MAP_EMAIL,     null, 'email'],
            [OAuthConstants::MAP_USERNAME,  null, 'preferred_username'],
            [OAuthConstants::MAP_FIRSTNAME, null, 'given_name'],
            [OAuthConstants::MAP_LASTNAME,  null, 'family_name'],
            [OAuthConstants::MAP_GROUP,     null, 'groups'],
            [OAuthConstants::AUTO_CREATE_ADMIN, null, '0'],
            [OAuthConstants::SYNC_ADMIN_PROFILE_ON_SSO, null, '0'],
            [OAuthConstants::SYNC_ADMIN_ROLE_ON_SSO, null, '0'],
        ]);

        // Context wiring
        $this->context = $this->createMock(Context::class);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getResultRedirectFactory')->willReturn($this->redirectFactory);
        $this->context->method('getEventManager')->willReturn(
            $this->createMock(EventManagerInterface::class)
        );
        $this->context->method('getRequest')->willReturn(
            $this->createMock(\Magento\Framework\App\RequestInterface::class)
        );
        $this->context->method('getResultFactory')->willReturn(
            $this->createMock(\Magento\Framework\Controller\ResultFactory::class)
        );

        // Cookie metadata
        $meta = $this->createMock(PublicCookieMetadata::class);
        $meta->method('setPath')->willReturnSelf();
        $meta->method('setHttpOnly')->willReturnSelf();
        $meta->method('setSecure')->willReturnSelf();
        $meta->method('setDuration')->willReturnSelf();
        $meta->method('setSameSite')->willReturnSelf();
        $this->cookieMetadataFactory->method('createPublicCookieMetadata')->willReturn($meta);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAction(): CheckAttributeMappingAction
    {
        return new CheckAttributeMappingAction(
            $this->context,
            $this->oauthUtility,
            $this->processUserAction,
            $this->userFactory,
            $this->backendUrl,
            $this->adminUserCreator,
            $this->customerSession,
            $this->testAction,
            $this->securityHelper,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->userProvisioningService,
            $this->adminProfileSyncService,
            $this->oidcAuthenticationService,
            $this->userProviderResource
        );
    }

    /**
     * Build a CheckAttributeMappingAction ready for an admin login flow.
     */
    private function buildActionForAdminLogin(): CheckAttributeMappingAction
    {
        return $this->buildAction();
    }

    /**
     * Build the OidcAttributeMappingContext for an admin login flow — replaces the
     * former setUserInfoResponse()/setFlattenedUserInfoResponse()/setUserEmail()/
     * setLoginType()/setClientDetails() setter chain.
     */
    private function buildAdminLoginContext(): OidcAttributeMappingContext
    {
        $attrs = [
            'email'      => self::ADMIN_EMAIL,
            'relayState' => 'https://store.example.com/admin/',
        ];
        $flattenedAttrs = [
            'email'              => self::ADMIN_EMAIL,
            'preferred_username' => 'admin_user',
            'given_name'         => 'Admin',
            'family_name'        => 'User',
        ];

        return new OidcAttributeMappingContext(
            $attrs,
            $flattenedAttrs,
            self::ADMIN_EMAIL,
            OAuthConstants::LOGIN_TYPE_ADMIN,
            false,
            [
                'id'                  => self::CURRENT_PROVIDER_ID,
                'endsession_endpoint' => '',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Test 1 – Admin bound to the same provider → redirect to admin callback
    // -------------------------------------------------------------------------

    public function testAdminBoundToSameProviderAllowed(): void
    {
        $this->adminUserCreator->method('isAdminUser')->with(self::ADMIN_EMAIL)->willReturn(true);
        $this->adminUserCreator->method('getAdminUserByEmail')->with(self::ADMIN_EMAIL)->willReturn($this->adminUser);

        $this->userProviderResource
            ->method('getBoundProviderId')
            ->with('admin', self::ADMIN_USER_ID)
            ->willReturn(self::CURRENT_PROVIDER_ID);

        // Set up nonce and redirect to admin callback
        $this->securityHelper->method('createAdminLoginNonce')->willReturn('test-nonce-abc');
        $this->backendUrl->method('getAreaFrontName')->willReturn('admin');
        $this->backendUrl->method('getUrl')->with('m2oidc/actions/oidccallback')->willReturn(
            'https://store.example.com/admin/m2oidc/actions/oidccallback/key/abc/'
        );

        $callbackRedirect = $this->createMock(Redirect::class);
        $callbackRedirect->method('setUrl')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($callbackRedirect);

        $action = $this->buildActionForAdminLogin();
        $result = $action->handle($this->buildAdminLoginContext());

        // Must redirect (not an error result)
        $this->assertSame($callbackRedirect, $result);
    }

    // -------------------------------------------------------------------------
    // Test 2 – Admin bound to a different provider → rejected with error redirect
    // -------------------------------------------------------------------------

    public function testAdminBoundToDifferentProviderRejected(): void
    {
        $this->adminUserCreator->method('isAdminUser')->with(self::ADMIN_EMAIL)->willReturn(true);
        $this->adminUserCreator->method('getAdminUserByEmail')->with(self::ADMIN_EMAIL)->willReturn($this->adminUser);

        $this->userProviderResource
            ->method('getBoundProviderId')
            ->with('admin', self::ADMIN_USER_ID)
            ->willReturn(self::OTHER_PROVIDER_ID);

        // saveMapping must NOT be called
        $this->userProviderResource->expects($this->never())->method('saveMapping');

        $this->backendUrl->method('getUrl')->with('admin')->willReturn(
            'https://store.example.com/admin/'
        );

        $errorRedirect = $this->createMock(Redirect::class);
        $errorRedirect->method('setUrl')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($errorRedirect);

        // addErrorMessage is called with the mismatch message
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $action = $this->buildActionForAdminLogin();
        $result = $action->handle($this->buildAdminLoginContext());

        $this->assertSame($errorRedirect, $result);
    }

    // -------------------------------------------------------------------------
    // Test 3 – Unbound admin → saveMapping called on first OIDC login
    // -------------------------------------------------------------------------

    public function testUnboundAdminFirstLoginCreatesBinding(): void
    {
        $this->adminUserCreator->method('isAdminUser')->with(self::ADMIN_EMAIL)->willReturn(true);
        $this->adminUserCreator->method('getAdminUserByEmail')->with(self::ADMIN_EMAIL)->willReturn($this->adminUser);

        $this->userProviderResource
            ->method('getBoundProviderId')
            ->with('admin', self::ADMIN_USER_ID)
            ->willReturn(null);

        // saveMapping MUST be called once to create the binding
        $this->userProviderResource->expects($this->once())
            ->method('saveMapping')
            ->with('admin', self::ADMIN_USER_ID, self::CURRENT_PROVIDER_ID);

        $this->securityHelper->method('createAdminLoginNonce')->willReturn('nonce-xyz');
        $this->backendUrl->method('getAreaFrontName')->willReturn('admin');
        $this->backendUrl->method('getUrl')->with('m2oidc/actions/oidccallback')->willReturn(
            'https://store.example.com/admin/m2oidc/actions/oidccallback/'
        );

        $callbackRedirect = $this->createMock(Redirect::class);
        $callbackRedirect->method('setUrl')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($callbackRedirect);

        $action = $this->buildActionForAdminLogin();
        $result = $action->handle($this->buildAdminLoginContext());

        $this->assertSame($callbackRedirect, $result);
    }
}
