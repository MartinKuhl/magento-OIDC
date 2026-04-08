<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\User\Block;

use Closure;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Escaper;
use Magento\Framework\Registry;
use Magento\User\Block\User\Edit\Tab\Main;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

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

    /** @var BackendUrl */
    private readonly BackendUrl $backendUrl;

    /**
     * Constructor.
     *
     * @param UserProviderResource $userProviderResource
     * @param Registry             $registry
     * @param Escaper              $escaper
     * @param BackendUrl           $backendUrl
     */
    public function __construct(
        UserProviderResource $userProviderResource,
        Registry $registry,
        Escaper $escaper,
        BackendUrl $backendUrl
    ) {
        $this->userProviderResource = $userProviderResource;
        $this->registry = $registry;
        $this->escaper = $escaper;
        $this->backendUrl = $backendUrl;
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

        $displayName = $info ? $info['display_name'] : '';
        $createdAt   = $info ? $info['created_at'] : '';
        /** @var string $escapedDisplayName */
        $escapedDisplayName = $this->escaper->escapeHtml($displayName);
        /** @var string $escapedCreatedAt */
        $escapedCreatedAt = $this->escaper->escapeHtml($createdAt);
        $providerText = $info
            ? $escapedDisplayName . ' (' . $escapedCreatedAt . ')'
            : 'none';

        $fieldset = $form->getElement('base_fieldset');
        if ($fieldset) {
            $unlinkHtml = '';
            if ($info && $userId > 0) {
                $configJson = $this->escaper->escapeHtmlAttr((string) json_encode([
                    'unlinkUrl'      => $this->backendUrl->getUrl('m2oidc/provider/unlinkuser'),
                    'userType'       => 'admin',
                    'userId'         => $userId,
                    'confirmMessage' => (string) __(
                        'Are you sure you want to unlink this admin user from their OIDC provider?'
                        . ' They will be able to link to a different provider on next SSO login.'
                    ),
                    'valueSelector'  => '.admin__field-value',
                ]));
                $unlinkHtml = ' <button type="button"'
                    . ' class="action-default m2oidc-unlink-btn"'
                    . ' style="margin-left:10px; cursor:pointer;"'
                    . ' data-m2oidc-config="' . $configJson . '">'
                    . $this->escaper->escapeHtmlAttr((string) __('Unlink IdP'))
                    . '</button>';
            }

            $fieldset->addField(
                'oidc_provider_info',
                'note',
                [
                    'label' => __('OIDC Provider'),
                    'text'  => $providerText . $unlinkHtml,
                ],
                'expiration'
            );
        }

        return $form->toHtml();
    }
}
