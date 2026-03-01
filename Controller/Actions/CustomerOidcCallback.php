<?php
/**
 * Customer OIDC Callback Controller
 *
 * Handles customer login in a clean HTTP context using nonce
 * validation. Mirrors the admin Oidccallback pattern for proper
 * session persistence.
 *
 * @package MiniOrange\OAuth\Controller\Actions
 */
namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Framework\App\Action\Context;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Customer OIDC callback handler.
 *
 * Validates nonce from cookie, loads customer, performs login,
 * and redirects to the stored relay state URL.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class CustomerOidcCallback extends BaseAction
{
    /** @var CustomerFactory */
    private readonly CustomerFactory $customerFactory;

    /** @var CustomerSession */
    private readonly CustomerSession $customerSession;

    /** @var StoreManagerInterface */
    private readonly StoreManagerInterface $storeManager;

    /** @var CookieManagerInterface */
    private readonly CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    private readonly CookieMetadataFactory $cookieMetadataFactory;

    /** @var OAuthSecurityHelper */
    private readonly OAuthSecurityHelper $securityHelper;

    /** @var CustomerRepositoryInterface */
    private readonly CustomerRepositoryInterface $customerRepository;

    /**
     * Initialize customer OIDC callback controller.
     *
     * @param Context $context Magento application context
     * @param OAuthUtility $oauthUtility OAuth utility helper
     * @param CustomerFactory $customerFactory Customer factory
     * @param CustomerSession $customerSession Customer session
     * @param StoreManagerInterface $storeManager Store manager
     * @param CookieManagerInterface $cookieManager Cookie manager
     * @param CookieMetadataFactory $cookieMetadataFactory Cookie
     *        metadata factory
     * @param OAuthSecurityHelper $securityHelper Security helper
     * @param CustomerRepositoryInterface $customerRepository
     *        Customer repository
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        CustomerFactory $customerFactory,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthSecurityHelper $securityHelper,
        CustomerRepositoryInterface $customerRepository
    ) {
        $this->customerFactory = $customerFactory;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->securityHelper = $securityHelper;
        $this->customerRepository = $customerRepository;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute customer login via nonce validation.
     *
     * Reads the nonce from cookie, validates it, loads the customer,
     * performs login in a clean HTTP context, sets the OIDC marker
     * cookie, and redirects to the relay state URL.
     *
     * @return Redirect Redirect to relay state or login on error
     */
    #[\Override]
    public function execute(): Redirect
    {
        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Starting customer authentication"
        );

        // Read and delete nonce cookie
        $nonce = $this->cookieManager->getCookie(
            'oidc_customer_nonce'
        );
        if ($nonce !== null) {
            try {
                $cookieMetadata = $this->cookieMetadataFactory
                    ->createCookieMetadata()
                    ->setPath('/');
                $this->cookieManager->deleteCookie(
                    'oidc_customer_nonce',
                    $cookieMetadata
                );
            } catch (\Exception $e) {
                $this->oauthUtility->customlog(
                    "CustomerOidcCallback: Error deleting nonce: "
                    . $e->getMessage()
                );
            }
        }

        if (empty($nonce)) {
            $this->oauthUtility->customlog(
                "ERROR: Missing customer OIDC nonce"
            );
            $this->messageManager->addErrorMessage(__(
                'Authentication failed. Please try again.'
            ));
            return $this->resultRedirectFactory->create()
                ->setPath('customer/account/login');
        }

        // Redeem nonce
        $nonceData = $this->securityHelper
            ->redeemCustomerLoginNonce($nonce);
        if ($nonceData === null) {
            $this->oauthUtility->customlog(
                "ERROR: Invalid or expired customer OIDC nonce"
            );
            $this->messageManager->addErrorMessage(__(
                'Authentication session expired. Please try again.'
            ));
            return $this->resultRedirectFactory->create()
                ->setPath('customer/account/login');
        }

        $email = $nonceData['email'];
        $relayState = $nonceData['relayState'];
        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Email from nonce: " . $email
        );

        // Load customer via repository (returns Data Interface)
        try {
            $customerData = $this->customerRepository->get($email);
            $customerId = $customerData->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->oauthUtility->customlog(
                "ERROR: Customer not found for email: " . $email
            );
            $this->messageManager->addErrorMessage(__(
                'Authentication failed. Please try again.'
            ));
            return $this->resultRedirectFactory->create()
                ->setPath('customer/account/login');
        }

        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Loaded customer ID: " . (string)$customerId
        );

        // Convert CustomerInterface to Model for session
        // PHPStan wants service contracts, but updateData() doesn't
        // properly initialize all Model fields needed for session
        // persistence. The load() method is required here.
        /** @phpstan-ignore-next-line */
        $customerModel = $this->customerFactory->create()
            ->load($customerId);

        // Verify model was loaded correctly
        if (!$customerModel->getId()) {
            $this->oauthUtility->customlog(
                "ERROR: Failed to load customer model for ID: "
                . (string)$customerId
            );
            $this->messageManager->addErrorMessage(__(
                'Authentication failed. Please try again.'
            ));
            return $this->resultRedirectFactory->create()
                ->setPath('customer/account/login');
        }

        // SEC-08: Enforce website context — reject cross-site login attempts
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        if ($customerModel->getWebsiteId() != $websiteId) {
            $this->oauthUtility->customlog(
                "SEC-08: Blocked cross-website login. "
                . "Customer website: " . $customerModel->getWebsiteId()
                . ", Current store website: " . $websiteId
            );
            $this->messageManager->addErrorMessage(
                __('Authentication failed: This account is not registered on this website.')
            );
            return $this->resultRedirectFactory->create()
                ->setPath('customer/account/login');
        }

        // Log in customer (clean HTTP context allows proper
        // session_regenerate_id())
        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Logging in customer ID: "
            . $customerModel->getId()
            . ", Email: " . $customerModel->getEmail()
            . ", Website: " . $customerModel->getWebsiteId()
        );
        $this->customerSession
            ->setCustomerAsLoggedIn($customerModel);

        // NOTE: Do NOT call regenerateId() here!
        // setCustomerAsLoggedIn() already regenerates the session internally.
        // Calling it again causes session ID mismatch between server and browser.

        // Verify session was set
        if ($this->customerSession->isLoggedIn()) {
            $this->oauthUtility->customlog(
                "CustomerOidcCallback: Session verification passed"
            );
        } else {
            $this->oauthUtility->customlog(
                "ERROR: Session verification failed after login!"
            );
        }

        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Login successful"
        );

        // Set OIDC authentication marker cookie (Item 4)
        try {
            $metadata = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setDuration(3600)
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure(true);
            $this->cookieManager->setPublicCookie(
                'oidc_customer_authenticated',
                '1',
                $metadata
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "CustomerOidcCallback: Error setting OIDC cookie: "
                . $e->getMessage()
            );
        }

        // Determine safe redirect URL
        $defaultRedirect = $this->oauthUtility->getBaseUrl()
            . 'customer/account';
        if (empty($relayState) || $relayState === '/') {
            $safeRedirect = $defaultRedirect;
        } else {
            $safeRedirect = $this->securityHelper
                ->validateRedirectUrl($relayState, $defaultRedirect);
            // Avoid redirecting back to login page
            $relayPath = $this->oauthUtility
                ->extractPathFromUrl($safeRedirect);
            $loginPath = '/customer/account/login';
            if (str_starts_with(
                rtrim($relayPath, '/'),
                $loginPath
            )) {
                $safeRedirect = $defaultRedirect;
            }
        }

        // Convert relative paths to full URLs
        if (str_starts_with($safeRedirect, '/')) {
            $baseUrl = rtrim(
                $this->oauthUtility->getBaseUrl(),
                '/'
            );
            $safeRedirect = $baseUrl . $safeRedirect;
        }

        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Redirecting to: " . $safeRedirect
        );

        // Log session details before redirect
        $sessionId = $this->customerSession->getSessionId();
        $customerId = $this->customerSession->getCustomerId();
        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Session ID before redirect: " . $sessionId
            . ", Customer ID in session: " . ($customerId ?? 'NULL')
        );

        // IMPORTANT: Do NOT call writeClose() here!
        // Calling writeClose() closes the session for writing, which causes
        // Magento's automatic session save to create a NEW session instead,
        // resulting in session ID mismatch. Let Magento save automatically.

        $this->oauthUtility->customlog(
            "CustomerOidcCallback: Returning redirect (Magento will save session)"
        );

        return $this->resultRedirectFactory->create()
            ->setUrl($safeRedirect);
    }
}
