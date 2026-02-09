# Implementation Plan - Disable Non-OIDC Admin Logins & UI Fixes

Add an option to the "Sign In Settings" section to disable non-OIDC logins for admins. When enabled, the default username and password fields will be removed from the admin login page. Additionally, fix a UI layout issue in the OAuth Settings page.

## Proposed Changes

### [Admin Login Restriction]

#### [MODIFY] [OAuthConstants.php](OIDC/Helper/OAuthConstants.php)
- Add `DISABLE_NON_OIDC_ADMIN_LOGIN` constant.

#### [MODIFY] [signinsettings.phtml](OIDC/view/adminhtml/templates/signinsettings.phtml)
- Add a checkbox for "Disable non-OIDC Login for Admins" under "Show Link on Default Login Page".
- Disable the checkbox if OIDC is not configured (`$isOAuthConfigured` is false).

#### [MODIFY] [OAuth.php](OIDC/Block/OAuth.php)
- Add method `isNonOidcAdminLoginDisabled()`.

#### [MODIFY] [Index.php](OIDC/Controller/Adminhtml/Signinsettings/Index.php)
- Update [processValuesAndSaveData](OIDC/Controller/Adminhtml/Signinsettings/Index.php#178-205) to save the `DISABLE_NON_OIDC_ADMIN_LOGIN` setting.

#### [MODIFY] [adminssobutton.phtml](OIDC/view/adminhtml/templates/adminssobutton.phtml)
- Add a JavaScript snippet to hide the default login form elements (`#login-form .admin__field`) and the "OR" separator if `isNonOidcAdminLoginDisabled()` is true.

#### [NEW] [AdminLoginRestrictionPlugin.php](OIDC/Plugin/AdminLoginRestrictionPlugin.php)
- Implement a `beforeAuthenticate` plugin on `Magento\Backend\Model\Auth`.
- If `isNonOidcAdminLoginDisabled()` is enabled and the login is NOT via OIDC, throw an exception to block authentication.

#### [MODIFY] [di.xml](OIDC/etc/di.xml)
- Register `AdminLoginRestrictionPlugin` for `Magento\Backend\Model\Auth`.

### [UI Fixes]

#### [MODIFY] [oauthsettings.phtml](OIDC/view/adminhtml/templates/oauthsettings.phtml)
- Ensure consistent spacing for all input fields.

## Verification Plan

### Manual Verification
1.  **UI Width Fix**:
    - Navigate to "OAuth Settings".
    - Verify that no horizontal scrollbar is present and the section width matches other sections.
2.  **Admin Login Restriction UI**:
    - Enable "Disable non-OIDC Login for Admins" in "Sign In Settings".
    - Go to the admin login page.
    - Verify that the username and password fields are hidden, as well as the "OR" separator.
    - Verify that only the OIDC Login button is visible.
3.  **Authentication Enforcement**:
    - Try to bypass the UI (e.g., via browser console or direct POST) to log in with regular credentials while the restriction is on.
    - Verify that login is blocked at the server level by the plugin.
    - Verify that OIDC login still works normally.
4.  **Disabled State**:
    - Ensure that if OIDC is not configured, the "Disable non-OIDC Login for Admins" checkbox is disabled (greyed out).
