<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User\Block;

use Magento\User\Block\User\Edit\Tab\Main;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Adds a read-only "OIDC Provider" note field directly after the Expiration Date
 * field in the Account Information fieldset on the admin User edit page.
 *
 * Uses afterGetFormHtml to ensure the user model is fully loaded and setValues()
 * has already run, so $subject->getUser() returns the actual admin user.
 *
 * Targets: Magento\User\Block\User\Edit\Tab\Main
 */
class OidcUserInfoPlugin
{
    private readonly UserProviderResource $userProviderResource;

    public function __construct(UserProviderResource $userProviderResource)
    {
        $this->userProviderResource = $userProviderResource;
    }

    /**
     * After getFormHtml — add an OIDC Provider note below the Expiration Date field.
     *
     * At this point the user model is guaranteed to be loaded on the block,
     * so $subject->getUser()->getId() returns the real admin user ID.
     *
     * @param  Main   $subject
     * @param  string $result  The rendered form HTML
     * @return string
     */
    public function afterGetFormHtml(Main $subject, string $result): string
    {
        $form = $subject->getForm();
        if (!$form) {
            return $result;
        }

        // Guard: field already added (e.g. multiple render passes)
        if ($form->getElement('oidc_provider_info')) {
            return $result;
        }

        $user   = $subject->getUser();
        $userId = $user ? (int) $user->getId() : 0;
        error_log('OidcUserInfoPlugin: userId=' . $userId);

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

        // Re-render the form with the new field included
        return $form->toHtml();
    }
}
