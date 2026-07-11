<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Controller\Actions;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use M2Oidc\OAuth\Controller\Actions\CustomerLoginAction;
use M2Oidc\OAuth\Controller\Actions\ProcessUserAction;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Data\OidcUserProvisioningContext;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Model\Service\CustomerProfileSyncService;
use M2Oidc\OAuth\Model\Service\CustomerUserCreator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProcessUserAction — IdP binding logic only.
 *
 * Verifies:
 *  - A customer bound to the current provider is allowed to log in
 *  - A customer bound to a different provider is rejected with PROVIDER_MISMATCH
 *  - A pre-existing customer with no binding gets a new binding created on first OIDC login
 *
 * @covers \M2Oidc\OAuth\Controller\Actions\ProcessUserAction
 */
class ProcessUserActionIdpBindingTest extends TestCase
{
    private const CURRENT_PROVIDER_ID = 7;
    private const OTHER_PROVIDER_ID   = 99;
    private const CUSTOMER_ID         = 42;
    private const USER_EMAIL          = 'customer@example.com';

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var CustomerRepositoryInterface&MockObject */
    private CustomerRepositoryInterface $customerRepository;

    /** @var StoreManagerInterface&MockObject */
    private StoreManagerInterface $storeManager;

    /** @var CustomerLoginAction&MockObject */
    private CustomerLoginAction $customerLoginAction;

    /** @var CustomerUserCreator&MockObject */
    private CustomerUserCreator $customerUserCreator;

    /** @var RedirectFactory&MockObject */
    private RedirectFactory $redirectFactory;

    /** @var CustomerProfileSyncService&MockObject */
    private CustomerProfileSyncService $profileSyncService;

    /** @var UserProviderResource&MockObject */
    private UserProviderResource $userProviderResource;

    /** @var CustomerInterface&MockObject */
    private CustomerInterface $customer;

    protected function setUp(): void
    {
        $this->oauthUtility        = $this->createMock(OAuthUtility::class);
        $this->customerRepository  = $this->createMock(CustomerRepositoryInterface::class);
        $this->storeManager        = $this->createMock(StoreManagerInterface::class);
        $this->customerLoginAction = $this->createMock(CustomerLoginAction::class);
        $this->customerUserCreator = $this->createMock(CustomerUserCreator::class);
        $this->redirectFactory     = $this->createMock(RedirectFactory::class);
        $this->profileSyncService  = $this->createMock(CustomerProfileSyncService::class);
        $this->userProviderResource = $this->createMock(UserProviderResource::class);

        $this->customer = $this->createMock(CustomerInterface::class);
        $this->customer->method('getId')->willReturn((string) self::CUSTOMER_ID);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('isBlank')->willReturn(false);

        // Default attribute mapping stubs
        $this->oauthUtility->method('getStoreConfig')->willReturnMap([
            [OAuthConstants::MAP_USERNAME,  null, 'preferred_username'],
            [OAuthConstants::MAP_FIRSTNAME, null, 'given_name'],
            [OAuthConstants::MAP_LASTNAME,  null, 'family_name'],
            [OAuthConstants::MAP_DEFAULT_ROLE, null, 'customer'],
            [OAuthConstants::AUTO_CREATE_CUSTOMER, null, '0'],
            [OAuthConstants::SYNC_CUSTOMER_PROFILE_ON_SSO, null, '0'],
            [OAuthConstants::SYNC_CUSTOMER_ADDRESS_ON_SSO, null, '0'],
            [OAuthConstants::UPDATE_FRONTEND_GROUPS_ON_SSO, null, '0'],
        ]);

        // Store mock
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://store.example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Default: customer lookup returns the customer mock
        $this->customerRepository
            ->method('get')
            ->with(self::USER_EMAIL)
            ->willReturn($this->customer);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildAction(): ProcessUserAction
    {
        return new ProcessUserAction(
            $this->oauthUtility,
            $this->customerRepository,
            $this->storeManager,
            $this->customerLoginAction,
            $this->customerUserCreator,
            $this->redirectFactory,
            $this->profileSyncService,
            $this->userProviderResource
        );
    }

    /**
     * Build the OidcUserProvisioningContext with the minimum attributes for a login attempt —
     * replaces the former setAttrs()/setFlattenedAttrs()/setUserEmail()/setProviderId() setter chain.
     */
    private function buildContext(): OidcUserProvisioningContext
    {
        $attrs = [
            'email'      => self::USER_EMAIL,
            'relayState' => 'https://store.example.com/',
        ];
        $flattenedAttrs = [
            'email'              => self::USER_EMAIL,
            'preferred_username' => 'customer_user',
            'given_name'         => 'Jane',
            'family_name'        => 'Doe',
        ];

        return new OidcUserProvisioningContext(
            $attrs,
            $flattenedAttrs,
            self::USER_EMAIL,
            null,
            self::CURRENT_PROVIDER_ID,
            false
        );
    }

    // -------------------------------------------------------------------------
    // Test 1 – Bound to same provider → login proceeds normally
    // -------------------------------------------------------------------------

    public function testBoundUserSameProviderIsAllowed(): void
    {
        $this->userProviderResource
            ->method('getBoundProviderId')
            ->with('customer', self::CUSTOMER_ID)
            ->willReturn(self::CURRENT_PROVIDER_ID);

        // Login action should be called (no redirect to error page)
        $redirectResult = $this->createMock(Redirect::class);
        $this->customerLoginAction->method('setUser')->willReturnSelf();
        $this->customerLoginAction->method('setRelayState')->willReturnSelf();
        $this->customerLoginAction->method('setHeadless')->willReturnSelf();
        $this->customerLoginAction->method('execute')->willReturn($redirectResult);

        $action = $this->buildAction();
        $result = $action->handle($this->buildContext());

        $this->assertSame($redirectResult, $result);
    }

    // -------------------------------------------------------------------------
    // Test 2 – Bound to different provider → redirect with PROVIDER_MISMATCH
    // -------------------------------------------------------------------------

    public function testBoundUserDifferentProviderIsRejected(): void
    {
        $this->userProviderResource
            ->method('getBoundProviderId')
            ->with('customer', self::CUSTOMER_ID)
            ->willReturn(self::OTHER_PROVIDER_ID);

        $this->oauthUtility->method('getCustomerLoginUrl')
            ->willReturn('https://store.example.com/customer/account/login');

        $errorRedirect = $this->createMock(Redirect::class);
        $errorRedirect->method('setUrl')->willReturnSelf();
        $this->redirectFactory->method('create')->willReturn($errorRedirect);

        // saveMapping must NOT be called
        $this->userProviderResource->expects($this->never())->method('saveMapping');

        $action = $this->buildAction();
        $result = $action->handle($this->buildContext());

        // The returned redirect must point to the login URL with oidc_error
        $this->assertSame($errorRedirect, $result);
    }

    // -------------------------------------------------------------------------
    // Test 3 – No binding exists → saveMapping is called to claim the binding
    // -------------------------------------------------------------------------

    public function testUnboundFirstLoginCreatesBinding(): void
    {
        $this->userProviderResource
            ->method('getBoundProviderId')
            ->with('customer', self::CUSTOMER_ID)
            ->willReturn(null);

        // saveMapping must be called once to claim the binding
        $this->userProviderResource->expects($this->once())
            ->method('saveMapping')
            ->with('customer', self::CUSTOMER_ID, self::CURRENT_PROVIDER_ID);

        $redirectResult = $this->createMock(Redirect::class);
        $this->customerLoginAction->method('setUser')->willReturnSelf();
        $this->customerLoginAction->method('setRelayState')->willReturnSelf();
        $this->customerLoginAction->method('setHeadless')->willReturnSelf();
        $this->customerLoginAction->method('execute')->willReturn($redirectResult);

        $action = $this->buildAction();
        $result = $action->handle($this->buildContext());

        $this->assertSame($redirectResult, $result);
    }
}
