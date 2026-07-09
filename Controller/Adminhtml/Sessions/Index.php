<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Sessions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Admin controller — OIDC Session activity listing.
 *
 * Route: GET /admin/m2oidc/sessions/index
 *
 * Renders a read-only grid of all users who have authenticated via OIDC SSO,
 * showing the user's email, type (admin/customer), provider, and first-seen date.
 * Protected by the module's oidc_sessions ACL resource.
 */
class Index extends Action implements HttpGetActionInterface
{
    /** ACL resource required to view OIDC session activity.
     * @var string */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::oidc_sessions';

    /** @var PageFactory */
    private readonly PageFactory $pageFactory;

    /**
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
     * Render the OIDC session activity grid page.
     */
    #[\Override]
    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page->setActiveMenu('M2Oidc_OAuth::oidc_sessions');
        $page->getConfig()->getTitle()->prepend((string) __('OIDC Session Activity'));
        $page->addBreadcrumb((string) __('M2Oidc OIDC'), (string) __('M2Oidc OIDC'));
        $page->addBreadcrumb((string) __('Session Activity'), (string) __('Session Activity'));
        return $page;
    }
}
