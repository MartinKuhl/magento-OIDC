<?php
namespace MiniOrange\OAuth\Block\Adminhtml;

use Magento\Framework\View\Element\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Block to display OIDC error messages on admin login page
 */
class OidcErrorMessage extends Template
{
    /**
     * Constructor
     *
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get OIDC error message from URL parameter
     *
     * @return string|null
     */
    public function getOidcErrorMessage()
    {
        $encodedMessage = $this->getRequest()->getParam('oidc_error');
        if ($encodedMessage) {
            return base64_decode($encodedMessage);
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
