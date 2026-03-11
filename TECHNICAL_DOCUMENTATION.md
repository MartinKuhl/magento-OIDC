# MiniOrange OAuth/OIDC SSO Module — Technical Documentation

> **Module**: `MiniOrange_OAuth` (`miniorange_inc/miniorange-oauth-sso` v4.2.0)
> **Requires**: PHP 8.1+, Magento 2.4.7+

---

## 1. Overview

This Magento 2 module adds **OAuth 2.0 / OpenID Connect** single sign-on for both **Customer** (frontend) and **Admin** (backend) users. It replaces Magento's native login flow with an external Identity Provider (Authelia, Keycloak, Auth0, etc.) while keeping all of Magento's built-in security events, ACL checks, and session handling intact.

### What it does

- Redirects users to an OIDC provider, receives an authorization code, exchanges it for tokens, and extracts user attributes.
- **Customer flow**: creates or matches a Magento customer, creates a one-time nonce cookie, and redirects to `CustomerOidcCallback` which sets the session and redirects to the relay state.
- **Admin flow**: uses Magento's native `Auth::login()` with a plugin-injected credential adapter — no bootstrap hacking, all security events fire normally. A nonce cookie is used as a one-time bridge from the OIDC callback into the admin-authenticated context.
- **JIT provisioning**: auto-creates customers and admins on first login (configurable per provider).
- **Attribute mapping**: maps OIDC claims to Magento user fields (email, name, groups, address, DOB, gender, phone).
- **Group-to-role/group mapping**: maps OIDC groups to Magento admin roles or customer groups with a configurable fallback.
- **Claims-Based Access Control**: evaluates per-provider JSON rules against OIDC claims before allowing login — can block access based on any claim value.
- **Profile sync**: optionally re-syncs profile, address, and group/role assignments on every SSO login.
- **Identity verification bypass**: OIDC-authenticated admins skip the "enter your password" prompt when editing users/roles/account settings.
- **Login restriction**: optionally blocks all non-OIDC admin logins, per provider.
- **PKCE (RFC 7636)**: supports S256 and plain code challenge methods.
- **Multi-provider**: database schema and utility layer support multiple active providers with per-provider settings.

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
- Configurable via `mo_oauth_auto_create_customer` setting (global) or per-provider `auto_create_customer` column
- Password generated but never used (authentication always via IdP)

**Address Auto-Population**
- Maps OIDC claims to Magento customer address fields using OIDC standard dotted-path notation
- Default address mappings: `address.street_address`, `address.locality`, `address.region`, `address.postal_code`, `address.country`
- Billing and shipping address fields are configured separately, each with city/state/country/street/phone/zip overrides
- Creates default billing/shipping addresses if OIDC data is present
- Reduces checkout friction — addresses pre-filled from IdP profile

**Profile Enrichment**
- Date of birth: `amDob` config key (default OIDC claim: `birthdate`) — formatted as YYYY-MM-DD
- Gender: `amGender` config key (default: `gender`) — mapped to Magento gender IDs (1=Male, 2=Female, 3=Not Specified)
- Phone: `amPhone` config key (default: `phone_number`) — stored on customer address
- All attributes synchronized on first login; controlled by `sync_customer_profile_on_sso` and `sync_customer_address_on_sso` flags on subsequent logins

**Customer Group Assignment**
- OIDC groups mapped to Magento customer groups via `customerGroupMapping` JSON
- Example: IdP group "VIP_Customers" → Magento "VIP" customer group with special pricing
- Default customer group assigned if no mapping matches (`defaultCustomerGroup` setting)
- Deny option: `createIfNotMapped` flag prevents login if no group mapping matches
- Dynamic updates: `sync_customer_group_on_sso` / `updateFrontendGroupsOnSso` re-maps groups on every login

**Session Continuity with Relay State**
- OAuth `state` parameter preserves target URL along with session ID, app name, login type, CSRF token, and provider ID
- Shopping cart preserved across IdP redirect
- Checkout flow uninterrupted by SSO
- Relay state host is validated against store URL (SEC-09) to prevent open redirect attacks

### Admin Flow Use Cases

**Zero-Touch Admin Provisioning**
- First-time admin login creates Magento admin account automatically
- Enabled via `autoCreateAdmin` setting (global) or per-provider `mo_oauth_auto_create_admin` column
- Email from IdP matched against `admin_user` table
- If no match and auto-create enabled, `AdminUserCreator::createAdminUser()` invoked

**Role Hierarchy Enforcement**
- OIDC groups mapped to Magento admin roles via `adminRoleMapping` JSON stored in `miniorange_oauth_client_apps`
- Example: IdP group "Engineering" → Magento role "Content Editors" (by role ID)
- Fallback chain: group mapping (case-insensitive) → `defaultRole` config → **deny** (null returned, user creation refused)
- Dynamic role updates: `sync_admin_role_on_sso` re-assigns roles on every login

**Password Elimination**
- **CAPTCHA bypass**: `OidcCaptchaBypassPlugin` skips CAPTCHA validation (IdP already authenticated user)
- **Password verification bypass**: `OidcIdentityVerificationPlugin` skips "enter current password" prompts when editing users/roles/account
- Detection via `oidc_authenticated` cookie (admin path scope, session lifetime)
- **Password expiration suppression**: `OidcPasswordExpirationPlugin` prevents password expiration warnings
- **Force change suppression**: `OidcForcePasswordChangePlugin` prevents forced password change redirects

**Audit Compliance**
- All standard Magento authentication events fire: `admin_user_authenticate_before`, `admin_user_authenticate_after`
- Events include `oidc_auth => true` marker for OIDC-specific handling
- Login records created via `User::recordLogin()` method
- ACL refresh triggered automatically
- Compatible with Magento's native audit logging and security extensions

**Emergency Access Patterns**
- Login restriction safety net: if OIDC button hidden (`show_admin_link` disabled) and restriction is on, password login is still allowed to prevent lockout
- Prevents lockout scenarios during IdP outages
- CLI admin creation possible: `bin/magento admin:user:create`
- Password-based emergency accounts remain functional unless restriction is explicitly enabled with the SSO button visible

### Security Use Cases

**Passwordless Authentication**
- Eliminates password-based attack vectors: phishing, brute force, credential stuffing, password reuse
- IdP enforces authentication policies (MFA, conditional access, device trust)
- No passwords stored in Magento database (random 32-char passwords generated but never used)
- Secure password generation: 28 alphanumeric + 2 special + 2 digit characters, shuffled (SEC-12)

**Claims-Based Access Control**
- Per-provider JSON rules evaluated against OIDC claims before any user routing or provisioning
- Supported operators: `eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`
- Rules are AND-combined: all rules must pass; first failure denies access
- Example: block login if `email_verified` is false, or if `groups` does not contain `magento-users`
- Configured via `access_control_rules` column in `miniorange_oauth_client_apps`
- Rules run before JIT provisioning — a blocked user is never created

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
- Nonce cookies (`oidc_admin_nonce`, `oidc_customer_nonce`) are one-time use, 120s TTL, HttpOnly, Secure, SameSite=Lax
- Cross-origin session handling: `SameSite=None; Secure; HttpOnly` applied to session cookies during OIDC routes only
- `SessionCookieObserver` applies only to `/mooauth/` routes — does not affect other cookies globally
- HTTPS required (SameSite=None only works with Secure flag)

**JWT Verification**
- Validates JWT tokens using RS256/384/512 signatures
- JWKS endpoint fetching with HTTP caching via `Helper/JwtVerifier.php`
- Key ID (kid) matching for key rotation support
- Token expiration validation, issuer validation, audience validation
- Prevents token forgery and replay attacks

**PKCE (RFC 7636)**
- Code verifier generated in `SendAuthorizationRequest`, stored in the provider's database row
- Code challenge sent to IdP as S256 (SHA-256 hash) or plain
- Code verifier retrieved and cleared (one-time use) in `ReadAuthorizationResponse`
- Prevents authorization code interception attacks

**Worker State Isolation (SEC-06)**
- `OidcCredentialPlugin::beforeLogin()` unconditionally resets all internal flags at the start of every login attempt
- Prevents PHP-FPM worker process recycling from leaking OIDC state between requests

### Anti-Patterns / Not Suitable For

**IdP-Initiated SSO**
- Module only supports SP-initiated flow (user starts at Magento, redirects to IdP)
- IdP-initiated flow (user starts at IdP, receives SAML-style POST assertion) not implemented
- Workaround: IdP can deep-link to Magento SSO URL, which is technically still SP-initiated

**Federated Logout**
- Partial support only: redirects to IdP logout URL (`endsession_endpoint`)
- No back-channel logout (OIDC RP-Initiated Logout spec)
- Magento session cleared locally, but IdP may not clear other sessions

**Complex Claim Transformations**
- No built-in conditional claim mapping (e.g., "if group = X, then map attribute Y differently")
- Attribute mappings are 1:1 only: OIDC claim → Magento field
- Workaround: configure claim transformations at IdP level before sending to Magento
- Custom logic requires a plugin on `CheckAttributeMappingAction::execute()`

---

## 4. API Reference

### `\MiniOrange\OAuth\Helper\Data`

The base data-access helper. Injected as a dependency everywhere configuration values are needed.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the frontend SSO login URL. Appends `relayState` and `app_name` as query params. Defaults `relayState` to the current URL and `app_name` to the stored config value. |
| `getAdminSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the admin backend SSO login URL. Uses the admin URL builder so the request routes through the admin `SendAuthorizationRequest` controller (which stamps `loginType=admin` into the state). |
| `getSPInitiatedUrlForProvider` | `(int $providerId, ?string $relayState = null, string $loginType = 'customer')` | `string` | Builds the SSO URL for a specific provider ID. Preferred over `getSPInitiatedUrl()` in multi-provider setups. |
| `getCallBackUrl` | `()` | `string` | Returns `{baseUrl}mooauth/actions/ReadAuthorizationResponse`. Register this in your IDP. |
| `getBaseUrl` | `()` | `string` | Returns Magento's `UrlInterface::getBaseUrl()`. |
| `getAdminBaseUrl` | `()` | `string` | Returns the admin home page URL. |
| `getStoreConfig` | `($config)` | `mixed` | Reads from `miniorange/oauth/{$config}` in `core_config_data`. In `OAuthUtility` this is overridden to resolve provider-specific keys from `miniorange_oauth_client_apps` when an active provider ID is set. |
| `setStoreConfig` | `($config, $value, $skipSanitize = false)` | `void` | Writes to `miniorange/oauth/{$config}`. Also syncs `show_admin_link` / `show_customer_link` to the `miniorange_oauth_client_apps` table. |
| `setOAuthClientApps` | `($app_name, $client_id, ...)` | `void` | Inserts a new row into the `miniorange_oauth_client_apps` table with the provider's configuration. |
| `getOAuthClientApps` | `()` | `Collection` | Returns the full collection from `miniorange_oauth_client_apps`. |
| `getAllActiveProviders` | `(string $loginType = 'customer')` | `array` | Returns all active provider rows for the given login type (`'customer'`, `'admin'`, or `'both'`). Used for multi-provider button rendering and login restriction checks. |
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
| `setActiveProviderId` | `(int $providerId)` | `void` | Sets the active provider ID for this request. Causes `getStoreConfig()` to resolve values from the matching `miniorange_oauth_client_apps` row instead of `core_config_data`. |
| `getActiveProviderId` | `()` | `?int` | Returns the currently active provider ID, or null if not set. |
| `customlog` | `($txt)` | `void` | Writes a plain-text line to `var/log/mo_oauth.log` if debug logging is enabled. |
| `customlogContext` | `(string $event, array $context = [])` | `void` | Writes a JSON-structured log entry: `{"ts":"...","level":"debug","message":"$event",...context}`. Automatically masks sensitive keys (`client_secret`, `access_token`, `id_token`, `refresh_token`, `password`, `token`). |
| `isLogEnable` | `()` | `bool` | Checks both the legacy and new debug-log config path. |
| `getAdminSession` | `()` | `Session` | Returns the backend session. |
| `setSessionData` / `getSessionData` | `($key, $value)` / `($key, $remove = false)` | `mixed` | Customer session read/write. |
| `setAdminSessionData` / `getAdminSessionData` | `($key, $value)` / `($key, $remove = false)` | `mixed` | Admin session read/write. |
| `getLogoutUrl` | `()` | `string` | Returns the appropriate logout URL based on who is logged in (customer or admin). |
| `getClientDetails` | `()` | `array` | Returns a flat array of the active app's client config (clientID, secret, endpoints, etc.). |
| `extractNameFromEmail` | `(string $email)` | `array` | Splits email into `['first' => 'prefix', 'last' => 'domain']`. Used as a name fallback when OIDC claims don't contain first/last name. |

### `\MiniOrange\OAuth\Model\Service\AdminUserCreator`

Service class for admin JIT provisioning.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createAdminUser` | `($email, $userName, $firstName, $lastName, array $userGroups, int $providerId = 0)` | `User\|null` | Creates an admin user with a random password, assigns a role based on group mapping. Returns `null` if no suitable role exists (group mapping fails and no default role configured). |
| `isAdminUser` | `(string $email)` | `bool` | Checks `admin_user` table by both username and email. |

**Role assignment fallback chain**: configured group mapping (case-insensitive) → `defaultRole` config → `null` (deny, user not created).

### `\MiniOrange\OAuth\Model\Service\CustomerUserCreator`

Service class for customer JIT provisioning.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createCustomer` | `($email, $userName, $firstName, $lastName, $flattenedAttrs, $rawAttrs, $providerId = 0)` | `Customer\|null` | Creates a customer with a random password. Maps DOB, gender, phone, and address fields from OIDC claims. Creates a default billing/shipping address if address data is present. |
| `updateCustomerGroupFromOidc` | `(CustomerInterface $customer, array $flattenedAttrs, array $rawAttrs)` | `bool` | Re-evaluates customer group from current OIDC claims and updates if changed. Called on subsequent logins when `sync_customer_group_on_sso` is enabled. |

### `\MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter`

Implements `Magento\Backend\Model\Auth\Credential\StorageInterface`. This is the bridge between OIDC and Magento's native admin auth.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `authenticate` | `($username, $password)` | `bool` | Verifies the OIDC token marker (`OIDC_VERIFIED_USER`), loads the admin user by email, checks active status and role assignment. Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth => true`. |
| `login` | `($username, $password)` | `$this` | Calls `authenticate()`, then records the login and reloads the user. |
| `reload` | `()` | `$this` | Reloads the user model from the database. |

### `\MiniOrange\OAuth\Helper\SessionHelper`

Handles cross-origin session cookie compatibility for OIDC redirects.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `configureSSOSession` | `()` | `void` | Delegates to `updateSessionCookies()`. Called at the start of every SSO flow. |
| `updateSessionCookies` | `()` | `void` | Re-sets session cookies with `SameSite=None; Secure; HttpOnly` via Magento's `CookieManager`. |
| `forceSameSiteNone` | `()` | `void` | Rewrites the PHP session cookie in the response to enforce `SameSite=None`. Called by `SessionCookieObserver` only for `/mooauth/` routes. |

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

For multi-provider setups, iterate over `getAllActiveProviders()` and use `getSPInitiatedUrlForProvider($provider['id'])` to generate one button per provider.

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
- `Controller/Actions/CheckAttributeMappingAction.php` — routes admin vs. customer, evaluates access control rules, handles attribute extraction
- `Controller/Actions/ProcessUserAction.php` — creates/updates customers based on mapped attributes
- `Model/Service/CustomerUserCreator.php` — the actual customer creation logic (DOB, gender, address, etc.)
- `Model/Service/AdminUserCreator.php` — admin creation with group-to-role mapping

The attribute mapping values are stored in the `miniorange_oauth_client_apps` table per provider.

### Pattern 4: Configure claims-based access control

Add an `access_control_rules` JSON array to the provider's row in `miniorange_oauth_client_apps`:

```json
[
  {"claim": "email_verified", "operator": "eq", "value": "true"},
  {"claim": "groups", "operator": "contains", "value": "magento-users"}
]
```

All rules must pass. A failed rule blocks login and JIT provisioning entirely — the user is never created. The `groups` claim (if it's an array) is joined with commas before string comparison.

Supported operators: `eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`.

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

### 5. Admin role mapping fallback does NOT include a role-name fallback

For security, if no group-to-role mapping matches and no default role is configured, admin auto-creation is **denied** (`AdminUserCreator::getAdminRoleFromGroups()` returns `null`). The fallback chain is strictly: group mapping → `defaultRole` config → deny. There is no implicit fallback to an "Administrators" role or role ID 1. Configure your role mappings explicitly.

### 6. OIDC-authenticated admins bypass password re-verification

The `OidcIdentityVerificationPlugin` skips the "enter your current password" prompt for admin users with the `oidc_authenticated` cookie. This cookie is set on login and cleared on logout. It's scoped to the admin path and lasts for the admin session lifetime.

### 7. `SameSite=None` cookies are scoped to OIDC routes only

The `SessionCookieObserver` (event: `controller_front_send_response_before`) checks whether the request URI contains `/mooauth/` before rewriting session cookies. Non-OIDC requests are unaffected. If you're debugging session cookie issues on non-OIDC pages, this observer is not the cause.

### 8. Debug logs auto-expire after 7 days

When debug logging is enabled, the `SendAuthorizationRequest` controller checks if the log file is older than 7 days. If so, it disables logging and deletes the log file. You'll need to re-enable it in the admin panel.

### 9. The OAuth `state` parameter encodes multiple values

The state is formatted as a JSON/Base64 structure containing relay state, session ID, app name, login type, CSRF token, and provider ID. A legacy pipe-delimited format is also supported for backward compatibility. If you're debugging state issues, decode it as Base64 JSON first, fall back to pipe-splitting.

### 10. Non-OIDC admin login can be disabled (with a safety net)

When `mo_disable_non_oidc_admin_login` is enabled on a provider, the `AdminLoginRestrictionPlugin` throws an `AuthenticationException` for any password-based admin login — but only if the OIDC button (`show_admin_link`) is visible on that provider. If the button is hidden, password login is allowed regardless, preventing a total lockout.

### 11. Nonce cookies are one-time use with a 120-second TTL

Both `oidc_admin_nonce` and `oidc_customer_nonce` are deleted immediately upon use. If a browser is slow, has cookies blocked, or if the user navigates away and back, the nonce will be missing or expired and the callback will redirect to the login page with an error. Ensure the admin browser has cookies enabled and the admin session path is not overly restrictive.

### 12. PKCE verifier is stored per provider row — concurrent logins collide

The PKCE code verifier is saved directly into the `miniorange_oauth_client_apps` database row (`pkce_code_verifier` column). If two users start the OIDC login flow for the same provider at the same time, the second write will overwrite the first, causing the first user's token exchange to fail. This is a known architectural limitation. Workaround: avoid enabling PKCE if your environment has high concurrent admin login volume.

### 13. `ProcessResponseAction` is a deprecated shim — do not add logic there

The file `Controller/Actions/ProcessResponseAction.php` exists for backward compatibility. All attribute mapping and routing logic was moved to `CheckAttributeMappingAction`. If you need to add custom processing, create a plugin on `CheckAttributeMappingAction::execute()` instead.

### 14. Do not remove the flag reset in `OidcCredentialPlugin::beforeLogin()`

`OidcCredentialPlugin` unconditionally resets `$isOidcAuth = false` and `$adapterLogged = false` at the very start of every `beforeLogin()` call, even before checking whether it's an OIDC login. This is intentional — it guards against PHP-FPM worker processes recycling request state between HTTP requests. Removing it would cause sporadic OIDC adapter injection on non-OIDC logins.

### 15. Access control rules block JIT provisioning entirely

Access control rules are evaluated in `CheckAttributeMappingAction` before any user lookup or creation. If a rule denies access, the user is never created — even if auto-create is enabled and this is the user's first login. This is intentional security-by-default behavior: configure rules carefully.

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
| `Magento\Backend\Model\Auth` | `AdminLoginRestrictionPlugin` | 5 | Blocks non-OIDC admin login when restriction is enabled; allows through if OIDC button is hidden (safety net) |
| `Magento\Backend\Model\Auth` | `OidcCredentialPlugin` | 10 | Injects `OidcCredentialAdapter` during OIDC login; resets guard flags on every `beforeLogin()` |
| `Magento\Backend\Model\Auth` | `OidcLogoutPlugin` | 20 | Deletes `oidc_authenticated` cookie on logout |
| `Magento\Captcha\Observer\CheckUserLoginBackendObserver` | `OidcCaptchaBypassPlugin` | 10 | Skips CAPTCHA for OIDC-authenticated logins |
| `Magento\User\Model\User` | `OidcIdentityVerificationPlugin` | 10 | Bypasses password re-verification for OIDC admins |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcIdentityFieldPlugin` | 20 | Removes "required" from password field in user edit form |
| `Magento\User\Block\Role\Tab\Info` | `OidcIdentityFieldPlugin` | 20 | Same for role edit form |
| `Magento\Backend\Block\System\Account\Edit\Form` | `OidcIdentityFieldPlugin` | 20 | Same for account settings form |

### Events Observed

| Event | Observer | Area |
|---|---|---|
| `controller_front_send_response_before` | `SessionCookieObserver` | frontend (scoped to `/mooauth/` routes) |
| Logout event | `OAuthLogoutObserver` | adminhtml |

---

## 8. Structure

```
MiniOrange_OAuth/
├── registration.php                          # Registers module with Magento
├── composer.json                             # Package metadata (v4.2.0)
├── etc/
│   ├── module.xml                            # Module declaration
│   ├── di.xml                                # DI config: plugins, constructor args
│   ├── db_schema.xml                         # DB tables: miniorange_oauth_client_apps, miniorange_oauth_user_provider
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
│   │   ├── SendAuthorizationRequest.php      # Step 1: Redirect to IDP (customer); generates PKCE verifier
│   │   ├── ReadAuthorizationResponse.php     # Step 2: Receive auth code, exchange for tokens, verify JWT
│   │   ├── ProcessResponseAction.php         # [DEPRECATED SHIM] Superseded by CheckAttributeMappingAction
│   │   ├── CheckAttributeMappingAction.php   # Step 3: Evaluate access rules, route admin vs customer, map attributes
│   │   ├── ProcessUserAction.php             # Step 4: Create/match customer, validate relay state, delegate login
│   │   ├── CustomerLoginAction.php           # Step 5: Create customer nonce cookie, redirect to CustomerOidcCallback
│   │   ├── CustomerOidcCallback.php          # Step 6: Redeem nonce, set customer session, redirect
│   │   └── ShowTestResults.php               # Test Configuration results display
│   └── Adminhtml/
│       ├── Actions/
│       │   ├── SendAuthorizationRequest.php  # Step 1: Redirect to IDP (admin); stamps loginType=admin
│       │   └── Oidccallback.php              # Admin login: redeems nonce, calls Auth::login() with OIDC marker
│       ├── OAuthsettings/Index.php           # Admin page: OAuth Settings
│       ├── Attrsettings/Index.php            # Admin page: Attribute Mapping
│       └── Signinsettings/Index.php          # Admin page: Sign In Settings
│
├── Model/
│   ├── Auth/
│   │   └── OidcCredentialAdapter.php         # StorageInterface impl for OIDC auth (no password check)
│   ├── Service/
│   │   ├── AdminUserCreator.php              # JIT admin provisioning + group-to-role mapping
│   │   └── CustomerUserCreator.php           # JIT customer provisioning + address/group creation
│   ├── Resolver/                             # GraphQL resolvers (if GraphQL module present)
│   ├── MiniorangeOauthClientApps.php         # Model for oauth_client_apps table
│   └── ResourceModel/
│       └── MiniOrangeOauthClientApps/
│           ├── Collection.php                # Collection model
│           └── (ResourceModel).php           # Resource model
│
├── Plugin/
│   ├── AdminLoginRestrictionPlugin.php       # Blocks non-OIDC admin login (with safety net)
│   ├── Auth/
│   │   ├── OidcCredentialPlugin.php          # Injects OIDC adapter; resets guard flags on every login
│   │   └── OidcLogoutPlugin.php              # Cleans up OIDC cookie on logout
│   ├── Captcha/
│   │   └── OidcCaptchaBypassPlugin.php       # Skips CAPTCHA for OIDC logins
│   └── User/
│       ├── OidcIdentityVerificationPlugin.php # Bypasses password re-verification
│       └── Block/
│           └── OidcIdentityFieldPlugin.php   # Removes required from password field in forms
│
├── Helper/
│   ├── Data.php                              # Base config data access + provider management
│   ├── OAuthUtility.php                      # Extended utility (sessions, cache, logging, provider resolution)
│   ├── OAuthConstants.php                    # All constants (config keys, defaults, URLs)
│   ├── OAuthMessages.php                     # User-facing message templates
│   ├── SessionHelper.php                     # SameSite=None cookie handling (OIDC routes only)
│   ├── Curl.php                              # HTTP client for token/userinfo requests
│   ├── JwtVerifier.php                       # JWT signature validation using JWKS
│   ├── TestResults.php                       # Test configuration HTML output
│   └── OAuth/
│       ├── AuthorizationRequest.php          # Builds the authorize URL query string (includes PKCE challenge)
│       ├── AccessTokenRequest.php            # Builds the token exchange POST body
│       └── AccessTokenRequestBody.php        # Alternate token body (header auth variant)
│
├── Service/                                  # Additional service layer classes
│
├── UI/                                       # Admin grid UI components
│
├── Block/
│   ├── OAuth.php                             # Admin template block (config getters)
│   └── Adminhtml/
│       ├── Debug.php                         # Debug info block
│       └── OidcErrorMessage.php              # OIDC error display block
│
├── Observer/
│   ├── SessionCookieObserver.php             # Forces SameSite=None on session cookies (mooauth routes only)
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
  Browser -> SendAuthorizationRequest (frontend, stamps loginType=customer, generates PKCE verifier)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback: validates state, exchanges code + PKCE verifier for tokens, verifies JWT)
    -> CheckAttributeMappingAction (evaluates access control rules, maps attributes)
    -> ProcessUserAction (find/create customer, validate relay state)
    -> CustomerLoginAction (creates oidc_customer_nonce cookie, 120s TTL)
    -> CustomerOidcCallback (redeems nonce, sets customer session, redirects to relay state)

ADMIN FLOW:
  Browser -> SendAuthorizationRequest (adminhtml, stamps loginType=admin, generates PKCE verifier)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback, same as customer)
    -> CheckAttributeMappingAction (evaluates access control rules, loginType=admin)
    -> [if user exists] -> creates oidc_admin_nonce cookie (120s TTL) -> redirect to Oidccallback
    -> [if auto-create] -> AdminUserCreator -> creates nonce -> redirect to Oidccallback
    -> Oidccallback -> redeems nonce -> Auth::login($email, 'OIDC_VERIFIED_USER')
       |-> OidcCredentialPlugin detects marker (after resetting guard flags) -> injects OidcCredentialAdapter
       |-> OidcCaptchaBypassPlugin skips CAPTCHA
       |-> OidcCredentialAdapter authenticates (no password check; fires auth events with oidc_auth=true)
       |-> All Magento security events fire normally
    -> Sets oidc_authenticated cookie -> Admin dashboard
```

### Database Tables

**`miniorange_oauth_client_apps`** — stores the OIDC provider configuration. One row per configured provider.

Key columns:

| Column | Purpose |
|---|---|
| `id` | Primary key; used as `provider_id` throughout the module |
| `app_name` | Provider identifier (e.g., "authelia") |
| `clientID`, `client_secret` | OAuth credentials |
| `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint` | OIDC endpoints |
| `endsession_endpoint` | IdP logout URL for RP-Initiated Logout redirect |
| `jwks_endpoint` | JWKS URL for JWT signature verification |
| `well_known_config_url` | OIDC discovery document URL |
| `scope` | OAuth scopes (e.g., "openid profile email groups") |
| `pkce_flow`, `pkce_code_verifier` | PKCE method ('S256'/'plain') and stored verifier (one-time use) |
| `email_attribute`, `username_attribute`, `firstname_attribute`, `lastname_attribute` | Attribute mapping overrides |
| `group_attribute` | OIDC claim containing group memberships |
| `oauth_admin_role_mapping` | JSON: maps OIDC groups to Magento admin role IDs |
| `oauth_customer_group_mapping` | JSON: maps OIDC groups to Magento customer group IDs |
| `access_control_rules` | JSON: claims-based access control rules (FEAT-04) |
| `mo_oauth_auto_create_customer`, `mo_oauth_auto_create_admin` | Per-provider JIT provisioning toggles |
| `mo_disable_non_oidc_admin_login` | Per-provider: block password-based admin login |
| `show_customer_link`, `show_admin_link` | SSO button visibility |
| `autoredirect_admin`, `autoredirect_customer` | Auto-redirect from login page |
| `is_active`, `login_type`, `sort_order` | Multi-provider: activation, scope ('customer'/'admin'/'both'), display order |
| `display_name`, `button_label`, `button_color` | Multi-provider UI customization |
| `sync_customer_profile_on_sso`, `sync_customer_address_on_sso`, `sync_customer_group_on_sso` | Re-sync customer data on every login |
| `sync_admin_profile_on_sso`, `sync_admin_role_on_sso` | Re-sync admin data on every login |
| `values_in_header`, `values_in_body` | Token request auth method (header vs body) |
| `grant_type` | OAuth grant type (default: `authorization_code`) |
| `last_test_status`, `last_test_at`, `received_oidc_claims` | Test configuration tracking |

---

**`miniorange_oauth_user_provider`** — tracks which OIDC provider created each Magento user.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `user_type` | `'customer'` or `'admin'` |
| `user_id` | Magento `entity_id` (customer) or `user_id` (admin) |
| `provider_id` | References `miniorange_oauth_client_apps.id` |
| `created_at` | Timestamp, auto-set on insert |

Unique constraint on `(user_type, user_id)` — each Magento user is linked to at most one provider. Used by the logout observer to retrieve the correct `endsession_endpoint` for the logged-in user's provider.

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
- **Location**: `Model/Auth/OidcCredentialAdapter.php` `__wakeup()` method
- **Issue**: Uses `ObjectManager::getInstance()` for post-deserialization dependency injection
- **Risk**: Tight coupling, hard to test, violates dependency injection principle
- **Fix**: Implement `Serializable` properly or use a service locator pattern

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

**Fix PKCE Concurrent Login Race Condition**
- **Current state**: PKCE code verifier stored in `miniorange_oauth_client_apps` row — single slot per provider
- **Issue**: Two concurrent logins for the same provider overwrite each other's verifier
- **Fix**: Store verifier in session (keyed by provider ID) or add a per-request verifier table
- **Impact**: Medium — only affects PKCE-enabled providers with concurrent logins

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

**Support Token Revocation Endpoint**
- **Use case**: Explicitly revoke tokens on logout
- **Current**: Logout only clears local session and redirects to IdP logout URL
- **Improvement**: Call IdP's token revocation endpoint (`revocation_endpoint` from OIDC discovery)

### Architecture & Scalability

**Consolidate Multi-Provider Admin UI**
- **Current state**: Database schema fully supports multiple active providers; admin UI for managing them is incomplete or provider-management grid is absent
- **Recommendation**: Add a provider management grid in the admin panel with per-provider edit/delete/test actions
- **Benefit**: Enables multi-IdP setups without direct database manipulation

**Refactor 60+ Configuration Columns**
- **Current state**: Single-row `miniorange_oauth_client_apps` table with 60+ columns
- **Issue**: Schema bloat, difficult to extend, no versioning
- **Recommendation**: Normalize into separate tables:
  - `miniorange_oauth_providers` — provider credentials and endpoints
  - `miniorange_oauth_attribute_mappings` — attribute mappings with EAV-style flexibility
  - `miniorange_oauth_role_mappings` — group-to-role mappings (one row per mapping)

**Implement Strategy Pattern for Attribute Mapping**
- **Current**: Attribute mapping hardcoded in `Model/Service/CustomerUserCreator.php` and `AdminUserCreator.php`
- **Issue**: Difficult to extend for custom attributes
- **Recommendation**: `AttributeMapperInterface` with provider-specific implementations

**Extract JIT Provisioning into Standalone Service Layer**
- **Current**: Provisioning logic mixed with controller logic in `CheckAttributeMappingAction.php`
- **Issue**: Controller too complex, difficult to test and extend
- **Recommendation**: Create `UserProvisioningService` with separate methods for admin/customer flows

**Add Event-Driven Hooks for Extensibility**
- Fire events at key points: pre-provisioning, post-provisioning, attribute-mapping
- Allow custom modules to inject logic via observers
- Examples: `oidc_admin_user_before_create`, `oidc_customer_attribute_mapping`

### Feature Completeness

**IdP-Initiated SSO Support**
- **Current**: SP-initiated only
- **Missing**: IdP-initiated (IdP sends user directly to Magento with assertion)
- **Use case**: Users start at IdP dashboard, click Magento app icon

**Back-Channel Logout (OIDC RP-Initiated Logout)**
- **Current**: Frontend logout redirects to `endsession_endpoint`
- **Missing**: Back-channel logout (IdP notifies Magento of logout via server-to-server call)
- **Specification**: OpenID Connect RP-Initiated Logout 1.0

**Attribute Synchronization Scheduler**
- **Use case**: Sync user attributes from IdP nightly (address changes, group changes, profile updates)
- **Current**: Attributes only updated on login
- **Implementation**: Cron job that refreshes user info from IdP for active users
- **Requires**: Refresh token storage, IdP API access

### Operational Improvements

**Admin UI for Viewing Active OIDC Sessions**
- **Use case**: Admins want to see who's logged in via OIDC, when, from where
- **Data source**: `miniorange_oauth_user_provider` table + Magento session storage

**Health Check Endpoint for IdP Connectivity**
- **Use case**: Monitoring systems check if IdP is reachable from Magento
- **Endpoint**: `/mooauth/health/check` — tests authorize endpoint, returns JSON status

**Configuration Export/Import**
- **Use case**: Deploy OIDC configuration across dev/staging/prod environments
- **Implementation**: CLI commands: `bin/magento oauth:config:export`, `bin/magento oauth:config:import`
- **Format**: JSON or YAML file with all provider settings

### Developer Experience

**GraphQL API for SSO Link Generation**
- **Use case**: Headless commerce implementations need SSO URLs
- **Query**: `query { oidcLoginUrl(relayState: String, providerId: Int): String }`
- **Schema**: Add to `etc/schema.graphqls`

**REST API for Configuration Management**
- **Endpoints**:
  - `GET /rest/V1/oauth/config` — retrieve current configuration
  - `PUT /rest/V1/oauth/config` — update configuration
- **Authentication**: Admin token required

**Better Error Messages**
- **Current**: Generic errors like "configuration error", "authentication failed"
- **Improvement**: Specific, actionable error messages
  - "OIDC provider returned email claim 'Email' but expected 'email' — check attribute mapping"
  - "Admin role mapping failed: no role found for group 'Engineers' — configure role mapping or set default role"

---

This roadmap represents approximately 6-12 months of development effort for a small team. The most critical items are testing coverage, the PKCE race condition fix, and completing the multi-provider admin UI.
