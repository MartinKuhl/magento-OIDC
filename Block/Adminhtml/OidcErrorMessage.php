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
        $encoded = $this->getRequest()->getParam('oidc_error');
        if (empty($encoded)) {
            return null;
        }
        // All error senders base64-encode the message before putting it in the URL.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $decoded = base64_decode((string) $encoded, true);
        if ($decoded === false || trim($decoded) === '') {
            return null;
        }
        return trim($decoded);
    }

    /**
     * Check if there is an OIDC error
     */
    public function hasOidcError(): bool
    {
        return $this->getOidcErrorMessage() !== null;
    }
}
