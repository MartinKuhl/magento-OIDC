<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;

class CustomerLoginAction extends BaseAction implements HttpPostActionInterface
{
    /**
     * @var \Magento\Customer\Model\Data\Customer|null
     */
    private $user;

    /**
     * @var string|null
     */
    private $relayState;

    private \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    private CookieManagerInterface $cookieManager;

    private CookieMetadataFactory $cookieMetadataFactory;

    /**
     * Initialize customer login action.
     *
     * @param Context $context Magento application context
     * @param OAuthUtility $oauthUtility OAuth utility helper
     * @param OAuthSecurityHelper $securityHelper Security helper
     * @param CookieManagerInterface $cookieManager Cookie manager
     * @param CookieMetadataFactory $cookieMetadataFactory Cookie
     *        metadata factory
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->securityHelper = $securityHelper;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function - creates nonce and redirects to callback.
     *
     * Creates a one-time nonce containing the customer email and
     * relay state, stores it in a cookie, and redirects to the
     * callback controller which performs login in a clean HTTP
     * context.
     */
    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        if ($this->relayState === null) {
            $this->relayState = $this->oauthUtility->getBaseUrl()
                . "customer/account";
        }
        $this->oauthUtility->customlog("CustomerLoginAction: execute");

        if ($this->user === null) {
            $this->oauthUtility->customlog(
                "CustomerLoginAction: ERROR - user is null"
            );
            $this->messageManager->addErrorMessage(__(
                'Authentication failed. Please try again.'
            ));
            return $this->resultRedirectFactory->create()->setUrl(
                $this->oauthUtility->getBaseUrl()
                . 'customer/account/login'
            );
        }

        // If relayState points to login page, use dashboard instead
        $relayPath = $this->oauthUtility
            ->extractPathFromUrl($this->relayState);
        $loginPath = '/customer/account/login';
        if (str_starts_with(rtrim($relayPath, '/'), $loginPath)) {
            $this->relayState = $this->oauthUtility->getBaseUrl()
                . 'customer/account';
        }

        // Create nonce with email + relayState
        $nonce = $this->securityHelper->createCustomerLoginNonce(
            $this->user->getEmail(),
            $this->relayState
        );

        // Set nonce as HttpOnly cookie (120s TTL matches cache)
        try {
            $cookieMetadata = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setDuration(120)
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure(true)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie(
                'oidc_customer_nonce',
                $nonce,
                $cookieMetadata
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "CustomerLoginAction: Error setting nonce cookie: "
                . $e->getMessage()
            );
            $this->messageManager->addErrorMessage(__(
                'Authentication failed. Please try again.'
            ));
            return $this->resultRedirectFactory->create()
                ->setPath('customer/account/login');
        }

        // Redirect to customer callback endpoint
        $callbackUrl = $this->oauthUtility->getBaseUrl()
            . 'mooauth/actions/CustomerOidcCallback';
        $this->oauthUtility->customlog(
            "CustomerLoginAction: Redirecting to callback: "
            . $callbackUrl
        );
        return $this->resultRedirectFactory->create()
            ->setUrl($callbackUrl);
    }

    /**
     * Setter for the user parameter.
     *
     * @param  \Magento\Customer\Model\Data\Customer|null $user
     */
    public function setUser($user): static
    {
        $this->oauthUtility->customlog("CustomerLoginAction: setUser");

        $this->user = $user;
        return $this;
    }

    /**
     * Setter for the relayState parameter.
     *
     * @param  string|null $relayState
     */
    public function setRelayState($relayState): static
    {
        $this->oauthUtility->customlog("CustomerLoginAction: setRelayState");
        $this->relayState = $relayState;
        return $this;
    }
}
