<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;

/**
 * Admin edit-form container for the OIDC Provider.
 *
 * Renders the Magento-standard page header (breadcrumbs, action buttons) and
 * wraps the Widget\Tabs sidebar form in a <form> element that posts to
 * mooauth/provider/save.
 */
class Edit extends Container
{
    /**
     * Override mode so _prepareLayout() does not auto-create a 'form' child block.
     * The three tabs are registered via layout XML instead.
     *
     * @var string
     */
    protected $_mode = 'tabs';

    /** @var Registry */
    private Registry $registry;

    /** @var MiniorangeOauthClientAppsFactory */
    private MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private AppResource $appResource;

    /** @var FormKey */
    private FormKey $formKeyHelper;

    /**
     * @param Context                          $context
     * @param Registry                         $registry
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     * @param FormKey                          $formKey
     * @param array                            $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        FormKey $formKey,
        array $data = []
    ) {
        $this->registry          = $registry;
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        $this->formKeyHelper     = $formKey;
        parent::__construct($context, $data);
    }

    /**
     * Set block identifiers, configure buttons, and load the provider into the registry.
     */
    protected function _construct(): void
    {
        $this->_objectId   = 'id';
        $this->_blockGroup = 'MiniOrange_OAuth';
        $this->_controller = 'adminhtml_provider';

        parent::_construct();

        $providerId = (int) $this->getRequest()->getParam('id', 0);

        if ($providerId > 0) {
            // Load the provider model and share it with tab blocks via Core Registry.
            $model = $this->clientAppsFactory->create();
            $this->appResource->load($model, $providerId);

            if ($model->getId()) {
                $this->registry->register('current_oidc_provider', $model);
            }
        } else {
            // No delete button when creating a new provider.
            $this->buttonList->remove('delete');
        }

        // Add "Save and Continue Edit" button (sets back=edit and submits the form).
        $this->buttonList->add(
            'save_and_continue',
            [
                'label'   => __('Save and Continue Edit'),
                'class'   => 'save',
                'onclick' => "document.getElementById('back').value='edit';"
                           . "document.getElementById('edit_form').submit();",
            ]
        );
    }

    /**
     * Skip Widget\Form\Container::_prepareLayout() which would try to auto-instantiate
     * MiniOrange\OAuth\Block\Adminhtml\Provider\Tabs\Edit\Form (does not exist).
     * Tab blocks are registered in the layout XML instead.
     *
     * @return $this
     */
    protected function _prepareLayout(): self
    {
        return $this;
    }

    /**
     * Render the <form> element wrapping the Widget\Tabs child block.
     *
     * @return string
     */
    public function getFormHtml(): string
    {
        $providerId = (int) $this->getRequest()->getParam('id', 0);
        $url        = $this->escapeUrl($this->getUrl('mooauth/provider/save'));
        $formKey    = $this->escapeHtmlAttr($this->formKeyHelper->getFormKey());

        return '<form id="edit_form"'
             . ' action="' . $url . '"'
             . ' method="post"'
             . ' enctype="multipart/form-data">'
             . '<input type="hidden" name="form_key" value="' . $formKey . '">'
             . '<input type="hidden" id="back" name="back" value="">'
             . '<input type="hidden" name="id" value="' . $providerId . '">'
             . $this->getChildHtml('mooauth_provider_edit_tabs')
             . '</form>';
    }

    /**
     * Return the page header text (used by the container template).
     *
     * @return Phrase|string
     */
    public function getHeaderText(): Phrase|string
    {
        $provider = $this->registry->registry('current_oidc_provider');

        if ($provider && $provider->getId()) {
            $name = (string) ($provider->getData('display_name') ?: $provider->getData('app_name'));
            return __("Edit Provider '%1'", $this->escapeHtml($name));
        }

        return __('New OIDC Provider');
    }
}
