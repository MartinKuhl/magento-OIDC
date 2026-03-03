<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User\Block;

use Closure;
use Magento\User\Block\User\Edit\Tab\Main;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Adds a read-only "OIDC Provider" note field directly after the Expiration Date
 * field in the Account Information fieldset on the admin User edit page.
 *
 * Uses aroundGetFormHtml so that $proceed() triggers _prepareForm() first,
 * ensuring the user model is fully loaded before we access it.
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
     * Around getFormHtml — inject OIDC Provider note after the form is prepared.
     *
     * $proceed() calls parent::getFormHtml() which triggers _prepareForm(),
     * loading the user from the registry and calling setValues().
     * After $proceed(), getUser() returns the real admin user.
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
