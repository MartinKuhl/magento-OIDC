<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\RequiredFieldsException;
use MiniOrange\OAuth\Helper\Exception\SupportQueryRequiredFieldsException;

/**
 * The base action class that is inherited by each of the admin action
 * class. It consists of certain common functions that needs to
 * be inherited by each of the action class. Extends the
 * \Magento\Backend\App\Action class which is usually
 * extended by Admin Controller class.
 *
 * \Magento\Backend\App\Action is extended instead of
 * \Magento\Framework\App\Action\Action so that we can check Access Level
 * Permissions before calling the execute fucntion
 */
abstract class BaseAdminAction extends \Magento\Backend\App\Action
{

    /**
     * @var \MiniOrange\OAuth\Helper\OAuthUtility
     */
    protected $oauthUtility;

    /**
     * @var \Magento\Backend\App\Action\Context
     */
    protected $context;

    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\Auth\AuthorizationInterface
     */
    protected $_authorization;

    /**
     * Initialize base admin action.
     *
     * @param \Magento\Backend\App\Action\Context          $context
     * @param \Magento\Framework\View\Result\PageFactory   $resultPageFactory
     * @param \MiniOrange\OAuth\Helper\OAuthUtility        $oauthUtility
     * @param \Magento\Framework\Message\ManagerInterface  $messageManager
     * @param \Psr\Log\LoggerInterface                     $logger
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->oauthUtility = $oauthUtility;
        $this->resultPageFactory = $resultPageFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Determine whether the incoming request is attempting to save a form option.
     *
     * Checks for the presence of an `option` key in the request data.
     *
     * @param  array $params Request parameters
     * @return bool
     */
    protected function isFormOptionBeingSaved(array $params)
    {
        return isset($params['option']);
    }

    /**
     * Validate that required fields are present and not blank.
     *
     * Expects an associative array where keys map to values or an array of
     * required keys paired with the source array. Throws {@see RequiredFieldsException}
     * when a required value is missing or blank.
     *
     * @param array $array Required keys or legacy associative mapping
     *
     * @throws RequiredFieldsException
     *
     * @return void
     */
    protected function checkIfRequiredFieldsEmpty(array $array)
    {
        foreach ($array as $key => $value) {
            if ((is_array($value) && (!isset($value[$key]) || $this->oauthUtility->isBlank($value[$key])))
                || $this->oauthUtility->isBlank($value)
            ) {
                throw new RequiredFieldsException();
            }
        }
    }

    /**
     * Validate support query specific fields and translate the exception type.
     *
     * @param array $array Required fields mapping
     *
     * @throws SupportQueryRequiredFieldsException
     *
     * @return void
     */
    public function checkIfSupportQueryFieldsEmpty(array $array)
    {
        try {
            $this->checkIfRequiredFieldsEmpty($array);
        } catch (RequiredFieldsException $e) {
            $this->oauthUtility->customlog("ERROR: Required fields missing in admin context");
            throw new SupportQueryRequiredFieldsException();
        }
    }

    /**
     * This function is abstract that needs to be implemented by each Action Class
     */
    abstract public function execute();
}
