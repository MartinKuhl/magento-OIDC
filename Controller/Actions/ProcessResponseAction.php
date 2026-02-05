<?php
namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use MiniOrange\OAuth\Helper\Exception\UserEmailNotFoundException;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Handles processing of SAML Responses from the IDP. Process the SAML Response
 * from the IDP and detect if it's a valid response from the IDP. Validate the
 * certificates and the SAML attributes and Update existing user attributes
 * and groups if necessary. Log the user in.
 */
class ProcessResponseAction extends BaseAction
{
    private $userInfoResponse;
    private $testAction;
    private $processUserAction;

    /**
     * @var CheckAttributeMappingAction
     */
    private $attrMappingAction;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Controller\Actions\CheckAttributeMappingAction $attrMappingAction
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->attrMappingAction = $attrMappingAction;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function to execute the classes function.
     * @throws IncorrectUserInfoDataException
     */
    public function execute()
    {
        $this->oauthUtility->customlog("processResponseAction: execute");

        try {
            $this->validateUserInfoData();
        } catch (IncorrectUserInfoDataException $e) {
            $this->oauthUtility->customlog("ERROR: Invalid user info data from OAuth provider - " . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Authentication failed: Invalid user information received from identity provider.'));
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        $userInfoResponse = $this->userInfoResponse;

        // flatten the nested OAuth response
        $flattenedUserInfoResponse = [];
        $flattenedUserInfoResponse = $this->getflattenedArray("", $userInfoResponse, $flattenedUserInfoResponse);

        $userEmail = $this->findUserEmail($userInfoResponse);
        if (empty($userEmail)) {
            return $this->getResponse()->setBody("Email address not received. Please check attribute mapping.");
        }

        // Extract loginType from userInfoResponse (defaults to customer for backward compatibility)
        $loginType = OAuthConstants::LOGIN_TYPE_CUSTOMER;
        if (is_array($userInfoResponse) && isset($userInfoResponse['loginType'])) {
            $loginType = $userInfoResponse['loginType'];
        } elseif (is_object($userInfoResponse) && isset($userInfoResponse->loginType)) {
            $loginType = $userInfoResponse->loginType;
        }
        $this->oauthUtility->customlog("ProcessResponseAction: loginType = " . $loginType);

        $result = $this->attrMappingAction->setUserInfoResponse($userInfoResponse)
            ->setFlattenedUserInfoResponse($flattenedUserInfoResponse)
            ->setUserEmail($userEmail)
            ->setLoginType($loginType)
            ->execute();

        // Debug: Prüfe was zurückkommt
        $this->oauthUtility->customlog("ProcessResponseAction: attrMappingAction returned: " .
            ($result ? get_class($result) : 'NULL'));

        return $result;
    }

    private function findUserEmail($arr)
    {
        $this->oauthUtility->customlog("processResponseAction: findUserEmail");
        if ($arr) {
            foreach ($arr as $value) {
                if (is_array($value)) {
                    $value = $this->findUserEmail($value);
                }
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->oauthUtility->customlog("processResponseAction: findUserEmail :" . $value);
                    return $value;
                }
            }
            return "";
        }
    }

    private function getflattenedArray($keyprefix, $arr, &$flattenedattributesarray)
    {
        foreach ($arr as $key => $resource) {
            if (is_array($resource) || is_object($resource)) {
                if (!empty($keyprefix)) {
                    $keyprefix .= ".";
                }
                $this->getflattenedArray($keyprefix . $key, $resource, $flattenedattributesarray);
            } else {
                if (!empty($keyprefix)) {
                    $key = $keyprefix . "." . $key;
                }
                $flattenedattributesarray[$key] = $resource;
            }
        }
        return $flattenedattributesarray;
    }

    /**
     * Function checks if the
     * @throws IncorrectUserInfoDataException
     */
    private function validateUserInfoData()
    {
        $this->oauthUtility->customlog("processResponseAction: validateUserInfoData");

        $userInfo = $this->userInfoResponse;
        if (isset($userInfo->error)) {
            throw new IncorrectUserInfoDataException();
        }
    }

    /** Setter for the UserInfo Parameter */
    public function setUserInfoResponse($userInfoResponse)
    {
        $this->oauthUtility->customlog("processResponseAction: setUserInfoResponse");

        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }
}
