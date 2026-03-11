<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User\Block;

use Closure;
use Magento\Framework\Escaper;
use Magento\Framework\Registry;
use Magento\User\Block\User\Edit\Tab\Main;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Adds a read-only "OIDC Provider" note field directly after the Expiration Date
 * field in the Account Information fieldset on the admin User edit page.
 *
 * Uses aroundGetFormHtml + Registry to ensure the user is fully loaded.
 *
 * @psalm-suppress DeprecatedClass
 */
class OidcUserInfoPlugin
{
    /** @var UserProviderResource */
    private readonly UserProviderResource $userProviderResource;

    /** @var Registry */
    private readonly Registry $registry;

    /** @var Escaper */
    private readonly Escaper $escaper;

    /**
     * Constructor.
     *
     * @param UserProviderResource $userProviderResource
     * @param Registry             $registry
     * @param Escaper              $escaper
     */
    public function __construct(
        UserProviderResource $userProviderResource,
        Registry $registry,
        Escaper $escaper
    ) {
        $this->userProviderResource = $userProviderResource;
        $this->registry = $registry;
        $this->escaper = $escaper;
    }

    /**
     * Around getFormHtml — inject OIDC Provider note after the form is prepared.
     *
     * The admin user is loaded from the registry key 'permissions_user',
     * which is set by the Edit controller before block rendering.
     *
     * @param Main    $subject
     * @param Closure $proceed
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
            ? $this->escaper->escapeHtml($info['display_name'])
              . ' (' . $this->escaper->escapeHtml($info['created_at']) . ')'
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
