<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml;

use Magento\Framework\View\Element\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Block to display OIDC error messages on admin login page
 */
class OidcErrorMessage extends Template
{
    // No constructor required; parent constructor is sufficient.
    /**
     * Get OIDC error message from URL parameter.
     *
     * Returns the raw decoded string. Callers MUST escape the return value before
     * rendering it in HTML (e.g. via $escaper->escapeHtml()). The template
     * oidc_error_message.phtml already does this — do not add raw output in any
     * new template without escaping.
     *
     * @return string|null Decoded message, or null if absent / invalid
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
