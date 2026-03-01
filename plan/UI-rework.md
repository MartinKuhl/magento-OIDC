Claude’s Plan
Plan: Full Magento-Native Admin UI for OIDC Plugin
Context
The user wants the OIDC plugin to use standard Magento admin UI components matching the
screenshots of the Customer module:

Provider list → Magento UI Grid (filters, search, pagination, mass-actions)
Add / Edit provider → Widget Tabs with left-sidebar navigation: Provider Settings | OAuth Settings | Attribute Mapping
Sign In Settings → standalone page, accessible from the left admin menu
Custom navbar (navbar.phtml) removed from all layouts
Left Magento menu simplified: only "Manage Providers" + "Sign In Settings"
All form data already flows through Controller/Adminhtml/Provider/Save.php (handles all
fields: provider identity + OAuth endpoints + attribute mapping). That controller needs no
logic changes, only a small addition to store the model in the Core Registry.

Files to CREATE
1. Ui/Component/Listing/Column/Actions.php
Actions column renderer (Edit / Delete links per row):


namespace MiniOrange\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    private UrlInterface $urlBuilder;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) ($item['id'] ?? 0);
            $item[$this->getData('name')] = [
                'edit'   => [
                    'href'  => $this->urlBuilder->getUrl('mooauth/provider/edit', ['id' => $id]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href'    => $this->urlBuilder->getUrl('mooauth/provider/delete', ['id' => $id]),
                    'label'   => __('Delete'),
                    'confirm' => [
                        'title'   => __('Delete Provider'),
                        'message' => __('Are you sure you want to delete this provider?'),
                    ],
                    'post'    => true,
                ],
            ];
        }
        return $dataSource;
    }
}
2. view/adminhtml/ui_component/mooauth_provider_listing.xml
Standard Magento UI Grid definition. Columns: ID, Provider Name (display_name/app_name),
Client ID, Login Type, Status (is_active), Sort Order, Actions.

3. Block/Adminhtml/Provider/Edit.php
Widget\Form\Container subclass. Loads provider by id URL param, stores in Core Registry,
overrides getFormHtml() to wrap the tabs block in a <form> tag, and adds buttons.

Key method:


public function getFormHtml(): string
{
    $id      = (int) $this->getRequest()->getParam('id', 0);
    $formKey = $this->formKey->getFormKey();
    $url     = $this->getUrl('mooauth/provider/save');

    return '<form id="edit_form" action="' . $this->escapeUrl($url) . '"'
         . ' method="post" enctype="multipart/form-data">'
         . '<input type="hidden" name="form_key" value="'
         . $this->escapeHtmlAttr($formKey) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . $this->getChildHtml('mooauth_provider_edit_tabs')
         . '</form>';
}
Buttons added in _construct():

Save (default from parent)
Save and Continue Edit (sets back=edit POST param via JS)
Back (link to mooauth/provider/index)
Delete (only when id > 0, confirmation dialog)
getHeaderText(): "Edit Provider 'X'" vs "New OIDC Provider"

4. Block/Adminhtml/Provider/Edit/Tabs.php
Extends \Magento\Backend\Block\Widget\Tabs. Sets id="mooauth_provider_tabs",
destElementId="edit_form", title "Provider Information". In _beforeToHtml() adds 3 tabs
using getChildHtml() (inline content, no AJAX):


$this->addTab('provider_settings', [
    'label'   => __('Provider Settings'),
    'content' => $this->getChildHtml('mooauth.provider.tab.settings'),
    'active'  => true,
]);
$this->addTab('oauth_settings', [
    'label'   => __('OAuth Settings'),
    'content' => $this->getChildHtml('mooauth.provider.tab.oauth'),
]);
$this->addTab('attribute_mapping', [
    'label'   => __('Attribute Mapping'),
    'content' => $this->getChildHtml('mooauth.provider.tab.attrs'),
]);
5–7. Tab blocks (3 files)
Block/Adminhtml/Provider/Edit/Tab/ProviderSettings.php
Block/Adminhtml/Provider/Edit/Tab/OAuthSettings.php
Block/Adminhtml/Provider/Edit/Tab/AttributeMapping.php

All extend \Magento\Backend\Block\Template and implement
\Magento\Backend\Block\Widget\Tab\TabInterface. Each:

Injects \Magento\Framework\Registry $registry
Has protected $_template = 'MiniOrange_OAuth::provider/tab/<name>.phtml'
Exposes getProviderData(): array (reads current_oidc_provider from registry)
Returns canShowTab(): true, isHidden(): false
8–10. Tab templates (3 phtml files)
view/adminhtml/templates/provider/tab/providersettings.phtml
→ Section A fields from provider_edit.phtml (lines 50–199):
app_name, display_name, login_type, is_active, sort_order, button_label, button_color.
No <form> tag, no form_key, no id field, no submit button.
$data = $block->getProviderData();

view/adminhtml/templates/provider/tab/oauthsettings.phtml
→ Section B fields from provider_edit.phtml (lines 201–434):
clientID, client_secret, scope, grant_type, values_in_header, values_in_body,
well_known_config_url, authorize_endpoint, access_token_endpoint, user_info_endpoint,
jwks_endpoint, endsession_endpoint, issuer.
Edit-mode detect: $isEditMode = !empty($data['id']).

view/adminhtml/templates/provider/tab/attrsettings.phtml
→ Section C fields from provider_edit.phtml (lines 436–537):
email_attribute, username_attribute, firstname_attribute, lastname_attribute, group_attribute.

Files to MODIFY
11. Controller/Adminhtml/Provider/Edit.php
Add \Magento\Framework\Registry injection. After validating the provider ID, store the
loaded model in the registry:


$this->registry->register('current_oidc_provider', $model);
(Only for id > 0. For new provider, nothing is registered; tab blocks return empty arrays.)
Also inject MiniorangeOauthClientAppsFactory and AppResource to load the model here
(currently uses oauthUtility->getClientDetailsById() which returns an array, not a model).

12. view/adminhtml/layout/mooauth_provider_index.xml
Replace the custom template block with a standard Magento UI Grid layout:


<page>
  <update handle="styles"/>
  <body>
    <referenceContainer name="content">
      <uiComponent name="mooauth_provider_listing"/>
    </referenceContainer>
  </body>
</page>
Remove navbar block and js.phtml block.

13. view/adminhtml/layout/mooauth_provider_edit.xml
Replace with Widget Tabs layout:


<page>
  <update handle="editor"/>
  <body>
    <referenceContainer name="content">
      <block class="MiniOrange\OAuth\Block\Adminhtml\Provider\Edit"
             name="mooauth_provider_edit">
        <block class="MiniOrange\OAuth\Block\Adminhtml\Provider\Edit\Tabs"
               name="mooauth_provider_edit_tabs">
          <block class="...Tab\ProviderSettings"
                 name="mooauth.provider.tab.settings"/>
          <block class="...Tab\OAuthSettings"
                 name="mooauth.provider.tab.oauth"/>
          <block class="...Tab\AttributeMapping"
                 name="mooauth.provider.tab.attrs"/>
        </block>
      </block>
    </referenceContainer>
  </body>
</page>
Remove navbar and js.phtml blocks.

14. etc/di.xml
Add virtual types for the UI Grid data source:


<virtualType name="MooauthProviderListingDataProvider"
             type="Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider">
  <arguments>
    <argument name="collection" xsi:type="object" shared="false">
      MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\Collection
    </argument>
    <argument name="filterPool" xsi:type="object" shared="false">
      MooauthProviderListingFilterPool
    </argument>
  </arguments>
</virtualType>
<virtualType name="MooauthProviderListingFilterPool"
             type="Magento\Framework\View\Element\UiComponent\DataProvider\FilterPool">
  <arguments>
    <argument name="appliers" xsi:type="array">
      <item name="regular" xsi:type="object">
        Magento\Framework\View\Element\UiComponent\DataProvider\RegularFilter
      </item>
    </argument>
  </arguments>
</virtualType>
<type name="Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory">
  <arguments>
    <argument name="collections" xsi:type="array">
      <item name="mooauth_provider_listing_data_source" xsi:type="string">
        MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\Collection
      </item>
    </argument>
  </arguments>
</type>
15. etc/adminhtml/menu.xml
Remove: provider_settings, oauth_settings, attr_settings entries.
Keep: provider_management (Manage Providers), signin_settings (Sign In Settings).
These two become the only items under "Authelia OIDC" in the left menu.

16. Layout files for removed-from-menu pages
mooauth_signinsettings_index.xml: remove navbar.phtml block. The Sign In Settings
page keeps its existing template but renders without the custom top navigation bar.
Standalone context-tab layouts (oauthsettings, attrsettings, providersettings) can similarly
have their navbar references removed (those pages are no longer linked from the menu).

What Does NOT Change
Controller/Adminhtml/Provider/Save.php — already handles all fields from all tabs
Controller/Adminhtml/Provider/Delete.php — no changes needed
Controller/Adminhtml/Provider/Index.php — triggers the grid page
signinsettings.phtml template — content stays the same
Controller/Adminhtml/Signinsettings/Index.php — stays as-is
Standalone controller actions (OAuthsettings, Attrsettings, Providersettings) — kept but de-linked from menu; still function if accessed by direct URL
All non-admin controllers, plugins, models — unaffected
Verification
Provider list (mooauth/provider/index):

Shows Magento-style data grid with search field, Filters button, Columns button
Rows show: ID, Provider Name, Client ID, Login Type, Status badge, Sort Order
Edit / Delete action links per row
"Add New Provider" button in top right
Add provider (mooauth/provider/edit):

Standard Magento page header with page title "New OIDC Provider"
Action buttons at top: Back, Save, Save and Continue Edit
Left sidebar: "PROVIDER INFORMATION" section header with 3 clickable items
Provider Settings tab active by default; OAuth Settings and Attribute Mapping accessible
Edit provider (mooauth/provider/edit/id/X):

All form fields pre-populated from registry-loaded model
Delete button appears in action bar
Header shows "Edit Provider 'AppName'"
Client Secret shows empty with "Leave blank to keep existing" note
Save and Continue Edit: saves and redirects back to edit form (via back=edit POST)

Sign In Settings (mooauth/signinsettings/index):

No custom navbar; accessible only via left Magento admin menu
Page content identical to before
Left menu: "Authelia OIDC" shows only "Manage Providers" and "Sign In Settings"

Run PHPCS: cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=phpcs.xml .

Run PHPStan: cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpstan analyse --no-progress

Run PHPUnit: cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpunit --configuration phpunit.xml