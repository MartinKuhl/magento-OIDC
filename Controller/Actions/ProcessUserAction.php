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
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
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
     * @var int|null Per-provider auto-create customer flag (null = fall back to global config)
     */
    private ?int $providerAutoCreateCustomer = null;

    /**
     * @var int OIDC provider ID to record when a new customer is created (0 = not tracked)
     */
    private int $providerId = 0;

    /** @var \MiniOrange\OAuth\Controller\Actions\CustomerLoginAction */
    private readonly \MiniOrange\OAuth\Controller\Actions\CustomerLoginAction $customerLoginAction;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    private readonly \Magento\Store\Model\StoreManagerInterface $storeManager;

    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    private readonly \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \MiniOrange\OAuth\Model\Service\CustomerUserCreator */
    private readonly \MiniOrange\OAuth\Model\Service\CustomerUserCreator $customerUserCreator;

    /** @var \Magento\Framework\Controller\Result\RedirectFactory */
    private readonly \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory;

    /** @var \Magento\Framework\Message\ManagerInterface */
    private readonly \Magento\Framework\Message\ManagerInterface $messageManager;

    /** @var \Magento\Customer\Api\CustomerRepositoryInterface */
    private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository;

    /**
     * Initialize ProcessUserAction.
     *
     * @param OAuthUtility $oauthUtility
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     * @param CustomerLoginAction $customerLoginAction
     * @param CustomerUserCreator $customerUserCreator
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        CustomerLoginAction $customerLoginAction,
        CustomerUserCreator $customerUserCreator,
        RedirectFactory $resultRedirectFactory,
        ManagerInterface $messageManager
    ) {
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->customerLoginAction = $customerLoginAction;
        $this->oauthUtility = $oauthUtility;
        $this->customerUserCreator = $customerUserCreator;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * Lazy-initialize attribute mappings from the active provider context.
     *
     * Must be called at the start of execute() — after setActiveProviderId()
     * has been called on oauthUtility — so that getStoreConfig() resolves
     * values from the correct provider row instead of core_config_data.
     *
     * Note: DOB, Gender, Phone and Address mappings are handled exclusively
     * by CustomerUserCreator::initializeAttributeMapping() and are therefore
     * not loaded here.
     */
    private bool $attributesInitialized = false;

    private function initAttributeMappings(): void
    {
        if ($this->attributesInitialized) {
            return;
        }
        $this->attributesInitialized = true;

        $this->usernameAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME)
            ?: OAuthConstants::DEFAULT_MAP_USERN;

        $this->firstNameKey = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME)
            ?: OAuthConstants::DEFAULT_MAP_FN;

        $this->lastNameKey = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME)
            ?: OAuthConstants::DEFAULT_MAP_LN;

        $this->defaultRole = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE)
            ?: OAuthConstants::DEFAULT_ROLE;
    }

    /**
     * Execute the user processing action.
     */
    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        // MP-05: Initialize attribute mappings from active provider context
        $this->initAttributeMappings();
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
     *
     * @param  string      $userEmail
     * @param  string|null $firstName
     * @param  string|null $lastName
     * @param  string|null $userName
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

            // Per-provider auto-create flag takes precedence over global config
            $autoCreateEnabled = $this->providerAutoCreateCustomer
                ?? $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);

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

        // Update customer group on every SSO login if flag is set
        $this->updateExistingCustomerGroup($user);

        /**
         * @var \Magento\Store\Model\Store $store
         */
        $store = $this->storeManager->getStore();
        $store_url = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $store_url = rtrim((string) $store_url, '/\\');

        // SEC-09: Validate relay state by comparing parsed hosts — str_contains allows open-redirect bypass
        // (e.g. https://evil.com?q=real-store.com would have passed str_contains).
        if (isset($this->attrs['relayState']) && $this->attrs['relayState'] !== '/') {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $relayHost = parse_url((string) $this->attrs['relayState'], PHP_URL_HOST);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $storeHost  = parse_url($store_url, PHP_URL_HOST);
            if ($relayHost !== $storeHost) {
                $this->attrs['relayState'] = $store_url;
                $this->oauthUtility->customlog(
                    "SEC-09: relayState host mismatch ('"
                    . $relayHost . "' != '" . $storeHost . "'), reset to store URL."
                );
            }
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
     * Create a new customer from OIDC attributes.
     *
     * @param  string      $userEmail
     * @param  string|null $firstName
     * @param  string|null $lastName
     * @param  string|null $userName
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
            $this->attrs,
            $this->providerId
        );

        if (!$customer instanceof \Magento\Customer\Api\Data\CustomerInterface) {
            throw new \RuntimeException(
                sprintf('Failed to create customer account for email: %s', $userEmail)
            );
        }

        return $customer;
    }

    /**
     * Load customer by email from the current website.
     *
     * @param  string $userEmail
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

    /**
     * Override auto-create customer with a per-provider value.
     *
     * @param int|null $value 1 = enabled, 0 = disabled, null = use global config
     */
    public function setAutoCreateCustomer(?int $value): static
    {
        $this->providerAutoCreateCustomer = $value;
        return $this;
    }

    /**
     * Set the OIDC provider ID to record when a new customer is created.
     *
     * @param int $providerId miniorange_oauth_client_apps.id (0 = not tracked)
     */
    public function setProviderId(int $providerId): static
    {
        $this->providerId = $providerId;
        return $this;
    }

    /**
     * Update customer group from OIDC claims if update_frontend_groups_on_sso is enabled.
     *
     * @param CustomerInterface $customer
     */
    private function updateExistingCustomerGroup(CustomerInterface $customer): void
    {
        $updateFlag = $this->oauthUtility->getStoreConfig(OAuthConstants::UPDATE_FRONTEND_GROUPS_ON_SSO);
        if ($this->oauthUtility->isBlank($updateFlag) || (string) $updateFlag !== '1') {
            return;
        }

        $this->oauthUtility->customlog('ProcessUserAction: update_frontend_groups_on_sso is ON');

        try {
            $this->customerUserCreator->updateCustomerGroupFromOidc(
                $customer,
                $this->flattenedattrs ?? [],
                $this->attrs ?? []
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'ProcessUserAction: group update failed: ' . $e->getMessage()
            );
        }
    }

}
