<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User\Block;

use Closure;
use Magento\Framework\Registry;
use Magento\User\Block\User\Edit\Tab\Main;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Adds a read-only "OIDC Provider" note field directly after the Expiration Date
 * field in the Account Information fieldset on the admin User edit page.
 *
 * Uses aroundGetFormHtml + Registry to ensure the user is fully loaded.
 */
class OidcUserInfoPlugin
{
    private readonly UserProviderResource $userProviderResource;
    private readonly Registry $registry;

    public function __construct(
        UserProviderResource $userProviderResource,
        Registry $registry
    ) {
        $this->userProviderResource = $userProviderResource;
        $this->registry = $registry;
    }

    /**
     * Around getFormHtml — inject OIDC Provider note after the form is prepared.
     *
     * The admin user is loaded from the registry key 'permissions_user',
     * which is set by the Edit controller before block rendering.
     */
    public function aroundGetFormHtml(Main $subject, Closure $proceed): string
    {
        $result = $proceed();

        $form = $subject->getForm();
        if (!$form) {
            return $result;
        }

        // Guard: field already added (e.g. multiple render passes)
        if ($form->getElement('oidc_provider_info')) {
            return $result;
        }

        // Registry is the reliable source — getUser() returns null at this stage
        $user   = $this->registry->registry('permissions_user');
        $userId = $user ? (int) $user->getId() : 0;
        error_log('aroundGetFormHtml: userId=' . $userId . ' user_class=' . ($user ? get_class($user) : 'null'));

        $info = $userId > 0
            ? $this->userProviderResource->getProviderInfo('admin', $userId)
            : null;

        $providerText = $info
            ? htmlspecialchars($info['display_name'], ENT_QUOTES, 'UTF-8')
              . ' (' . htmlspecialchars($info['created_at'], ENT_QUOTES, 'UTF-8') . ')'
            : 'none';

        $fieldset = $form->getElement('base_fieldset');
        if ($fieldset) {
            $fieldset->addField(
                'oidc_provider_info',
                'note',
                [
                    'label' => __('OIDC Provider'),
                    'text'  => $providerText,
                ],
                'expiration'
            );
        }

        return $form->toHtml();
    }
}
