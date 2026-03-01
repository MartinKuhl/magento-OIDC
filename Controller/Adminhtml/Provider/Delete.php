<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;

/**
 * Admin controller — Delete OIDC Provider (MP-06).
 *
 * Route: POST /admin/mooauth/provider/delete
 *
 * Requires a valid CSRF form key (Magento validates this automatically for
 * admin POST actions). Guards against deleting the last remaining provider
 * to prevent a lockout scenario.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /**
     * Initialize provider delete controller.
     *
     * @param Context                          $context
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     * @param OAuthUtility                     $oauthUtility
     */
    public function __construct(
        Context $context,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        OAuthUtility $oauthUtility
    ) {
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        $this->oauthUtility      = $oauthUtility;
        parent::__construct($context);
    }

    /**
     * Delete the requested provider and redirect to the list.
     */
    #[\Override]
    public function execute(): Redirect
    {
        $redirect   = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $providerId = (int) $this->getRequest()->getParam('id', 0);

        if ($providerId <= 0) {
            $this->messageManager->addErrorMessage((string) __('Invalid provider ID.'));
            return $redirect;
        }

        // Guard: refuse to delete the last configured provider
        $allProviders = $this->oauthUtility->getOAuthClientApps();
        if (count($allProviders) <= 1) {
            $this->messageManager->addErrorMessage(
                (string) __('Cannot delete the last OIDC provider. At least one provider must remain configured.')
            );
            return $redirect;
        }

        try {
            $model = $this->clientAppsFactory->create();
            $this->appResource->load($model, $providerId);

            if (!$model->getId()) {
                $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                return $redirect;
            }

            $this->appResource->delete($model);
            $this->messageManager->addSuccessMessage(
                (string) __(
                    'Provider "%1" has been deleted.',
                    $model->getData('display_name') ?: $model->getData('app_name')
                )
            );

            $this->oauthUtility->customlog(
                sprintf('OAuthProvider: Deleted provider id=%d name=%s', $providerId, $model->getData('app_name'))
            );

        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                (string) __('An error occurred while deleting the provider: %1', $e->getMessage())
            );
        }

        return $redirect;
    }
}
