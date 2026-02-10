# MiniOrange OAuth/OIDC SSO Module — Technical Documentation

> **Module**: `MiniOrange_OAuth` (`miniorange_inc/miniorange-oauth-sso` v4.3.0)
> **Requires**: PHP 8.1+, Magento 2.4.7+

---

## 1. Overview

This Magento 2 module adds **OAuth 2.0 / OpenID Connect** single sign-on for both **Customer** (frontend) and **Admin** (backend) users. It replaces Magento's native login flow with an external Identity Provider (Authelia, Keycloak, Auth0, etc.) while keeping all of Magento's built-in security events, ACL checks, and session handling intact.

### What it does

- Redirects users to an OIDC provider, receives an authorization code, exchanges it for tokens, and extracts user attributes.
- **Customer flow**: creates or matches a Magento customer, sets a customer session, and redirects to the relay state.
- **Admin flow**: uses Magento's native `Auth::login()` with a plugin-injected credential adapter — no bootstrap hacking, all security events fire normally.
- **JIT provisioning**: auto-creates customers and admins on first login (configurable).
- **Attribute mapping**: maps OIDC claims to Magento user fields (email, name, groups, address, DOB, gender, phone).
- **Group-to-role mapping**: maps OIDC groups to Magento admin roles with a configurable fallback chain.
- **Identity verification bypass**: OIDC-authenticated admins skip the "enter your password" prompt when editing users/roles/account settings.
- **Login restriction**: optionally blocks all non-OIDC admin logins.

### Why it exists

Out of the box, Magento has no OIDC support. This module bridges that gap while respecting Magento's plugin architecture — no core patches, no rewrites, just DI-configured plugins and observers.

---

## 2. Quick Start

### Step 1 — Install and enable

```bash
composer require miniorange_inc/miniorange-oauth-sso
bin/magento module:enable MiniOrange_OAuth
bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

### Step 2 — Configure the provider

Navigate to **Stores > Configuration > MiniOrange > OAuth/OIDC** and fill in:

| Field | Example value |
|---|---|
| App Name | `authelia` |
| Client ID | `magento-store` |
| Client Secret | `your-secret` |
| Authorize Endpoint | `https://auth.example.com/api/oidc/authorization` |
| Token Endpoint | `https://auth.example.com/api/oidc/token` |
| User Info Endpoint | `https://auth.example.com/api/oidc/userinfo` |
| Scope | `openid profile email groups` |

Set the **Callback URL** in your IDP to:

```
https://your-site.com/mooauth/actions/ReadAuthorizationResponse
```

### Step 3 — Test

Click **Test Configuration** in the admin panel. You'll be redirected to your IDP, and on return you'll see the received attributes. Map them under **Attribute Mapping** and you're done.

---

## 3. API Reference

### `\MiniOrange\OAuth\Helper\Data`

The base data-access helper. Injected as a dependency everywhere configuration values are needed.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the frontend SSO login URL. Appends `relayState` and `app_name` as query params. Defaults `relayState` to the current URL and `app_name` to the stored config value. |
| `getAdminSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the admin backend SSO login URL. Uses the admin URL builder so the request routes through the admin `SendAuthorizationRequest` controller (which stamps `loginType=admin` into the state). |
| `getCallBackUrl` | `()` | `string` | Returns `{baseUrl}mooauth/actions/ReadAuthorizationResponse`. Register this in your IDP. |
| `getBaseUrl` | `()` | `string` | Returns Magento's `UrlInterface::getBaseUrl()`. |
| `getAdminBaseUrl` | `()` | `string` | Returns the admin home page URL. |
| `getStoreConfig` | `($config)` | `mixed` | Reads from `miniorange/oauth/{$config}` in `core_config_data`. |
| `setStoreConfig` | `($config, $value, $skipSanitize = false)` | `void` | Writes to `miniorange/oauth/{$config}`. Also syncs `show_admin_link` / `show_customer_link` to the `miniorange_oauth_client_apps` table. |
| `setOAuthClientApps` | `($app_name, $client_id, ...)` | `void` | Inserts a new row into the `miniorange_oauth_client_apps` table with the provider's configuration. |
| `getOAuthClientApps` | `()` | `Collection` | Returns the full collection from `miniorange_oauth_client_apps`. |
| `saveConfig` | `($url, $value, $id, $admin)` | `void` | Updates an attribute on either an admin user or a customer, depending on `$admin` (bool). |
| `sanitize` | `($value)` | `mixed` | Recursive `htmlspecialchars(strip_tags(trim(...)))`. Applied to all config writes by default. |

### `\MiniOrange\OAuth\Helper\OAuthUtility`

Extends `Data`. This is the class most controllers and plugins inject.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `isOAuthConfigured` | `()` | `bool` | `true` if `authorizeURL` is set in config. |
| `isUserLoggedIn` | `()` | `bool` | Checks both customer session and admin auth session. |
| `getCurrentUser` | `()` | `Customer` | Returns the logged-in customer from the customer session. |
| `getCurrentAdminUser` | `()` | `User` | Returns the logged-in admin from the auth session. |
| `customlog` | `($txt)` | `void` | Writes to `var/log/mo_oauth.log` if debug logging is enabled. |
| `log_debug` | `($msg, $obj = null)` | `void` | Logs `$msg` plus `var_export($obj)` when `$obj` is provided. |
| `flushCache` | `($from = "")` | `void` | Cleans `db_ddl` cache type and all frontend cache pools. |
| `isLogEnable` | `()` | `bool` | Checks both the legacy and new debug-log config path. |
| `getAdminSession` | `()` | `Session` | Returns the backend session. |
| `setSessionData` / `getSessionData` | `($key, $value)` / `($key, $remove = false)` | `mixed` | Customer session read/write. |
| `setAdminSessionData` / `getAdminSessionData` | `($key, $value)` / `($key, $remove = false)` | `mixed` | Admin session read/write. |
| `getLogoutUrl` | `()` | `string` | Returns the appropriate logout URL based on who is logged in (customer or admin). |
| `getClientDetails` | `()` | `array` | Returns a flat array of the active app's client config (clientID, secret, endpoints, etc.). |

### `\MiniOrange\OAuth\Model\Service\AdminUserCreator`

Service class for admin JIT provisioning.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createAdminUser` | `($email, $userName, $firstName, $lastName, array $userGroups)` | `User\|null` | Creates an admin user with a random password, assigns a role based on group mapping. Returns `null` if no suitable role exists. |
| `isAdminUser` | `($email)` | `bool` | Checks `admin_user` table by both username and email. |

### `\MiniOrange\OAuth\Model\Service\CustomerUserCreator`

Service class for customer JIT provisioning.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createCustomer` | `($email, $userName, $firstName, $lastName, $flattenedAttrs, $rawAttrs)` | `Customer\|null` | Creates a customer with a random password. Maps DOB, gender, phone, and address fields from OIDC claims. Creates a default billing/shipping address if address data is present. |

### `\MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter`

Implements `Magento\Backend\Model\Auth\Credential\StorageInterface`. This is the bridge between OIDC and Magento's native admin auth.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `authenticate` | `($username, $password)` | `bool` | Verifies the OIDC token marker, loads the admin user by email, checks active status and role assignment. Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth => true`. |
| `login` | `($username, $password)` | `$this` | Calls `authenticate()`, then records the login and reloads the user. |
| `reload` | `()` | `$this` | Reloads the user model from the database. |

### `\MiniOrange\OAuth\Helper\SessionHelper`

Handles cross-origin session cookie compatibility for OIDC redirects.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `configureSSOSession` | `()` | `void` | Delegates to `updateSessionCookies()`. Called at the start of every SSO flow. |
| `updateSessionCookies` | `()` | `void` | Re-sets session cookies with `SameSite=None; Secure; HttpOnly` via Magento's `CookieManager`. |
| `forceSameSiteNone` | `()` | `void` | Rewrites all `Set-Cookie` headers in the response to enforce `SameSite=None`. Called by `SessionCookieObserver` before every response. |

---

## 4. Common Patterns

### Pattern 1: Add a "Login with SSO" button to your theme

```php
<?php
// In your .phtml template — inject \MiniOrange\OAuth\Helper\Data as $oauthHelper
$loginUrl = $oauthHelper->getSPInitiatedUrl();
?>
<a href="<?= $loginUrl ?>" class="btn-sso">Login with SSO</a>
```

For admin login, use `getAdminSPInitiatedUrl()` instead. The admin variant stamps `loginType=admin` into the OAuth state so the callback routes the user through the admin auth flow.

### Pattern 2: Check if the current user logged in via OIDC

```php
// Inject \MiniOrange\OAuth\Helper\OAuthUtility as $oauthUtility

// For any logged-in user (customer or admin):
if ($oauthUtility->isUserLoggedIn()) {
    $customer = $oauthUtility->getCurrentUser();       // Customer model (or null)
    $admin    = $oauthUtility->getCurrentAdminUser();   // Admin User model (or null)
}
```

For admin-specific OIDC detection, check the `oidc_authenticated` cookie:

```php
// Inject Magento\Framework\Stdlib\CookieManagerInterface as $cookieManager
$isOidcAdmin = ($cookieManager->getCookie('oidc_authenticated') === '1');
```

### Pattern 3: Extend attribute mapping

The module maps standard OIDC claims via config. For custom logic, create a plugin on `CheckAttributeMappingAction::execute()` or observe the process via events.

Key files to understand:
- `Controller/Actions/CheckAttributeMappingAction.php` — routes admin vs. customer, handles attribute extraction
- `Controller/Actions/ProcessUserAction.php` — creates/updates customers based on mapped attributes
- `Model/Service/CustomerUserCreator.php` — the actual customer creation logic (DOB, gender, address, etc.)
- `Model/Service/AdminUserCreator.php` — admin creation with group-to-role mapping

The attribute mapping values are stored in the `miniorange_oauth_client_apps` table and `core_config_data` under the `miniorange/oauth/` prefix.

---

## 5. Gotchas

### 1. Admin and customer flows are separate entry points

Admin login starts from `Controller/Adminhtml/Actions/SendAuthorizationRequest.php` which stamps `loginType=admin` into the OAuth state. Customer login starts from `Controller/Actions/SendAuthorizationRequest.php` which stamps `loginType=customer`. **Both use the same callback URL** — the `loginType` in the state determines routing.

If you trigger the customer SSO URL but the user is an admin, they'll be logged in as a customer (or rejected if they don't have a customer account). Always use `getAdminSPInitiatedUrl()` for admin-intent logins.

### 2. The IDP email MUST match

For admin login, the email from the OIDC provider is matched against the `email` column in `admin_user`. If there's no match and auto-create is disabled, the login fails with `ADMIN_ACCOUNT_NOT_FOUND`. Check `var/log/mo_oauth.log` — every decision is logged there when debug logging is enabled.

### 3. Mixed content / callback URL protocol

The callback URL is built from `storeManager->getBaseUrl()`. If your load balancer terminates SSL but Magento sees HTTP internally, the callback URL will be `http://...` and most IDPs will reject it. Fix: configure `web/secure/base_url` correctly and handle `X-Forwarded-Proto` in your web server config.

### 4. Attribute keys are case-sensitive

Authelia sends `preferred_username`, `email`, `name`, `groups`. Other providers might send `Email`, `firstName`, etc. If mapping fails silently, check the exact key names via **Test Configuration** and verify they match your Attribute Mapping settings.

### 5. Admin role mapping fallback does NOT default to "Administrators"

For security, if no group-to-role mapping matches and no default role is configured, admin auto-creation is **denied** (`AdminUserCreator::getAdminRoleFromGroups()` returns `null`). This is intentional — configure your role mappings explicitly.

### 6. OIDC-authenticated admins bypass password re-verification

The `OidcIdentityVerificationPlugin` skips the "enter your current password" prompt for admin users with the `oidc_authenticated` cookie. This cookie is set on login and cleared on logout. It's scoped to the admin path and lasts 24 hours.

### 7. `SameSite=None` cookies are forced globally

The `SessionCookieObserver` (event: `controller_front_send_response_before`) rewrites **all** `Set-Cookie` headers to add `SameSite=None`. This is required for cross-origin OIDC redirects but may affect other cookies. If you have cookie-related issues, check this observer first.

### 8. Debug logs auto-expire after 7 days

When debug logging is enabled, the `SendAuthorizationRequest` controller checks if the log file is older than 7 days. If so, it disables logging and deletes the log file. You'll need to re-enable it in the admin panel.

### 9. The OAuth `state` parameter encodes multiple values

The state is formatted as `encodedRelayState|sessionId|encodedAppName|loginType`. The pipe `|` is the delimiter; the relayState and appName are URL-encoded to prevent collisions. If you're debugging state issues, decode each segment manually.

### 10. Non-OIDC admin login can be disabled

When `disableNonOidcAdminLogin` is enabled in config, the `AdminLoginRestrictionPlugin` throws an `AuthenticationException` for any password-based admin login attempt. Only the OIDC token marker (`OIDC_VERIFIED_USER`) is allowed through. This affects **all** admin users, including emergency access.

---

## 6. Related Modules

### Magento Core Dependencies

| Module | Usage |
|---|---|
| `Magento_Customer` | `CustomerFactory`, `Session`, `CustomerRepositoryInterface` — customer creation and session management |
| `Magento_User` | `UserFactory`, `User` model — admin user CRUD and lookup |
| `Magento_Backend` | `Auth`, `Auth\Session`, `UrlInterface` — admin authentication, session, and URL generation |
| `Magento_Authorization` | `Role\Collection` — querying available admin roles for group mapping |
| `Magento_Captcha` | `CheckUserLoginBackendObserver` — intercepted by `OidcCaptchaBypassPlugin` to skip CAPTCHA for OIDC |
| `Magento_Directory` | `CountryFactory`, `DirectoryData` — country resolution for customer address mapping |
| `Magento_Framework` | `CookieManager`, `CookieMetadataFactory`, `Random`, `Curl`, `Event\Manager` |

### Plugin Interceptions (defined in `etc/di.xml`)

| Target | Plugin | Sort Order | Purpose |
|---|---|---|---|
| `Magento\Backend\Model\Auth` | `AdminLoginRestrictionPlugin` | 5 | Blocks non-OIDC admin login when restriction is enabled |
| `Magento\Backend\Model\Auth` | `OidcCredentialPlugin` | 10 | Injects `OidcCredentialAdapter` during OIDC login |
| `Magento\Backend\Model\Auth` | `OidcLogoutPlugin` | 20 | Deletes `oidc_authenticated` cookie on logout |
| `Magento\Captcha\Observer\CheckUserLoginBackendObserver` | `OidcCaptchaBypassPlugin` | 10 | Skips CAPTCHA for OIDC-authenticated logins |
| `Magento\User\Model\User` | `OidcIdentityVerificationPlugin` | 10 | Bypasses password re-verification for OIDC admins |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcIdentityFieldPlugin` | 20 | Removes "required" from password field in user edit form |
| `Magento\User\Block\Role\Tab\Info` | `OidcIdentityFieldPlugin` | 20 | Same for role edit form |
| `Magento\Backend\Block\System\Account\Edit\Form` | `OidcIdentityFieldPlugin` | 20 | Same for account settings form |

### Events Observed

| Event | Observer | Area |
|---|---|---|
| `controller_front_send_response_before` | `SessionCookieObserver` | frontend |
| Logout event | `OAuthLogoutObserver` | adminhtml |

---

## 7. Structure

```
MiniOrange_OAuth/
├── registration.php                          # Registers module with Magento
├── composer.json                             # Package metadata (v4.3.0)
├── etc/
│   ├── module.xml                            # Module declaration
│   ├── di.xml                                # DI config: plugins, constructor args
│   ├── db_schema.xml                         # DB table: miniorange_oauth_client_apps
│   ├── acl.xml                               # ACL resources for admin pages
│   ├── events.xml                            # Global event observers
│   ├── csp_whitelist.xml                     # Content Security Policy whitelist
│   ├── frontend/
│   │   ├── routes.xml                        # Frontend route: mooauth
│   │   └── events.xml                        # Frontend event observers
│   └── adminhtml/
│       ├── routes.xml                        # Admin route: mooauth
│       ├── events.xml                        # Admin event observers
│       ├── menu.xml                          # Admin menu entries
│       └── csp_whitelist.xml                 # Admin CSP whitelist
│
├── Controller/
│   ├── Actions/                              # Frontend controllers (OAuth flow)
│   │   ├── BaseAction.php                    # Base class for frontend actions
│   │   ├── BaseAdminAction.php               # Base class for admin config pages
│   │   ├── SendAuthorizationRequest.php      # Step 1: Redirect to IDP (customer)
│   │   ├── ReadAuthorizationResponse.php     # Step 2: Receive auth code, exchange for tokens
│   │   ├── ProcessResponseAction.php         # Step 3: Validate response, extract email
│   │   ├── CheckAttributeMappingAction.php   # Step 4: Route admin vs customer, map attributes
│   │   ├── ProcessUserAction.php             # Step 5: Create/match customer, delegate login
│   │   ├── CustomerLoginAction.php           # Step 6: Set customer session, redirect
│   │   └── ShowTestResults.php               # Test Configuration results display
│   └── Adminhtml/
│       ├── Actions/
│       │   ├── SendAuthorizationRequest.php  # Step 1: Redirect to IDP (admin)
│       │   └── Oidccallback.php              # Admin login: calls Auth::login() with OIDC marker
│       ├── OAuthsettings/Index.php           # Admin page: OAuth Settings
│       ├── Attrsettings/Index.php            # Admin page: Attribute Mapping
│       └── Signinsettings/Index.php          # Admin page: Sign In Settings
│
├── Model/
│   ├── Auth/
│   │   └── OidcCredentialAdapter.php         # StorageInterface impl for OIDC auth
│   ├── Service/
│   │   ├── AdminUserCreator.php              # JIT admin provisioning + role mapping
│   │   └── CustomerUserCreator.php           # JIT customer provisioning + address creation
│   ├── MiniorangeOauthClientApps.php         # Model for oauth_client_apps table
│   └── ResourceModel/
│       └── MiniOrangeOauthClientApps/
│           ├── Collection.php                # Collection model
│           └── (ResourceModel).php           # Resource model
│
├── Plugin/
│   ├── AdminLoginRestrictionPlugin.php       # Blocks non-OIDC admin login
│   ├── Auth/
│   │   ├── OidcCredentialPlugin.php          # Injects OIDC adapter into Auth::login()
│   │   └── OidcLogoutPlugin.php              # Cleans up OIDC cookie on logout
│   ├── Captcha/
│   │   └── OidcCaptchaBypassPlugin.php       # Skips CAPTCHA for OIDC logins
│   └── User/
│       ├── OidcIdentityVerificationPlugin.php # Bypasses password re-verification
│       └── Block/
│           └── OidcIdentityFieldPlugin.php   # Removes required from password field in forms
│
├── Helper/
│   ├── Data.php                              # Base config data access
│   ├── OAuthUtility.php                      # Extended utility (sessions, cache, logging)
│   ├── OAuthConstants.php                    # All constants (config keys, defaults, URLs)
│   ├── OAuthMessages.php                     # User-facing message templates
│   ├── SessionHelper.php                     # SameSite=None cookie handling
│   ├── Curl.php                              # HTTP client for token/userinfo requests
│   ├── TestResults.php                       # Test configuration HTML output
│   └── OAuth/
│       ├── AuthorizationRequest.php          # Builds the authorize URL query string
│       ├── AccessTokenRequest.php            # Builds the token exchange POST body
│       └── AccessTokenRequestBody.php        # Alternate token body (header auth variant)
│
├── Block/
│   ├── OAuth.php                             # Admin template block (config getters)
│   └── Adminhtml/
│       ├── Debug.php                         # Debug info block
│       └── OidcErrorMessage.php              # OIDC error display block
│
├── Observer/
│   ├── SessionCookieObserver.php             # Forces SameSite=None on all cookies
│   ├── OAuthObserver.php                     # OAuth event handler
│   └── OAuthLogoutObserver.php               # Redirects to IDP logout URL
│
├── Logger/
│   ├── Logger.php                            # Custom Monolog logger
│   └── Handler.php                           # Writes to var/log/mo_oauth.log
│
├── Helper/Exception/                         # Custom exceptions
│   ├── IncorrectUserInfoDataException.php
│   ├── MissingAttributesException.php
│   ├── NotRegisteredException.php
│   ├── RequiredFieldsException.php
│   └── SupportQueryRequiredFieldsException.php
│
└── view/
    ├── adminhtml/
    │   ├── layout/                           # Admin layout XML files
    │   ├── templates/                        # Admin .phtml templates
    │   └── web/                              # Admin CSS and JS assets
    └── frontend/
        ├── layout/                           # Frontend layout XML files
        ├── templates/                        # Frontend .phtml templates (SSO buttons, popups)
        └── web/                              # Frontend CSS, images, JS templates
```

### Authentication Flow Diagram

```
CUSTOMER FLOW:
  Browser -> SendAuthorizationRequest (frontend, stamps loginType=customer)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback)
    -> ProcessResponseAction (token exchange + userinfo)
    -> CheckAttributeMappingAction (loginType=customer)
    -> ProcessUserAction (find/create customer)
    -> CustomerLoginAction (set session, redirect)

ADMIN FLOW:
  Browser -> SendAuthorizationRequest (adminhtml, stamps loginType=admin)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback, same as customer)
    -> ProcessResponseAction (token exchange + userinfo)
    -> CheckAttributeMappingAction (loginType=admin)
    -> [if user exists] -> redirect to Oidccallback
    -> [if auto-create] -> AdminUserCreator -> redirect to Oidccallback
    -> Oidccallback -> Auth::login($email, 'OIDC_VERIFIED_USER')
       |-> OidcCredentialPlugin detects marker -> injects OidcCredentialAdapter
       |-> OidcCaptchaBypassPlugin skips CAPTCHA
       |-> OidcCredentialAdapter authenticates (no password check)
       |-> All Magento security events fire normally
    -> Admin dashboard
```

### Database Table

**`miniorange_oauth_client_apps`** — stores the OIDC provider configuration. One row per configured app.

Key columns:

| Column | Purpose |
|---|---|
| `app_name` | Provider identifier (e.g., "authelia") |
| `clientID`, `client_secret` | OAuth credentials |
| `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint` | OIDC endpoints |
| `scope` | OAuth scopes (e.g., "openid profile email groups") |
| `email_attribute`, `username_attribute`, `firstname_attribute`, `lastname_attribute` | Attribute mapping overrides |
| `group_attribute` | OIDC claim containing group memberships |
| `oauth_admin_role_mapping` | JSON: maps OIDC groups to Magento admin role IDs |
| `mo_oauth_auto_create_customer`, `mo_oauth_auto_create_admin` | JIT provisioning toggles |
| `show_customer_link`, `show_admin_link` | SSO button visibility |
| `billing_*_attribute`, `shipping_*_attribute` | Address attribute mappings |
| `values_in_header`, `values_in_body` | Token request auth method (header vs body) |
| `grant_type` | OAuth grant type (default: `authorization_code`) |
