<?php
namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Model\Service\CustomerUserCreator;
use Magento\Framework\App\ResponseFactory;
use Magento\Store\Model\StoreManagerInterface;
use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthMessages;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Customer\Api\Data\CustomerInterface;

/**
 * Handles creation or lookup of customer users from OIDC attributes
 * and performs the customer login flow.
 */
class ProcessUserAction
{
    /**
     * @var array|null
     */
    private $attrs;

    /**
     * @var array|null
     */
    private $flattenedattrs;

    /**
     * @var string|null
     */
    private $userEmail;

    /**
     * @var string|null
     */
    private $defaultRole;

    /**
     * @var string
     */
    private $emailAttribute;

    /**
     * @var string
     */
    private $usernameAttribute;

    /**
     * @var string
     */
    private $firstNameKey;

    /**
     * @var string
     */
    private $lastNameKey;

    /**
     * @var string|null
     */
    private $dobAttribute;

    /**
     * @var string|null
     */
    private $genderAttribute;

    /**
     * @var string|null
     */
    private $phoneAttribute;

    /**
     * @var string|null
     */
    private $streetAttribute;

    /**
     * @var string|null
     */
    private $zipAttribute;

    /**
     * @var string|null
     */
    private $cityAttribute;

    /**
     * @var string|null
     */
    private $countryAttribute;

    private \MiniOrange\OAuth\Controller\Actions\CustomerLoginAction $customerLoginAction;

    private \Magento\Store\Model\StoreManagerInterface $storeManager;

    private \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    private \MiniOrange\OAuth\Model\Service\CustomerUserCreator $customerUserCreator;

    private \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory;

    private \Magento\Framework\Message\ManagerInterface $messageManager;

    private \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository;

    /**
     * Initialize ProcessUserAction.
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        ResponseFactory $responseFactory,
        CustomerLoginAction $customerLoginAction,
        ScopeConfigInterface $scopeConfig,
        CustomerUserCreator $customerUserCreator,
        RedirectFactory $resultRedirectFactory,
        ManagerInterface $messageManager
    ) {
        $this->emailAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $this->emailAttribute = $oauthUtility->isBlank($this->emailAttribute)
            ? OAuthConstants::DEFAULT_MAP_EMAIL
            : $this->emailAttribute;
        $this->usernameAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $this->usernameAttribute = $oauthUtility->isBlank($this->usernameAttribute)
            ? OAuthConstants::DEFAULT_MAP_USERN
            : $this->usernameAttribute;
        $this->firstNameKey = $oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        $this->firstNameKey = $oauthUtility->isBlank($this->firstNameKey)
            ? OAuthConstants::DEFAULT_MAP_FN
            : $this->firstNameKey;
        $this->lastNameKey = $oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        $this->lastNameKey = $oauthUtility->isBlank($this->lastNameKey)
            ? OAuthConstants::DEFAULT_MAP_LN
            : $this->lastNameKey;
        $this->defaultRole = $oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE);

        // Initialize customer data mapping attributes
        $this->dobAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB);
        $this->dobAttribute = $oauthUtility->isBlank($this->dobAttribute)
            ? OAuthConstants::DEFAULT_MAP_DOB
            : $this->dobAttribute;
        $this->genderAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER);
        $this->genderAttribute = $oauthUtility->isBlank($this->genderAttribute)
            ? OAuthConstants::DEFAULT_MAP_GENDER
            : $this->genderAttribute;
        $this->phoneAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE);
        $this->phoneAttribute = $oauthUtility->isBlank($this->phoneAttribute)
            ? OAuthConstants::DEFAULT_MAP_PHONE
            : $this->phoneAttribute;
        $this->streetAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET);
        $this->streetAttribute = $oauthUtility->isBlank($this->streetAttribute)
            ? OAuthConstants::DEFAULT_MAP_STREET
            : $this->streetAttribute;
        $this->zipAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP);
        $this->zipAttribute = $oauthUtility->isBlank($this->zipAttribute)
            ? OAuthConstants::DEFAULT_MAP_ZIP
            : $this->zipAttribute;
        $this->cityAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY);
        $this->cityAttribute = $oauthUtility->isBlank($this->cityAttribute)
            ? OAuthConstants::DEFAULT_MAP_CITY
            : $this->cityAttribute;
        $this->countryAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY);
        $this->countryAttribute = $oauthUtility->isBlank($this->countryAttribute)
            ? OAuthConstants::DEFAULT_MAP_COUNTRY
            : $this->countryAttribute;

        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->customerLoginAction = $customerLoginAction;
        $this->oauthUtility = $oauthUtility;
        $this->customerUserCreator = $customerUserCreator;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * Execute the user processing action.
     */
    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        $this->oauthUtility->customlog("ProcessUserAction: execute");
        if (empty($this->attrs)) {
            $this->oauthUtility->customlog("No Attributes Received");
            return $this->handleMissingAttributes();
        }

        $firstName = $this->flattenedattrs[$this->firstNameKey] ?? null;
        $lastName = $this->flattenedattrs[$this->lastNameKey] ?? null;
        $userName = $this->flattenedattrs[$this->usernameAttribute] ?? null;

        if ($this->oauthUtility->isBlank($this->defaultRole)) {
            $this->defaultRole = OAuthConstants::DEFAULT_ROLE;
        }

        return $this->processUserAction($this->userEmail, $firstName, $lastName, $userName);
    }

    /**
     * Handle missing attributes by logging and redirecting to login.
     */
    private function handleMissingAttributes(): \Magento\Framework\Controller\Result\Redirect
    {
        $this->oauthUtility->customlog("ERROR: Missing required attributes from OAuth provider");
        $this->messageManager->addErrorMessage(
            __('Authentication failed: Required user information not received from identity provider.')
        );
        return $this->resultRedirectFactory->create()->setPath('customer/account/login');
    }

    /**
     * Process user action.
     */
    private function processUserAction(
        string $userEmail,
        ?string $firstName,
        ?string $lastName,
        ?string $userName
    ): \Magento\Framework\Controller\Result\Redirect {
        $user = $this->getCustomerFromAttributes($userEmail);

        if (!$user) {
            $this->oauthUtility->customlog("User not found. Checking auto-create configuration");

            $autoCreateEnabled = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);

            if (!$autoCreateEnabled) {
                $this->oauthUtility->customlog("Auto Create Customer is disabled. Rejecting login.");
                $encodedError = base64_encode(OAuthMessages::AUTO_CREATE_USER_DISABLED);
                $baseLoginUrl = $this->oauthUtility->getCustomerLoginUrl();
                $sep = (strpos($baseLoginUrl, '?') !== false) ? '&' : '?';
                $loginUrl = $baseLoginUrl . $sep . 'oidc_error=' . $encodedError;
                return $this->resultRedirectFactory->create()->setUrl($loginUrl);
            }

            $user = $this->createNewUser($userEmail, $firstName, $lastName, $userName);
        }

        /**
 * @var \Magento\Store\Model\Store $store
*/
        $store = $this->storeManager->getStore();
        $store_url = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $store_url = rtrim($store_url, '/\\');

        if (isset($this->attrs['relayState'])
            && !str_contains($this->attrs['relayState'], $store_url)
            && $this->attrs['relayState'] != '/'
        ) {
            $this->attrs['relayState'] = $store_url;
            $this->oauthUtility->customlog("processUserAction: changing relayState with store url " . $store_url);
        }

        // Customer login flow (admin routing is now handled by CheckAttributeMappingAction)
        $this->oauthUtility->customlog("processUserAction: Processing as customer login");

        $relayState = '';
        if (is_array($this->attrs) && isset($this->attrs['relayState'])) {
            $relayState = $this->attrs['relayState'];
        }

        if ($this->oauthUtility->getSessionData('guest_checkout')) {
            $this->oauthUtility->setSessionData('guest_checkout', null);
            $target = rtrim($this->oauthUtility->getBaseUrl(), '/') . '/checkout';
            return $this->customerLoginAction->setUser($user)->setRelayState($target)->execute();
        }

        if (!empty($relayState)) {
            return $this->customerLoginAction->setUser($user)->setRelayState($relayState)->execute();
        }

        return $this->customerLoginAction->setUser($user)->setRelayState('/')->execute();
    }

    /**
     * Create a new customer user from OIDC attributes.
     *
     * @throws \RuntimeException If customer creation fails
     */
    private function createNewUser(
        string $userEmail,
        ?string $firstName,
        ?string $lastName,
        ?string $userName
    ): \Magento\Customer\Api\Data\CustomerInterface {
        if ($firstName === null || $firstName === '' || $firstName === '0') {
            $parts = explode("@", $userEmail);
            $firstName = $parts[0];
        }

        if ($lastName === null || $lastName === '' || $lastName === '0') {
            $parts = explode("@", $userEmail);
            $lastName = $parts[1] ?? $parts[0];
        }

        $userName = $this->oauthUtility->isBlank($userName) ? $userEmail : $userName;
        $firstName = $this->oauthUtility->isBlank($firstName) ? $userName : $firstName;
        $lastName = $this->oauthUtility->isBlank($lastName) ? $userName : $lastName;

        $customer = $this->customerUserCreator->createCustomer(
            $userEmail,
            $userName,
            $firstName,
            $lastName,
            $this->flattenedattrs,
            $this->attrs
        );

        if ($customer === null) {
            throw new \RuntimeException(
                sprintf('Failed to create customer account for email: %s', $userEmail)
            );
        }

        return $customer;
    }

    /**
     * Load customer by email from the current website.
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface|false
     */
    private function getCustomerFromAttributes(string $userEmail)
    {
        try {
            return $this->customerRepository->get($userEmail);
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * Set raw attribute array received from OIDC provider.
     *
     * @param  array $attrs
     * @return $this
     */
    public function setAttrs($attrs): static
    {
        $this->attrs = $attrs;
        return $this;
    }

    /**
     * Set flattened attribute map (simple key => value mapping).
     *
     * @param  array $flattenedattrs
     * @return $this
     */
    public function setFlattenedAttrs($flattenedattrs): static
    {
        $this->flattenedattrs = $flattenedattrs;
        return $this;
    }

    /**
     * Set the user's email address resolved from attributes.
     *
     * @param  string $userEmail
     * @return $this
     */
    public function setUserEmail($userEmail): static
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    // Admin creation methods removed as they are now handled by CheckAttributeMappingAction and AdminUserCreator.
}
