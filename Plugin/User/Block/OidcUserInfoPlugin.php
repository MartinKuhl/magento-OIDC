<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User\Block;

use Magento\User\Block\User\Edit\Tab\Main;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Adds a read-only "OIDC Provider" note field directly after the Expiration Date
 * field in the Account Information fieldset on the admin User edit page.
 *
 * Targets: Magento\User\Block\User\Edit\Tab\Main (same block as OidcIdentityFieldPlugin)
 */
class OidcUserInfoPlugin
{
    /** @var \MiniOrange\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /**
     * @param UserProviderResource $userProviderResource
     */
    public function __construct(UserProviderResource $userProviderResource)
    {
        $this->userProviderResource = $userProviderResource;
    }

    /**
     * After setForm — add an OIDC Provider note below the Expiration Date field.
     *
     * @param  Main $subject
     * @param  Main $result
     * @return Main
     */
    public function afterSetForm(Main $subject, Main $result): Main
    {
        $form = $subject->getForm();
        if (!$form) {
            return $result;
        }

        $user   = $subject->getUser();
        $userId = $user ? (int) $user->getId() : 0;

        $info = $userId > 0
            ? $this->userProviderResource->getProviderInfo('admin', $userId)
            : null;

        $providerText = $info
            ? htmlspecialchars($info['display_name'], ENT_QUOTES, 'UTF-8')
              . ' (' . htmlspecialchars($info['created_at'], ENT_QUOTES, 'UTF-8') . ')'
            : 'none';

        $fieldset = $form->getElement('base_fieldset');
        if ($fieldset && !$form->getElement('oidc_provider_info')) {
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

        return $result;
    }
}
