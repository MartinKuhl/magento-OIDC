<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin controller — OIDC Provider listing (MP-06).
 *
 * Route: GET /admin/mooauth/provider/index
 *
 * Renders a grid of all configured OIDC providers with Add / Edit / Delete
 * action links. Protected by the module's admin ACL resource so only
 * authenticated admins with the OAuth settings permission can access it.
 */
class Index extends Action implements HttpGetActionInterface
{
    /** ACL resource required to access provider management. */
    public const ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var PageFactory */
    private readonly PageFactory $pageFactory;

    /**
     * Initialize provider list controller.
     *
     * @param Context     $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory
    ) {
        $this->pageFactory = $pageFactory;
        parent::__construct($context);
    }

    /**
     * Render the provider management grid page.
     */
    #[\Override]
    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page->setActiveMenu('MiniOrange_OAuth::provider_management');
        $page->getConfig()->getTitle()->prepend((string) __('OIDC Provider Management'));
        $page->addBreadcrumb((string) __('MiniOrange OIDC'), (string) __('MiniOrange OIDC'));
        $page->addBreadcrumb((string) __('Manage Providers'), (string) __('Manage Providers'));
        return $page;
    }
}
