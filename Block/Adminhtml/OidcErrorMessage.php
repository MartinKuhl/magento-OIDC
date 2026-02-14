<?php
namespace MiniOrange\OAuth\Block\Adminhtml;

use Magento\Framework\View\Element\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Block to display OIDC error messages on admin login page
 */
class OidcErrorMessage extends Template
{
    // No constructor required; parent constructor is sufficient.

    /**
     * Get OIDC error message from URL parameter
     *
     * @return string|null
     */
    public function getOidcErrorMessage()
    {
        $encodedMessage = $this->getRequest()->getParam('oidc_error');
        if ($encodedMessage) {
            $decoded = rawurldecode($encodedMessage);
            return $decoded === '' ? null : $decoded;
        }
        return null;
    }

    /**
     * Check if there is an OIDC error
     *
     * @return bool
     */
    public function hasOidcError()
    {
        return $this->getOidcErrorMessage() !== null;
    }
}
