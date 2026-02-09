# Implementation Plan - Disable Non-OIDC Admin Logins

Add an option to the "Sign In Settings" section to disable non-OIDC logins for admins. When enabled, the default username and password fields will be removed from the admin login page, and authentication will be enforced at the server level.

## User Review Required

> [!WARNING]
> **Emergency Access Consideration**: When this setting is enabled, if the OIDC provider is unavailable, admins will not be able to log in through the standard interface. Consider documenting a CLI or database method to disable this setting in emergency situations.

> [!IMPORTANT]
> **Breaking Change**: Enabling this setting will prevent all non-OIDC admin logins. Ensure all admin users have OIDC accounts before enabling.

## Proposed Changes

### [Admin Login Restriction]

#### [MODIFY] [OAuthConstants.php](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/Helper/OAuthConstants.php)

Add the constant for the new setting:

```php
const DISABLE_NON_OIDC_ADMIN_LOGIN = 'disableNonOidcAdminLogin';
```

**Location**: After line 59 (after `ENABLE_LOGIN_REDIRECT`)

---

#### [MODIFY] [signinsettings.phtml](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/view/adminhtml/templates/signinsettings.phtml)

Add a new checkbox in the "Show Link on Default Login Page" section:

```php
<input type="checkbox"
       name="mo_disable_non_oidc_admin_login"
       id="mo_disable_non_oidc_admin_login"
       <?= $block->escapeHtmlAttr($disableNonOidcAdminLogin) ?>
       <?= $block->escapeHtmlAttr($disabled) ?>
       value="true">
Disable non-OIDC Login for Admins (OIDC login only)
```

**Location**: After line 52 (after the "Show the Login Link on the default admin login page" checkbox)

**Additional**: Initialize the variable at the top of the file (around line 11):
```php
$disableNonOidcAdminLogin = $this->isNonOidcAdminLoginDisabled() ? 'checked' : '';
```

---

#### [MODIFY] [OAuth.php](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/Block/OAuth.php)

Add the method to check if non-OIDC admin login is disabled:

```php
/**
 * Check if non-OIDC admin login is disabled
 * 
 * @return bool
 */
public function isNonOidcAdminLoginDisabled()
{
    return $this->oauthUtility->getStoreConfig(OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN);
}
```

**Location**: After the `showAdminLink()` method (around line 421)

---

#### [MODIFY] [Index.php](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/Controller/Adminhtml/Signinsettings/Index.php#L181-L204)

Update the `processValuesAndSaveData` method to save the new setting:

```php
$mo_disable_non_oidc_admin_login = isset($params['mo_disable_non_oidc_admin_login']) ? 1 : 0;

$this->oauthUtility->customlog("SignInSettings: Saving disable non-OIDC admin login: " . $mo_disable_non_oidc_admin_login);

$this->oauthUtility->setStoreConfig(OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN, $mo_disable_non_oidc_admin_login);
```

**Location**: Add after line 188 (after the `$mo_oauth_logout_redirect_url` processing)

---

#### [MODIFY] [adminssobutton.phtml](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/view/adminhtml/templates/adminssobutton.phtml)

Add JavaScript to hide the standard login form when non-OIDC login is disabled:

```php
<?php if ($this->isNonOidcAdminLoginDisabled()): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Hide the standard login form
        var loginForm = document.querySelector('#login-form');
        if (loginForm) {
            loginForm.style.display = 'none';
        }
        
        // Hide the OR separator (SVG lines and text)
        var actionsDiv = document.querySelector('.actions');
        if (actionsDiv) {
            var svgs = actionsDiv.querySelectorAll('svg');
            svgs.forEach(function(svg) {
                svg.style.display = 'none';
            });
            // Hide the "OR" text between SVGs
            var textNodes = actionsDiv.childNodes;
            textNodes.forEach(function(node) {
                if (node.nodeType === 3 && node.textContent.trim() === 'OR') {
                    node.textContent = '';
                }
            });
        }
    });
</script>
<?php endif; ?>
```

**Location**: Add before the closing PHP tag at the end of the file

---

#### [NEW] [AdminLoginRestrictionPlugin.php](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/Plugin/AdminLoginRestrictionPlugin.php)

Create a new plugin to enforce the restriction at the authentication level:

```php
<?php

namespace MiniOrange\OAuth\Plugin;

use Magento\Backend\Model\Auth;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Plugin to restrict non-OIDC admin logins when the setting is enabled
 */
class AdminLoginRestrictionPlugin
{
    private $cookieManager;
    private $oauthUtility;

    public function __construct(
        CookieManagerInterface $cookieManager,
        OAuthUtility $oauthUtility
    ) {
        $this->cookieManager = $cookieManager;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Block non-OIDC authentication attempts when the restriction is enabled
     *
     * @param Auth $subject
     * @param string $username
     * @param string $password
     * @throws AuthenticationException
     */
    public function beforeLogin(Auth $subject, $username, $password)
    {
        // Check if non-OIDC admin login is disabled
        $isDisabled = $this->oauthUtility->getStoreConfig(OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN);
        
        if (!$isDisabled) {
            return null; // Allow normal authentication
        }

        // Check if this is an OIDC-authenticated session
        $oidcToken = $this->cookieManager->getCookie('oidc_admin_token');
        
        if (!$oidcToken) {
            // No OIDC token present, block the login
            $this->oauthUtility->customlog("AdminLoginRestriction: Blocked non-OIDC login attempt for user: " . $username);
            throw new AuthenticationException(
                __('Non-OIDC admin login is disabled. Please use OIDC authentication.')
            );
        }

        return null;
    }
}
```

---

#### [MODIFY] [di.xml](file:///Users/martin/Documents/Docker/authelia/Authelia-OIDC/magento-OIDC/etc/di.xml)

Register the new plugin:

```xml
<!-- Admin Login Restriction Plugin Configuration -->
<type name="MiniOrange\OAuth\Plugin\AdminLoginRestrictionPlugin">
    <arguments>
        <argument name="cookieManager" xsi:type="object">Magento\Framework\Stdlib\CookieManagerInterface</argument>
        <argument name="oauthUtility" xsi:type="object">MiniOrange\OAuth\Helper\OAuthUtility</argument>
    </arguments>
</type>

<!-- Plugin to restrict non-OIDC admin logins -->
<type name="Magento\Backend\Model\Auth">
    <plugin name="admin_login_restriction"
            type="MiniOrange\OAuth\Plugin\AdminLoginRestrictionPlugin"
            sortOrder="5"/>
</type>
```

**Location**: Add after the existing `Magento\Backend\Model\Auth` plugin configuration (after line 70)

**Note**: The `sortOrder="5"` ensures this runs before the OIDC credential interceptor (which has `sortOrder="10"`)

---

## Verification Plan

### Automated Tests
- Run `bin/magento setup:di:compile` to ensure DI configuration is valid
- Run `bin/magento cache:flush` to clear configuration cache

### Manual Verification

1. **Configuration UI**:
   - Navigate to "Sign In Settings"
   - Verify the new checkbox appears below "Show the Login Link on the default admin login page"
   - Verify the checkbox is disabled (greyed out) when OIDC is not configured
   - Configure OIDC and verify the checkbox becomes enabled

2. **Admin Login Restriction UI**:
   - Enable "Disable non-OIDC Login for Admins" in "Sign In Settings"
   - Save the configuration
   - Clear cache: `bin/magento cache:flush`
   - Log out and navigate to the admin login page
   - Verify that the username and password fields are hidden
   - Verify that the "OR" separator is hidden
   - Verify that only the OIDC Login button is visible

3. **Authentication Enforcement**:
   - With the restriction enabled, attempt to log in using standard credentials via:
     - Browser console manipulation
     - Direct POST request to the login endpoint
   - Verify that login is blocked with the error message: "Non-OIDC admin login is disabled. Please use OIDC authentication."
   - Verify that OIDC login still works normally
   - Verify that after OIDC login, the admin session is created successfully

4. **Disabled State**:
   - Disable OIDC configuration
   - Verify that the "Disable non-OIDC Login for Admins" checkbox becomes disabled
   - Verify that existing admin sessions are not terminated

5. **Edge Cases**:
   - Test with the setting enabled and OIDC provider temporarily unavailable
   - Verify appropriate error handling
   - Document the emergency access procedure (CLI/database method to disable the setting)

6. **Existing Sessions**:
   - Log in as an admin with standard credentials
   - Enable the "Disable non-OIDC Login for Admins" setting
   - Verify that the existing session remains active
   - Log out and verify that re-login requires OIDC
