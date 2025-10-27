<?php
namespace MiniOrange\OAuth\Block\Adminhtml;

use Magento\Framework\View\Element\Template;
use Magento\Framework\App\RequestInterface;

/**
 * Block to display OIDC error messages on admin login page
 */
class OidcErrorMessage extends Template
{
    protected $request;

    public function __construct(
        Template\Context $context,
        RequestInterface $request,
        array $data = []
    ) {
        $this->request = $request;
        parent::__construct($context, $data);
    }

    /**
     * Get OIDC error message from URL parameter
     * 
     * @return string|null
     */
    public function getOidcErrorMessage()
    {
        $encodedMessage = $this->request->getParam('oidc_error');
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
