<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Admin edit-form container for the OIDC Provider.
 *
 * Renders the Magento-standard page header (breadcrumbs, action buttons) and
 * wraps the Widget\Tabs sidebar form in a <form> element that posts to
 * mooauth/provider/save.
 */
class Edit extends Container
{
    /** @var Registry */
    private Registry $registry;

    /** @var FormKey */
    private FormKey $formKeyHelper;

    /** @var StoreManagerInterface */
    private readonly StoreManagerInterface $storeManager;

    /**
     * @param Context               $context
     * @param Registry              $registry
     * @param FormKey               $formKey
     * @param StoreManagerInterface $storeManager
     * @param array                 $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormKey $formKey,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->registry      = $registry;
        $this->formKeyHelper = $formKey;
        $this->storeManager  = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * Set block identifiers and configure buttons.
     *
     * The provider model is loaded and registered in the Core Registry by
     * Controller/Adminhtml/Provider/Edit before any block is instantiated, so
     * we must not re-register it here (would throw a RuntimeException).
     *
     * After parent::_construct() we clear $_blockGroup so that
     * Widget\Form\Container::_prepareLayout() skips auto-creating a non-existent
     * Form child block, while still letting Widget\Container::_prepareLayout()
     * run and push buttons to the toolbar.
     */
    protected function _construct(): void
    {
        $this->_objectId   = 'id';
        $this->_blockGroup = 'MiniOrange_OAuth';
        $this->_controller = 'adminhtml_provider';

        // Adds Save/Back/Reset; adds Delete only when the id URL param is > 0.
        parent::_construct();

        // Prevent Widget\Form\Container::_prepareLayout() from auto-creating
        // MiniOrange\OAuth\Block\Adminhtml\Provider\Tabs\Edit\Form (does not exist).
        // Clearing _blockGroup makes the guard condition false while still letting
        // Widget\Container::_prepareLayout() run to push buttons into the toolbar.
        $this->_blockGroup = '';

        // Add "Save and Continue Edit" button.
        $this->buttonList->add(
            'save_and_continue',
            [
                'label'   => __('Save and Continue Edit'),
                'class'   => 'save',
                'onclick' => "document.getElementById('back').value='edit';"
                           . "jQuery('#edit_form').trigger('save');",
            ]
        );

        // Add "Save & Test OIDC Flow" button — only shown when editing an existing provider.
        // Submits the form with back=test so changes are saved before the test flow starts.
        $provider = $this->registry->registry('current_oidc_provider');
        if ($provider && $provider->getId()) {
            $appName = (string) $provider->getData('app_name');
            if ($appName) {
                $this->buttonList->add(
                    'test_oidc_flow',
                    [
                        'label'      => __('Save & Test OIDC Flow'),
                        'class'      => 'save',
                        'onclick'    => "document.getElementById('back').value='test';"
                                     . "jQuery('#edit_form').trigger('save');",
                        'sort_order' => 40,
                    ]
                );
            }
        }
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

    /**
     * Append popup-opening JS when the page was reached via "Save & Test OIDC Flow".
     *
     * After Save.php redirects back here with pending_test=1, the page reloads
     * normally while this script opens the OIDC test flow in a popup window.
     * The frontend URL is used because SendAuthorizationRequest is a frontend controller.
     */
    #[\Override]
    protected function _toHtml(): string
    {
        $html = parent::_toHtml();

        if (!$this->getRequest()->getParam('pending_test')) {
            return $html;
        }

        $provider = $this->registry->registry('current_oidc_provider');
        if (!$provider || !$provider->getData('app_name')) {
            return $html;
        }

        $testUrl = $this->storeManager->getStore()->getUrl(
            'mooauth/actions/sendAuthorizationRequest',
            [
                'app_name' => $provider->getData('app_name'),
                'option'   => OAuthConstants::TEST_CONFIG_OPT,
            ]
        );

        $html .= '<script>window.open('
               . json_encode($testUrl)
               . ', "TEST_OIDC", "scrollbars=1,width=800,height=600");</script>';

        return $html;
    }
}
