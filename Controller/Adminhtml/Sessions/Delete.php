<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Sessions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Admin controller — Delete a single OIDC session activity record.
 *
 * Route: POST /admin/m2oidc/sessions/delete
 *
 * Requires a valid CSRF form key (Magento validates this automatically for
 * admin POST actions). Used to remove orphaned records whose Magento user
 * has already been deleted.
 */
class Delete extends Action implements HttpPostActionInterface
{
    /**
     * @var string
     */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::oidc_sessions';

    /** @var UserProviderResource */
    private readonly UserProviderResource $userProviderResource;

    /**
     * @param Context              $context
     * @param UserProviderResource $userProviderResource
     */
    public function __construct(
        Context $context,
        UserProviderResource $userProviderResource
    ) {
        $this->userProviderResource = $userProviderResource;
        parent::__construct($context);
    }

    /**
     * Delete the requested session record and redirect to the list.
     */
    #[\Override]
    public function execute(): Redirect
    {
        $redirect  = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $sessionId = (int) $this->getRequest()->getParam('id', 0);

        if ($sessionId <= 0) {
            $this->messageManager->addErrorMessage((string) __('Invalid session record ID.'));
            return $redirect;
        }

        try {
            $this->userProviderResource->deleteById($sessionId);
            $this->messageManager->addSuccessMessage(
                (string) __('Session record #%1 has been deleted.', $sessionId)
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                (string) __('An error occurred while deleting the session record: %1', $e->getMessage())
            );
        }

        return $redirect;
    }
}
