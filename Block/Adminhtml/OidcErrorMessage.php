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
     */
    public function getOidcErrorMessage(): ?string
    {
        // getParam() already URL-decodes the value; no additional decoding needed.
        $message = $this->getRequest()->getParam('oidc_error');
        if ($message) {
            $message = trim((string) $message);
            return $message !== '' ? $message : null;
        }
        return null;
    }

    /**
     * Check if there is an OIDC error
     */
    public function hasOidcError(): bool
    {
        return $this->getOidcErrorMessage() !== null;
    }
}
