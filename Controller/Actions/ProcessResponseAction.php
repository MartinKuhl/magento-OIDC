<?php
namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Backwards-compatible shim for older flow.
 *
 * @deprecated This controller has been superseded by the service layer
 *             and `CheckAttributeMappingAction`.
 *             Use `\MiniOrange\OAuth\Model\Service\OidcAuthenticationService`
 *             together with `CheckAttributeMappingAction` instead.
 * @see        \MiniOrange\OAuth\Model\Service\OidcAuthenticationService
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class ProcessResponseAction extends BaseAction
{
    /**
     * @var array|object|null Raw userinfo response (array or stdClass)
     */
    private $userInfoResponse;

    private readonly \MiniOrange\OAuth\Controller\Actions\CheckAttributeMappingAction $attrMappingAction;

    /**
     * Initialize process response action.
     */
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
     *
     * @throws IncorrectUserInfoDataException
     */
    #[\Override]
    public function execute()
    {
        $this->oauthUtility->customlog("processResponseAction: execute");

        try {
            $this->validateUserInfoData();
        } catch (IncorrectUserInfoDataException $e) {
            $this->oauthUtility->customlog(
                "ERROR: Invalid user info data from OAuth provider - " . $e->getMessage()
            );
            $this->messageManager->addErrorMessage(
                __('Authentication failed: Invalid user information received from identity provider.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        $userInfoResponse = $this->userInfoResponse;

        // flatten the nested OAuth response
        $flattenedUserInfoResponse = [];
        $flattenedUserInfoResponse = $this->getflattenedArray("", $userInfoResponse, $flattenedUserInfoResponse);

        // First try the configured email attribute
        $emailAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        if ($this->oauthUtility->isBlank($emailAttribute)) {
            $emailAttribute = OAuthConstants::DEFAULT_MAP_EMAIL;
        }

        $userEmail = '';
        if (isset($flattenedUserInfoResponse[$emailAttribute])
            && filter_var($flattenedUserInfoResponse[$emailAttribute], FILTER_VALIDATE_EMAIL)
        ) {
            $userEmail = $flattenedUserInfoResponse[$emailAttribute];
            $this->oauthUtility->customlog(
                "ProcessResponseAction: Email found via configured attribute '$emailAttribute': " . $userEmail
            );
        }

        // Fallback to recursive search if configured attribute didn't yield an email
        if (empty($userEmail)) {
            $userEmail = $this->findUserEmail($userInfoResponse);
        }

        if (empty($userEmail)) {
            $this->messageManager->addErrorMessage(
                __('Email address not received. Please check attribute mapping.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
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

        // Debug: Check what is returned
        $this->oauthUtility->customlog(
            "ProcessResponseAction: attrMappingAction returned: " .
            get_class($result)
        );

        return $result;
    }

    private const int MAX_RECURSION_DEPTH = 5;

    /**
     * Recursively search for an email address in the user info array
     *
     * @param  array|object $arr
     * @param  int          $depth
     * @return string
     */
    private function findUserEmail($arr, int|float $depth = 0)
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return "";
        }

        if (is_object($arr)) {
            $arr = (array) $arr;
        }

        if (!is_array($arr)) {
            return "";
        }

        foreach ($arr as $value) {
            if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->oauthUtility->customlog("ProcessResponseAction: findUserEmail found: " . (string) $value);
                return $value;
            }

            if (is_array($value) || is_object($value)) {
                $email = $this->findUserEmail($value, $depth + 1);
                if (!empty($email)) {
                    return $email;
                }
            }
        }
        return "";
    }

    /**
     * Flatten a multidimensional array with dot notation keys
     *
     * @param  string       $keyprefix
     * @param  array|object $arr
     * @param  int          $depth
     * @return array
     */
    private function getflattenedArray(?string $keyprefix, $arr, array &$flattenedattributesarray, int|float $depth = 0)
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return $flattenedattributesarray;
        }

        foreach ($arr as $key => $resource) {
            if (is_array($resource) || is_object($resource)) {
                $newPrefix = $keyprefix === null || $keyprefix === '' || $keyprefix === '0' ? $key : $keyprefix . "." . $key;
                $this->getflattenedArray($newPrefix, $resource, $flattenedattributesarray, $depth + 1);
            } else {
                $newKey = $keyprefix === null || $keyprefix === '' || $keyprefix === '0' ? $key : $keyprefix . "." . $key;
                $flattenedattributesarray[$newKey] = $resource;
            }
        }
        return $flattenedattributesarray;
    }

    /**
     * Function checks if the
     *
     * @throws IncorrectUserInfoDataException
     */
    private function validateUserInfoData(): void
    {
        $this->oauthUtility->customlog("processResponseAction: validateUserInfoData");

        $userInfo = $this->userInfoResponse;
        if (is_object($userInfo) && isset($userInfo->error)) {
            throw new IncorrectUserInfoDataException();
        }
        if (is_array($userInfo) && isset($userInfo['error'])) {
            throw new IncorrectUserInfoDataException();
        }
        if (empty($userInfo)) {
            throw new IncorrectUserInfoDataException();
        }
    }

    /**
     * Setter for the UserInfo Parameter.
     *
     * @param array|object|null $userInfoResponse
     */
    public function setUserInfoResponse($userInfoResponse): static
    {
        $this->oauthUtility->customlog("processResponseAction: setUserInfoResponse");

        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }
}
