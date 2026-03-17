<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;

/**
 * Admin controller — Delete OIDC Provider (MP-06).
 *
 * Route: POST /admin/m2oidc/provider/delete
 *
 * Requires a valid CSRF form key (Magento validates this automatically for
 * admin POST actions). Guards against deleting the last remaining provider
 * to prevent a lockout scenario.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'M2Oidc_OAuth::oauth_settings';

    /** @var M2oidcOauthClientAppsFactory */
    private readonly M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /**
     * Initialize provider delete controller.
     *
     * @param Context                      $context
     * @param M2oidcOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                  $appResource
     * @param OAuthUtility                 $oauthUtility
     */
    public function __construct(
        Context $context,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
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
