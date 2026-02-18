<?php
/**
 * OIDC Identity Field Plugin
 *
 * Removes the "required" validation from the identity verification password field
 * for OIDC-authenticated admin users. This works in conjunction with the server-side
 * OidcIdentityVerificationPlugin to provide a complete bypass of password re-authentication.
 *
 * Applies to:
 * - Magento\User\Block\User\Edit\Tab\Main (User edit form)
 * - Magento\User\Block\Role\Tab\Info (Role edit form)
 * - Magento\Backend\Block\System\Account\Edit\Form (Account settings form)
 *
 * @package MiniOrange\OAuth\Plugin\User\Block
 */
namespace MiniOrange\OAuth\Plugin\User\Block;

use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcIdentityFieldPlugin
{
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    protected \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /**
     * Constructor
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        OAuthUtility $oauthUtility
    ) {
        $this->cookieManager = $cookieManager;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * After setForm - modify the current_password field for OIDC users
     *
     * Removes the required attribute and required-entry CSS class from the
     * identity verification password field when the user is OIDC-authenticated.
     *
     * @param  mixed $subject The form block
     * @param  mixed $result  The result of setForm
     * @return mixed
     */
    public function afterSetForm($subject, $result)
    {
        // Read OIDC flag from cookie (set by Oidccallback controller)
        $cookieValue = $this->cookieManager->getCookie('oidc_authenticated');
        $isOidcAuth = ($cookieValue === '1');

        $this->oauthUtility->customlog("OidcIdentityFieldPlugin: afterSetForm called for " . get_class($subject));
        $this->oauthUtility->customlog("OidcIdentityFieldPlugin: Cookie value = " . var_export($cookieValue, true));
        $this->oauthUtility->customlog("OidcIdentityFieldPlugin: isOidcAuth = " . var_export($isOidcAuth, true));

        // Only modify for OIDC-authenticated users
        if ($isOidcAuth) {
            $form = $subject->getForm();
            $this->oauthUtility->customlog("OidcIdentityFieldPlugin: Form exists = " . ($form ? 'yes' : 'no'));

            if ($form) {
                $field = $form->getElement('current_password');
                $this->oauthUtility->customlog("OidcIdentityFieldPlugin: Field found = " . ($field ? 'yes' : 'no'));

                if ($field) {
                    // Remove required attribute
                    $field->setRequired(false);

                    // Remove required-entry CSS class (used by Magento's JS validation)
                    $currentClass = $field->getClass();
                    $newClass = str_replace('required-entry', '', $currentClass);
                    $field->setClass(trim($newClass));

                    $this->oauthUtility->customlog("OidcIdentityFieldPlugin: Field modified - required removed");
                }
            }
        }

        return $result;
    }
}
