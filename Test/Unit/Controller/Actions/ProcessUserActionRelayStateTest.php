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
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Data\OidcUserProvisioningContext;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use M2Oidc\OAuth\Model\Service\CustomerProfileSyncService;
use M2Oidc\OAuth\Model\Service\CustomerUserCreator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProcessUserAction relay-state validation (SEC-09 / H-05).
 *
 * Exercises the runtime host-comparison guard through the public handle()
 * path and asserts which relay state reaches CustomerLoginAction:
 *  - Relative paths (/checkout/cart) have no host and are same-origin — preserved
 *  - Absolute same-host URLs are preserved
 *  - Foreign-host URLs embedding the store host in the query are reset
 *  - Scheme-relative URLs (//evil.com/x) DO carry a host and are reset
 *
 * @covers \M2Oidc\OAuth\Controller\Actions\ProcessUserAction
 */
class ProcessUserActionRelayStateTest extends TestCase
{
    private const USER_EMAIL = 'customer@example.com';
    private const STORE_URL  = 'https://store.example.com';

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

    protected function setUp(): void
    {
        $this->oauthUtility         = $this->createMock(OAuthUtility::class);
        $this->customerRepository   = $this->createMock(CustomerRepositoryInterface::class);
        $this->storeManager         = $this->createMock(StoreManagerInterface::class);
        $this->customerLoginAction  = $this->createMock(CustomerLoginAction::class);
        $this->customerUserCreator  = $this->createMock(CustomerUserCreator::class);
        $this->redirectFactory      = $this->createMock(RedirectFactory::class);
        $this->profileSyncService   = $this->createMock(CustomerProfileSyncService::class);
        $this->userProviderResource = $this->createMock(UserProviderResource::class);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn('42');

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('isBlank')->willReturn(false);
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

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn(self::STORE_URL . '/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->customerRepository
            ->method('get')
            ->with(self::USER_EMAIL)
            ->willReturn($customer);

        // No provider binding logic in scope here
        $this->userProviderResource->method('getBoundProviderId')->willReturn(null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Run handle() with the given relay state and assert what reaches
     * CustomerLoginAction::setRelayState().
     *
     * @param string $relayState         Relay state supplied via attributes
     * @param string $expectedRelayState Relay state CustomerLoginAction must receive
     */
    private function assertRelayStateForwardedAs(string $relayState, string $expectedRelayState): void
    {
        $redirectResult = $this->createMock(Redirect::class);
        $this->customerLoginAction->method('setUser')->willReturnSelf();
        $this->customerLoginAction->expects($this->once())
            ->method('setRelayState')
            ->with($expectedRelayState)
            ->willReturnSelf();
        $this->customerLoginAction->method('setHeadless')->willReturnSelf();
        $this->customerLoginAction->method('execute')->willReturn($redirectResult);

        $action = new ProcessUserAction(
            $this->oauthUtility,
            $this->customerRepository,
            $this->storeManager,
            $this->customerLoginAction,
            $this->customerUserCreator,
            $this->redirectFactory,
            $this->profileSyncService,
            $this->userProviderResource
        );
        $context = new OidcUserProvisioningContext(
            [
                'email'      => self::USER_EMAIL,
                'relayState' => $relayState,
            ],
            [
                'email'              => self::USER_EMAIL,
                'preferred_username' => 'customer_user',
                'given_name'         => 'Jane',
                'family_name'        => 'Doe',
            ],
            self::USER_EMAIL,
            null,
            0,
            false
        );

        $result = $action->handle($context);

        $this->assertSame($redirectResult, $result);
    }

    // -------------------------------------------------------------------------
    // H-05: relative relay states are same-origin and must be preserved
    // -------------------------------------------------------------------------

    public function testRelativeRelayStateIsPreserved(): void
    {
        $this->assertRelayStateForwardedAs('/checkout/cart', '/checkout/cart');
    }

    public function testSameHostAbsoluteRelayStateIsPreserved(): void
    {
        $this->assertRelayStateForwardedAs(
            self::STORE_URL . '/sales/order/history',
            self::STORE_URL . '/sales/order/history'
        );
    }

    // -------------------------------------------------------------------------
    // SEC-09: foreign-host relay states are reset to the store URL
    // -------------------------------------------------------------------------

    public function testForeignHostRelayStateIsReset(): void
    {
        // str_contains-style bypass attempt: store host only appears in the query
        $this->assertRelayStateForwardedAs(
            'https://evil.com/?q=store.example.com',
            self::STORE_URL
        );
    }

    public function testSchemeRelativeRelayStateIsReset(): void
    {
        // //evil.com/x DOES have a host per parse_url and must still be caught
        $this->assertRelayStateForwardedAs('//evil.com/x', self::STORE_URL);
    }
}
