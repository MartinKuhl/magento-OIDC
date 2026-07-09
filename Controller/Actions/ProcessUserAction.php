<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use M2Oidc\OAuth\Model\Service\CustomerUserCreator;
use M2Oidc\OAuth\Model\Service\CustomerProfileSyncService;
use Magento\Framework\App\ResponseFactory;
use Magento\Store\Model\StoreManagerInterface;
use M2Oidc\OAuth\Helper\Exception\MissingAttributesException;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\OAuthMessages;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
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
     * @var mixed
     */
    private $attrs;

    /**
     * @var mixed
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

    /**
     * @var bool Headless PWA mode flag (FEAT-09)
     */
    private bool $headless = false;

    /** @var \M2Oidc\OAuth\Controller\Actions\CustomerLoginAction */
    private readonly \M2Oidc\OAuth\Controller\Actions\CustomerLoginAction $customerLoginAction;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    private readonly \Magento\Store\Model\StoreManagerInterface $storeManager;

    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \M2Oidc\OAuth\Model\Service\CustomerUserCreator */
    private readonly \M2Oidc\OAuth\Model\Service\CustomerUserCreator $customerUserCreator;

    /** @var \M2Oidc\OAuth\Model\Service\CustomerProfileSyncService */
    private readonly CustomerProfileSyncService $profileSyncService;

    /** @var \Magento\Framework\Controller\Result\RedirectFactory */
    private readonly \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory;

    /** @var \Magento\Customer\Api\CustomerRepositoryInterface */
    private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository;

    /** @var \M2Oidc\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /**
     * Initialize ProcessUserAction.
     *
     * @param OAuthUtility $oauthUtility
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     * @param CustomerLoginAction $customerLoginAction
     * @param CustomerUserCreator $customerUserCreator
     * @param RedirectFactory $resultRedirectFactory
     * @param CustomerProfileSyncService $profileSyncService
     * @param UserProviderResource $userProviderResource
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CustomerRepositoryInterface $customerRepository,
        StoreManagerInterface $storeManager,
        CustomerLoginAction $customerLoginAction,
        CustomerUserCreator $customerUserCreator,
        RedirectFactory $resultRedirectFactory,
        CustomerProfileSyncService $profileSyncService,
        UserProviderResource $userProviderResource
    ) {
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->customerLoginAction = $customerLoginAction;
        $this->oauthUtility = $oauthUtility;
        $this->customerUserCreator = $customerUserCreator;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->profileSyncService = $profileSyncService;
        $this->userProviderResource = $userProviderResource;
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
     *
     * @var bool
     */
    private bool $attributesInitialized = false;

    /**
     * Initialize attribute mappings from active provider configuration.
     */
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

        // phpcs:ignore Magento2.Security.InsecureFunction.Found
        assert($this->userEmail !== null, 'userEmail must be set');
        return $this->processUserAction($this->userEmail, $firstName, $lastName, $userName);
    }

    /**
     * Handle missing attributes by logging and redirecting to login.
     */
    private function handleMissingAttributes(): \Magento\Framework\Controller\Result\Redirect
    {
        $this->oauthUtility->customlog("ERROR: Missing required attributes from OAuth provider");
        $encodedError = base64_encode(
            'Authentication failed: Required user information not received from identity provider.'
        );
        $loginUrl = $this->oauthUtility->getCustomerLoginUrl() . '?oidc_error=' . $encodedError;
        return $this->resultRedirectFactory->create()->setUrl($loginUrl);
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
        $isNewCustomer = false;

        if ($user) {
            // Provider binding check: reject if account is bound to a different IdP
            $boundProvider = $this->userProviderResource->getBoundProviderId('customer', (int) $user->getId());
            if ($boundProvider !== null && $boundProvider !== $this->providerId) {
                $this->oauthUtility->customlog(
                    "Provider mismatch for customer " . $userEmail
                    . " (bound=" . $boundProvider . ", current=" . $this->providerId . ")"
                );
                $encodedError = base64_encode(
                    OAuthMessages::parse('PROVIDER_MISMATCH', ['email' => $userEmail])
                );
                $baseLoginUrl = $this->oauthUtility->getCustomerLoginUrl();
                $sep = (strpos($baseLoginUrl, '?') !== false) ? '&' : '?';
                $redirectUrl = $baseLoginUrl . $sep . 'oidc_error=' . $encodedError;
                return $this->resultRedirectFactory->create()->setUrl($redirectUrl);
            }
            // First OIDC login of a pre-existing (non-OIDC-created) account — claim the binding
            if ($boundProvider === null && $this->providerId > 0) {
                $this->userProviderResource->saveMapping('customer', (int) $user->getId(), $this->providerId);
                $this->oauthUtility->customlog(
                    "Provider binding claimed for existing customer " . $userEmail
                    . " → provider " . $this->providerId
                );
            }
        }

        if (!$user) {
            $this->oauthUtility->customlog("User not found. Checking auto-create configuration");

            // Per-provider auto-create flag takes precedence over global config
            $autoCreateEnabled = $this->providerAutoCreateCustomer
                ?? $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);

            if (!$autoCreateEnabled) {
                $this->oauthUtility->customlog("Auto Create Customer is disabled. Rejecting login for: " . $userEmail);
                $encodedError = base64_encode(
                    OAuthMessages::parse('CUSTOMER_AUTO_CREATE_DISABLED_FOR_EMAIL', ['email' => $userEmail])
                );
                $baseLoginUrl = $this->oauthUtility->getCustomerLoginUrl();
                $sep = (strpos($baseLoginUrl, '?') !== false) ? '&' : '?';
                $loginUrl = $baseLoginUrl . $sep . 'oidc_error=' . $encodedError;
                return $this->resultRedirectFactory->create()->setUrl($loginUrl);
            }

            $user = $this->createNewUser($userEmail, $firstName, $lastName, $userName);
            $isNewCustomer = true;
        }

        // Update customer group on every SSO login if flag is set
        $this->updateExistingCustomerGroup($user);

        // Sync address on every SSO login (new customers included — CustomerUserCreator may have created the
        // address already, but the sync service detects no-change and skips a redundant save).
        $this->syncAddressIfEnabled($user);

        // Sync profile for existing customers only (new customers were just written; re-syncing email
        // immediately after creation is a no-op and avoids any session ordering issues).
        if (!$isNewCustomer) {
            $this->syncProfileIfEnabled($user);
        }

        /**
         * @var \Magento\Store\Model\Store $store
         */
        $store = $this->storeManager->getStore();
        $store_url = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $store_url = rtrim($store_url, '/\\');

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
                    . (string)($relayHost ?? '') . "' != '" . (string)($storeHost ?? '') . "'), reset to store URL."
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
            return $this->customerLoginAction->setUser($user)
                ->setRelayState($target)
                ->setHeadless($this->headless)
                ->execute();
        }

        if (!empty($relayState)) {
            return $this->customerLoginAction->setUser($user)
                ->setRelayState($relayState)
                ->setHeadless($this->headless)
                ->execute();
        }

        return $this->customerLoginAction->setUser($user)
            ->setRelayState('/')
            ->setHeadless($this->headless)
            ->execute();
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
        if (in_array($firstName, [null, '', '0'], true)) {
            $parts = explode("@", $userEmail);
            $firstName = $parts[0];
        }

        if (in_array($lastName, [null, '', '0'], true)) {
            $parts = explode("@", $userEmail);
            $lastName = $parts[1] ?? $parts[0];
        }

        $userName = $this->oauthUtility->isBlank($userName) ? $userEmail : (string)$userName;
        $firstName = $this->oauthUtility->isBlank($firstName) ? $userName : $firstName;
        $lastName  = $this->oauthUtility->isBlank($lastName)  ? $userName  : $lastName;

        $customer = $this->customerUserCreator->createCustomer(
            $userEmail,
            $userName,
            $firstName,
            $lastName,
            $this->flattenedattrs ?? [],
            $this->attrs ?? [],
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
     * @param  mixed $attrs
     */
    public function setAttrs($attrs): static
    {
        $this->attrs = $attrs;
        return $this;
    }

    /**
     * Set flattened attribute map (simple key => value mapping).
     *
     * @param  mixed $flattenedattrs
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
     * @param int $providerId m2oidc_oauth_client_apps.id (0 = not tracked)
     */
    public function setProviderId(int $providerId): static
    {
        $this->providerId = $providerId;
        return $this;
    }

    /**
     * Set the headless PWA mode flag (FEAT-09).
     *
     * @param bool $headless When true, CustomerLoginAction redirects to HeadlessOidcCallback.
     */
    public function setHeadless(bool $headless): static
    {
        $this->headless = $headless;
        return $this;
    }

    /**
     * Sync customer profile fields from OIDC claims if sync_customer_profile_on_sso is enabled.
     *
     * @param CustomerInterface $customer
     */
    private function syncProfileIfEnabled(CustomerInterface $customer): void
    {
        $flag = $this->oauthUtility->getStoreConfig(OAuthConstants::SYNC_CUSTOMER_PROFILE_ON_SSO);
        if (!$flag || (string) $flag !== '1') {
            return;
        }
        $attrKeys = [
            'firstname' => $this->firstNameKey,
            'lastname'  => $this->lastNameKey,
            'email'     => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL)
                ?: OAuthConstants::DEFAULT_MAP_EMAIL,
            'dob'       => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB)
                ?: OAuthConstants::DEFAULT_MAP_DOB,
            'gender'    => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER)
                ?: OAuthConstants::DEFAULT_MAP_GENDER,
        ];
        try {
            $this->profileSyncService->syncProfile(
                $customer,
                $this->flattenedattrs ?? [],
                $this->attrs ?? [],
                $attrKeys,
                $this->providerId
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('ProcessUserAction: profile sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Sync customer billing address from OIDC claims if sync_customer_address_on_sso is enabled.
     *
     * @param CustomerInterface $customer
     */
    private function syncAddressIfEnabled(CustomerInterface $customer): void
    {
        $flag = $this->oauthUtility->getStoreConfig(OAuthConstants::SYNC_CUSTOMER_ADDRESS_ON_SSO);
        if (!$flag || (string) $flag !== '1') {
            return;
        }
        $addrKeys = [
            'street'  => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET)
                ?: OAuthConstants::DEFAULT_MAP_STREET,
            'city'    => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY)
                ?: OAuthConstants::DEFAULT_MAP_CITY,
            'zip'     => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP)
                ?: OAuthConstants::DEFAULT_MAP_ZIP,
            'country' => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY)
                ?: OAuthConstants::DEFAULT_MAP_COUNTRY,
            'phone'   => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE)
                ?: OAuthConstants::DEFAULT_MAP_PHONE,
            'state'   => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_STATE)
                ?: OAuthConstants::DEFAULT_MAP_STATE,
        ];
        try {
            $this->profileSyncService->syncAddress(
                $customer,
                $this->flattenedattrs ?? [],
                $this->attrs ?? [],
                $addrKeys
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('ProcessUserAction: address sync failed: ' . $e->getMessage());
        }
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
                $this->attrs ?? [],
                $this->oauthUtility->getActiveProviderId() ?? 0
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'ProcessUserAction: group update failed: ' . $e->getMessage()
            );
        }
    }
}
