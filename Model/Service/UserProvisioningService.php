<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Facade for JIT (Just-In-Time) user provisioning during OIDC authentication.
 *
 * Wraps AdminUserCreator and CustomerUserCreator with before/after events so
 * third-party modules can react to or modify provisioning without overriding
 * controller logic.
 *
 * Events dispatched (frontend + adminhtml area):
 *   oidc_admin_user_before_create   — before admin user is created via SSO
 *   oidc_admin_user_after_create    — after admin user is created via SSO
 *   oidc_customer_before_create     — before customer is created via SSO
 *   oidc_customer_after_create      — after customer is created via SSO
 *
 * All events carry a Magento\Framework\DataObject transport object with the
 * documented keys. Observers may set 'skip_creation' => true on the before-
 * events to abort creation and return null.
 */
class UserProvisioningService
{
    /** @var AdminUserCreator */
    private readonly AdminUserCreator $adminUserCreator;

    /** @var CustomerUserCreator */
    private readonly CustomerUserCreator $customerUserCreator;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var EventManager */
    private readonly EventManager $eventManager;

    /**
     * @param AdminUserCreator    $adminUserCreator
     * @param CustomerUserCreator $customerUserCreator
     * @param OAuthUtility        $oauthUtility
     * @param EventManager        $eventManager
     */
    public function __construct(
        AdminUserCreator $adminUserCreator,
        CustomerUserCreator $customerUserCreator,
        OAuthUtility $oauthUtility,
        EventManager $eventManager
    ) {
        $this->adminUserCreator    = $adminUserCreator;
        $this->customerUserCreator = $customerUserCreator;
        $this->oauthUtility        = $oauthUtility;
        $this->eventManager        = $eventManager;
    }

    /**
     * Provision (create) an admin user based on OIDC attributes.
     *
     * Fires oidc_admin_user_before_create before and oidc_admin_user_after_create
     * after the creation attempt. If an observer sets 'skip_creation' => true on
     * the before-event transport, creation is skipped and null is returned.
     *
     * @param  string      $email
     * @param  string      $username
     * @param  string|null $firstName
     * @param  string|null $lastName
     * @param  array       $oidcGroups
     * @param  int         $providerId
     */
    public function provisionAdmin(
        string $email,
        string $username,
        ?string $firstName,
        ?string $lastName,
        array $oidcGroups,
        int $providerId
    ): ?\Magento\User\Model\User {
        $transport = new DataObject([
            'email'         => $email,
            'username'      => $username,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'groups'        => $oidcGroups,
            'provider_id'   => $providerId,
            'skip_creation' => false,
        ]);

        $this->eventManager->dispatch('oidc_admin_user_before_create', ['transport' => $transport]);

        if ($transport->getData('skip_creation')) {
            $this->oauthUtility->customlog(
                "UserProvisioningService: oidc_admin_user_before_create observer requested skip"
                . " for {$email}"
            );
            return null;
        }

        $user = $this->adminUserCreator->createAdminUser(
            (string) $transport->getData('email'),
            (string) $transport->getData('username'),
            $transport->getData('first_name'),
            $transport->getData('last_name'),
            (array)  $transport->getData('groups'),
            (int)    $transport->getData('provider_id')
        );

        $this->eventManager->dispatch('oidc_admin_user_after_create', [
            'user'        => $user,
            'email'       => $email,
            'provider_id' => $providerId,
            'transport'   => new DataObject(['user' => $user, 'provider_id' => $providerId]),
        ]);

        return $user;
    }

    /**
     * Provision (create or update) a customer based on OIDC attributes.
     *
     * Fires oidc_customer_before_create before and oidc_customer_after_create
     * after the creation attempt. If an observer sets 'skip_creation' => true on
     * the before-event transport, creation is skipped and null is returned.
     *
     * @param  string $email
     * @param  string $username
     * @param  string $firstName
     * @param  string $lastName
     * @param  array  $flattenedAttrs  Flattened OIDC claims
     * @param  array  $rawAttrs        Raw (nested) OIDC response
     * @param  int    $providerId
     */
    public function provisionCustomer(
        string $email,
        string $username,
        string $firstName,
        string $lastName,
        array $flattenedAttrs,
        array $rawAttrs,
        int $providerId
    ): ?\Magento\Customer\Api\Data\CustomerInterface {
        $transport = new DataObject([
            'email'          => $email,
            'username'       => $username,
            'first_name'     => $firstName,
            'last_name'      => $lastName,
            'flattened_attrs' => $flattenedAttrs,
            'raw_attrs'      => $rawAttrs,
            'provider_id'    => $providerId,
            'skip_creation'  => false,
        ]);

        $this->eventManager->dispatch('oidc_customer_before_create', ['transport' => $transport]);

        if ($transport->getData('skip_creation')) {
            $this->oauthUtility->customlog(
                "UserProvisioningService: oidc_customer_before_create observer requested skip"
                . " for {$email}"
            );
            return null;
        }

        $customer = $this->customerUserCreator->createCustomer(
            (string) $transport->getData('email'),
            (string) $transport->getData('username'),
            (string) $transport->getData('first_name'),
            (string) $transport->getData('last_name'),
            (array)  $transport->getData('flattened_attrs'),
            (array)  $transport->getData('raw_attrs'),
            (int)    $transport->getData('provider_id')
        );

        $this->eventManager->dispatch('oidc_customer_after_create', [
            'customer'    => $customer,
            'email'       => $email,
            'provider_id' => $providerId,
            'transport'   => new DataObject(['customer' => $customer, 'provider_id' => $providerId]),
        ]);

        return $customer;
    }
}
