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

## 3. Functionalities and Use Cases

### Primary Use Cases

This module solves real-world authentication challenges in enterprise and e-commerce environments:

**Enterprise SSO Integration**
- Centralize identity management with corporate IdP (Authelia, Keycloak, Auth0, Azure AD, Okta, Google)
- Single identity across multiple systems (Magento + other enterprise applications)
- Compliance requirements: audit trails via IdP, centralized access logs, GDPR-compliant identity management
- Eliminate password management overhead: no password resets, no credential storage, no password policies

**Multi-Store Customer Federation**
- Share customer identity across multiple Magento stores
- Customer authenticates once, accesses all stores in the network
- Useful for multi-brand retailers or marketplace platforms

**B2B Customer Onboarding Automation**
- Automatically provision customers from corporate directory
- Map organizational groups to Magento customer groups
- Pre-populate billing/shipping addresses from OIDC claims
- Reduce manual account creation and support overhead

**Admin Team Management**
- Dynamic admin role assignment based on corporate roles
- Zero-touch admin provisioning for new employees
- Automatic role updates when organizational structure changes
- Centralized deprovisioning when employees leave

### Customer Flow Use Cases

**Guest-to-Customer Conversion (JIT Provisioning)**
- First-time OIDC login automatically creates Magento customer account
- No manual registration required — IdP authentication is sufficient
- Configurable via `mo_oauth_auto_create_customer` setting
- Password generated but never used (authentication always via IdP)

**Address Auto-Population**
- Maps 30+ OIDC claims to Magento customer fields
- Billing address fields: `billing_city_attribute`, `billing_state_attribute`, `billing_country_attribute`, `billing_address_attribute`, `billing_phone_attribute`, `billing_zip_attribute`
- Shipping address fields: `shipping_city_attribute`, `shipping_state_attribute`, `shipping_country_attribute`, `shipping_address_attribute`, `shipping_phone_attribute`, `shipping_zip_attribute`
- Creates default billing/shipping addresses if OIDC data present
- Reduces checkout friction — addresses pre-filled from IdP profile

**Profile Enrichment**
- Date of birth: `dob_attribute` (default: `birthdate`) — formatted as YYYY-MM-DD
- Gender: `gender_attribute` (default: `gender`) — mapped to Magento gender IDs
- Phone: `phone_attribute` (default: `phone_number`) — stored with optional obfuscation
- All attributes synchronized on first login, optionally updated on subsequent logins

**Customer Group Assignment**
- OIDC groups mapped to Magento customer groups via `oauth_customer_group_mapping` JSON
- Example: IdP group "VIP_Customers" → Magento "VIP" customer group with special pricing
- Default customer group assigned if no mapping matches (`default_group` setting)
- Dynamic updates: `update_frontend_groups_on_sso` re-maps groups on every login

**Session Continuity with Relay State**
- OAuth `state` parameter preserves target URL: `encodedRelayState|sessionId|encodedAppName|loginType`
- Shopping cart preserved across IdP redirect
- Checkout flow uninterrupted by SSO
- User redirected to original page after successful authentication

### Admin Flow Use Cases

**Zero-Touch Admin Provisioning**
- First-time admin login creates Magento admin account automatically
- Enabled via `mo_oauth_auto_create_admin` setting
- Email from IdP matched against `admin_user` table
- If no match and auto-create enabled, `AdminUserCreator::createAdminUser()` invoked

**Role Hierarchy Enforcement**
- OIDC groups mapped to Magento admin roles via `oauth_admin_role_mapping` JSON stored in database
- Example: IdP group "Engineering" → Magento role "Content Editors"
- Fallback chain: group mapping → `default_role` → "Administrators" role → role ID 1
- Role denied if no mapping and no fallback configured (security by default)
- Dynamic role updates: `update_backend_roles_on_sso` re-assigns roles on every login

**Password Elimination**
- **CAPTCHA bypass**: `OidcCaptchaBypassPlugin` skips CAPTCHA validation (IdP already authenticated user)
- **Password verification bypass**: `OidcIdentityVerificationPlugin` skips "enter current password" prompts when editing users/roles/account
- Detection via `oidc_authenticated` cookie (24-hour duration, admin path scope)
- **Password expiration suppression**: `OidcPasswordExpirationPlugin` prevents password expiration warnings
- **Force change suppression**: `OidcForcePasswordChangePlugin` prevents forced password change redirects

**Audit Compliance**
- All standard Magento authentication events fire: `admin_user_authenticate_before`, `admin_user_authenticate_after`
- Events include `oidc_auth => true` marker for OIDC-specific handling
- Login records created via `User::recordLogin()` method
- ACL refresh triggered automatically
- Compatible with Magento's native audit logging and security extensions

**Emergency Access Patterns**
- Login restriction safety net: if OIDC button hidden (`show_admin_link` disabled), password login allowed
- Prevents lockout scenarios during IdP outages
- CLI admin creation possible: `bin/magento admin:user:create`
- Password-based emergency accounts remain functional unless explicitly restricted

### Security Use Cases

**Passwordless Authentication**
- Eliminates password-based attack vectors: phishing, brute force, credential stuffing, password reuse
- IdP enforces authentication policies (MFA, conditional access, device trust)
- No passwords stored in Magento database (random 32-char passwords generated but never used)
- Secure password generation: 28 alphanumeric + 2 special + 2 digit characters

**Centralized Access Revocation**
- Disable user at IdP → immediate effect across all integrated systems including Magento
- No need to disable accounts individually in each system
- Useful for employee offboarding, compromised accounts, access policy changes

**MFA Enforcement at IdP Level**
- Magento inherits IdP's MFA settings (TOTP, SMS, push notifications, biometric)
- No Magento-specific MFA plugins required
- Centralized MFA configuration and audit logs
- Transparent to Magento — user arrives already authenticated with MFA

**Session Security**
- Cross-origin session handling: `SameSite=None; Secure; HttpOnly` cookies enforced globally
- Required for OIDC redirect flows (IdP on different domain)
- `SessionCookieObserver` rewrites all `Set-Cookie` headers before response
- HTTPS required (SameSite=None only works with Secure flag)

**JWT Verification**
- Validates JWT tokens using RS256/384/512 signatures
- JWKS endpoint fetching with HTTP caching via `Helper/JwtVerifier.php`
- Key ID (kid) matching for key rotation support
- Token expiration validation, issuer validation, audience validation
- Prevents token forgery and replay attacks

### Anti-Patterns / Not Suitable For

**Multi-Provider Scenarios**
- Module supports only one OIDC provider per store
- Database limitation: single row in `miniorange_oauth_client_apps` table, no provider selector UI
- Workaround: separate store views with separate database configurations (requires core modification)
- Future enhancement needed for native multi-provider support

**IdP-Initiated SSO**
- Module only supports SP-initiated flow (user starts at Magento, redirects to IdP)
- IdP-initiated flow (user starts at IdP, receives SAML-style POST assertion) not implemented
- Magento callback expects OAuth authorization code exchange, not direct assertion
- Workaround: IdP can deep-link to Magento SSO URL, but still technically SP-initiated

**Federated Logout**
- Partial support only: redirects to IdP logout URL (`post_logout_url` endpoint)
- No back-channel logout (OIDC RP-Initiated Logout spec)
- Magento session cleared locally, but IdP may not clear other sessions
- Workaround: users must log out at IdP separately for complete session termination

**Complex Claim Transformations**
- No built-in claim mapping logic (e.g., "if group = X, then map attribute Y differently")
- Attribute mappings are 1:1 only: OIDC claim → Magento field
- Workaround: configure claim transformations at IdP level before sending to Magento
- Custom logic requires plugin on `CheckAttributeMappingAction::execute()`

**Global Cookie Enforcement Impact**
- `SessionCookieObserver` rewrites **all** cookies globally with `SameSite=None`
- May affect non-OIDC modules' cookie behavior
- Required for OIDC cross-origin flows but broader than necessary
- Workaround: modify observer to check request path and only apply to OIDC routes

---

## 4. API Reference

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

## 5. Common Patterns

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

## 6. Gotchas

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

## 7. Related Modules

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

## 8. Structure

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

---

## 9. Future Improvements

This section documents technical debt, architectural limitations, and potential enhancements identified during codebase analysis. Organized by category and prioritized by impact.

### Testing & Code Quality

**Add Unit Test Coverage**
- **Current state**: 0% test coverage — no `Test/Unit/` or `Test/Integration/` directories present
- **Impact**: High risk of regressions from future changes
- **Recommendation**: Start with Model and Helper classes
  - `Model/Service/AdminUserCreator.php` — role mapping fallback chain
  - `Model/Service/CustomerUserCreator.php` — address creation logic
  - `Helper/JwtVerifier.php` — JWT signature validation
  - `Model/Auth/OidcCredentialAdapter.php` — authentication flow

**Add Integration Tests**
- Test full authentication flows (customer and admin)
- Mock OIDC provider (Docker-based local test environment)
- Verify security plugins fire correctly (CAPTCHA bypass, identity verification bypass)
- Test JIT provisioning with various role mappings

**Fix Unsafe ObjectManager Usage**
- **Location**: `Model/Auth/OidcCredentialAdapter.php` lines 88-100 (`__wakeup()` method)
- **Issue**: Uses `ObjectManager::getInstance()` for post-deserialization dependency injection
- **Risk**: Tight coupling, hard to test, violates dependency injection principle
- **Fix**: Implement `Serializable` properly or use service locator pattern

**Static Analysis Compliance**
- Add PHPStan or Psalm configuration
- Fix type hint inconsistencies (e.g., `OidcCredentialAdapter::$user` property)
- Enable strict type checking for new code
- Add return type declarations consistently

### Security Enhancements

**Add Explicit CSRF Token Validation**
- **Current state**: OAuth `state` parameter includes session ID but no independent CSRF token
- **Location**: `Controller/Actions/ReadAuthorizationResponse.php`
- **Recommendation**: Generate CSRF token on authorization request, validate on callback
- **Benefit**: Defense-in-depth against sophisticated CSRF attacks

**Scope SessionCookieObserver to OIDC Paths Only**
- **Current state**: `Observer/SessionCookieObserver.php` rewrites **all** cookies globally with `SameSite=None`
- **Issue**: May interfere with other modules' cookie behavior
- **Fix**: Check request path in `forceSameSiteNone()` — only apply to `/mooauth/` routes
- **Alternative**: Apply only to session cookies, not all cookies

**Add Rate Limiting for Callback Endpoint**
- **Location**: `Controller/Actions/ReadAuthorizationResponse.php`
- **Risk**: Attackers could spam callback with invalid authorization codes
- **Recommendation**: Implement IP-based or user-based rate limiting
- **Options**: Magento's built-in rate limiting, Cloudflare integration, or Redis-based limiter

**Implement Token Refresh Handling**
- **Current state**: Access tokens stored in session but no refresh logic
- **Issue**: Long sessions may outlive access token expiration
- **Recommendation**: Check token expiration before API calls, refresh if needed
- **Requires**: Store refresh token securely, implement refresh flow

**Add PKCE Flow Enforcement Option**
- **Current state**: PKCE optional via `pkce_flow` database column
- **Security benefit**: Prevents authorization code interception attacks
- **Recommendation**: Add admin UI toggle to enforce PKCE, default to enabled
- **Target**: OAuth 2.1 compliance

**Support Token Revocation Endpoint**
- **Use case**: Explicitly revoke tokens on logout
- **Current**: Logout only clears local session
- **Improvement**: Call IdP's token revocation endpoint (`revocation_endpoint` from OIDC discovery)
- **Benefit**: Invalidate tokens immediately, not just when they expire

### Architecture & Scalability

**Refactor 60+ Configuration Columns**
- **Current state**: Single-row `miniorange_oauth_client_apps` table with 60+ columns
- **Issue**: Schema bloat, difficult to extend, no versioning
- **Recommendation**: Normalize into separate tables:
  - `miniorange_oauth_providers` — provider credentials and endpoints
  - `miniorange_oauth_attribute_mappings` — attribute mappings with EAV-style flexibility
  - `miniorange_oauth_role_mappings` — group-to-role mappings (one row per mapping)
- **Benefit**: Easier to query, extend, and maintain

**Add Multi-Provider Support**
- **Current limitation**: Only one OIDC provider per store
- **Database change**: Add `provider_id` column to config tables, allow multiple providers
- **UI change**: Provider selection dropdown in `SendAuthorizationRequest` controller
- **Use case**: Different regions with different IdPs, or multiple identity sources (corporate + customer)

**Implement Strategy Pattern for Attribute Mapping**
- **Current**: Attribute mapping hardcoded in `Model/Service/CustomerUserCreator.php` and `AdminUserCreator.php`
- **Issue**: Difficult to extend for custom attributes
- **Recommendation**: `AttributeMapperInterface` with provider-specific implementations
- **Example**: `StandardOidcMapper`, `Auth0Mapper`, `KeycloakMapper` with provider-specific claim handling

**Extract JIT Provisioning into Standalone Service Layer**
- **Current**: Provisioning logic mixed with controller logic in `CheckAttributeMappingAction.php`
- **Issue**: Controller too complex (260+ lines), difficult to test and extend
- **Recommendation**: Create `UserProvisioningService` with separate methods for admin/customer flows
- **Benefit**: Testable, reusable, cleaner controller code

**Add Event-Driven Hooks for Extensibility**
- Fire events at key points: pre-provisioning, post-provisioning, attribute-mapping
- Allow custom modules to inject logic via observers
- Examples: `oidc_admin_user_before_create`, `oidc_customer_attribute_mapping`
- Benefit: Customize provisioning without modifying core module code

### Feature Completeness

**IdP-Initiated SSO Support**
- **Current**: SP-initiated only (Magento redirects to IdP)
- **Missing**: IdP-initiated (IdP sends user directly to Magento with assertion)
- **Implementation**: Accept SAML-style POST assertions at callback endpoint
- **Use case**: Users start at IdP dashboard, click Magento app icon
- **Complexity**: Medium — requires assertion validation, different from authorization code flow

**Back-Channel Logout (OIDC RP-Initiated Logout)**
- **Current**: Frontend logout redirects to `post_logout_url`
- **Missing**: Back-channel logout (IdP notifies Magento of logout via server-to-server call)
- **Specification**: OpenID Connect RP-Initiated Logout 1.0
- **Benefit**: Logout propagates across all integrated systems instantly
- **Implementation**: Add logout endpoint, validate logout tokens, clear sessions

**Claims-Based Access Control**
- **Use case**: Deny login based on OIDC claims (e.g., `email_verified=false`, `status=inactive`)
- **Current**: All authenticated users allowed (if admin/customer creation succeeds)
- **Implementation**: Add claim validation rules in `CheckAttributeMappingAction.php`
- **Configuration**: Admin UI for claim validation rules (claim name, operator, value, action)

**Dynamic Role/Group Updates on Each Login**
- **Current state**: Partial implementation (`update_backend_roles_on_sso`, `update_frontend_groups_on_sso` toggles exist)
- **Issue**: Inconsistent behavior, not always reliable
- **Recommendation**: Consolidate and test dynamic update logic
- **Use case**: Admin role changed in IdP during active session, re-mapped on next login

**Attribute Synchronization Scheduler**
- **Use case**: Sync user attributes from IdP nightly (address changes, group changes, profile updates)
- **Current**: Attributes only updated on login
- **Implementation**: Cron job that refreshes user info from IdP for active users
- **Requires**: Refresh token storage, IdP API access

### Operational Improvements

**Structured Logging**
- **Current**: Free-text logging via `customlog()` in `OAuthUtility.php`
- **Issue**: Difficult to parse logs, no log levels, no structured fields
- **Recommendation**: JSON-formatted logs with structured fields (user_id, event_type, timestamp, ip_address)
- **Benefit**: Integration with log aggregation tools (Splunk, ELK stack, Datadog)
- **Example**: `{"event": "oidc_login", "user_email": "user@example.com", "result": "success", "duration_ms": 342}`

**Admin UI for Viewing Active OIDC Sessions**
- **Use case**: Admins want to see who's logged in via OIDC, when, from where
- **Implementation**: Admin grid showing OIDC-authenticated users, last login time, session expiration
- **Data source**: Session storage + custom database table for audit trail
- **Benefit**: Visibility, troubleshooting, compliance

**Health Check Endpoint for IdP Connectivity**
- **Use case**: Monitoring systems check if IdP is reachable from Magento
- **Endpoint**: `/mooauth/health/check` — tests authorize endpoint, returns JSON status
- **Response**: `{"idp_reachable": true, "response_time_ms": 125, "last_check": "2026-02-19T10:30:00Z"}`
- **Benefit**: Proactive alerting for IdP outages

**Configuration Export/Import**
- **Use case**: Deploy OIDC configuration across dev/staging/prod environments
- **Current**: Manual configuration in each environment
- **Implementation**: CLI commands: `bin/magento oauth:config:export`, `bin/magento oauth:config:import`
- **Format**: JSON or YAML file with all provider settings
- **Benefit**: Environment parity, faster deployments, configuration as code

**Observability Hooks**
- Integration with Prometheus (expose metrics: `oidc_login_attempts`, `oidc_login_success`, `oidc_login_failures`)
- New Relic custom events for OIDC authentication flows
- Datadog APM spans for OIDC performance monitoring
- Benefit: Real-time monitoring, alerting, performance analysis

### Developer Experience

**GraphQL API for SSO Link Generation**
- **Use case**: Headless commerce implementations need SSO URLs
- **Query**: `query { oidcLoginUrl(relayState: String): String }`
- **Implementation**: Inject `OAuthUtility`, call `getSPInitiatedUrl($relayState)`
- **Schema**: Add to `etc/schema.graphqls`
- **Benefit**: Supports modern frontend architectures (Next.js, React, Vue)

**REST API for Configuration Management**
- **Use case**: Programmatic configuration management, infrastructure as code
- **Endpoints**:
  - `GET /rest/V1/oauth/config` — retrieve current configuration
  - `PUT /rest/V1/oauth/config` — update configuration
- **Authentication**: Admin token required
- **Benefit**: Automate configuration changes, integrate with CI/CD

**CLI Commands for Testing Auth Flow**
- **Command**: `bin/magento oauth:test-flow --user=user@example.com`
- **Action**: Simulates OIDC flow, displays each step, shows received claims
- **Output**: Step-by-step log of authorization request, token exchange, userinfo retrieval
- **Benefit**: Debug issues without browser, automate testing

**Better Error Messages**
- **Current**: Generic errors like "configuration error", "authentication failed"
- **Issue**: Difficult to troubleshoot, users don't know what to fix
- **Improvement**: Specific, actionable error messages
  - "OIDC provider returned email claim 'Email' but expected 'email' — check attribute mapping"
  - "Admin role mapping failed: no role found for group 'Engineers' — configure role mapping or set default role"
- **Implementation**: Custom exception classes with detailed messages, error code taxonomy

**Debugging Mode with Detailed Flow Visualization**
- **Feature**: Admin UI page showing real-time OIDC flow visualization
- **Display**: Timeline of requests/responses, token contents (decoded JWT), attribute mappings applied
- **Trigger**: Query parameter `?oidc_debug=1` (admin-only, logs all steps)
- **Benefit**: Understand flow without reading logs, visual debugging for non-technical admins

---

This roadmap represents approximately 6-12 months of development effort for a small team. Priorities should be based on actual usage patterns, security requirements, and business value. The most critical items are testing coverage, security enhancements (CSRF, rate limiting), and operational improvements (structured logging, health checks).
