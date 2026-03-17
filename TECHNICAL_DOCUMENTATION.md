# M2Oidc OAuth/OIDC SSO Module — Technical Documentation

> **Module**: `M2Oidc_OAuth` (`m2oidc_inc/m2oidc-oauth-sso` v4.2.0)
> **Requires**: PHP 8.1+, Magento 2.4.7+

---

## 1. Overview

This Magento 2 module adds **OAuth 2.0 / OpenID Connect** single sign-on for both **Customer** (frontend) and **Admin** (backend) users. It replaces Magento's native login flow with an external Identity Provider (Authelia, Keycloak, Auth0, etc.) while keeping all of Magento's built-in security events, ACL checks, and session handling intact.

### What it does

- Redirects users to an OIDC provider, receives an authorization code, exchanges it for tokens, and extracts user attributes.
- **Customer flow**: creates or matches a Magento customer, creates a one-time nonce cookie, and redirects to `CustomerOidcCallback` which sets the session and redirects to the relay state.
- **Admin flow**: uses Magento's native `Auth::login()` with a plugin-injected credential adapter — no bootstrap hacking, all security events fire normally. A nonce cookie bridges from the OIDC callback into the admin-authenticated context.
- **JIT provisioning**: auto-creates customers and admins on first login (configurable per provider).
- **Attribute mapping**: maps OIDC claims to Magento user fields (email, name, groups, address, DOB, gender, phone). Per-provider overrides take priority over global config.
- **Auto-discovery**: if a `well_known_config_url` is configured, all OIDC endpoints (authorize, token, userinfo, JWKS, logout, revocation, issuer) are auto-populated from the provider's discovery document on save.
- **Group-to-role/group mapping**: maps OIDC groups to Magento admin roles or customer groups with a configurable fallback. Stored in a normalized `m2oidc_oauth_role_mappings` table.
- **Claims-Based Access Control**: evaluates per-provider JSON rules against OIDC claims before allowing login — can block access based on any claim value.
- **Profile sync**: optionally re-syncs profile, address, and group/role assignments on every SSO login.
- **Identity verification bypass**: OIDC-authenticated admins skip the "enter your password" prompt when editing users/roles/account settings.
- **Password lifecycle suppression**: `OidcPasswordExpirationPlugin` and `OidcForcePasswordChangePlugin` prevent password expiry warnings and forced password-change redirects for OIDC-authenticated admins.
- **Login restriction**: optionally blocks all non-OIDC admin or customer logins, per provider. Protected by a lockout-prevention guard that reverts the setting if no OIDC users exist yet.
- **Lockout-prevention guards**: on provider save, if "disable non-OIDC login" is enabled but no users of that type have authenticated via OIDC for that provider, the setting is automatically reverted with a warning.
- **User-delete cleanup**: `AdminUserDeleteObserver` and `CustomerDeleteObserver` remove OIDC provider mappings from `m2oidc_oauth_user_provider` when Magento users are deleted, keeping the Sessions activity view accurate.
- **Dirty-field tracking**: `view/adminhtml/web/js/dirtyTracking.js` highlights modified provider form fields with an amber border before save, using `MutationObserver` to track dynamically added mapping rows.
- **PKCE (RFC 7636)**: supports S256 and plain code challenge methods. The code verifier is stored per provider ID in the session — concurrent logins for the same provider do not collide.
- **Rate limiting**: `OidcRateLimiter` enforces an IP-based attempt window on the callback endpoint to prevent code-stuffing attacks.
- **CSRF protection**: a token is embedded in the OAuth `state` parameter, generated on authorization request and validated on callback.
- **RP-Initiated Logout**: on admin logout, the `OidcLogoutPlugin` captures session tokens before destruction, calls the IdP's `end_session_endpoint`, and optionally revokes the access token via RFC 7009. Supports both standard OIDC and Authelia Forward-Auth logout modes.
- **Back-Channel Logout**: `POST /m2oidc/actions/backchannel-logout` accepts a signed JWT logout token from the IdP and destroys the matching PHP session via `OidcSessionRegistry`.
- **Multi-provider**: database schema and utility layer support multiple active providers with per-provider settings, managed via the Provider grid at `/admin/m2oidc/provider/index`.
- **Session Activity view**: `/admin/m2oidc/sessions/index` lists all users who authenticated via OIDC, with total and active user counts per provider.
- **CLI tools**: `bin/magento oauth:config:export` and `bin/magento oauth:config:import` for moving provider configuration across environments.
- **Health check**: `GET /m2oidc/health/check` tests IdP reachability from Magento and returns JSON status.

### Why it exists

Out of the box, Magento has no OIDC support. This module bridges that gap while respecting Magento's plugin architecture — no core patches, no rewrites, just DI-configured plugins and observers.

---

## 2. Quick Start

### Step 1 — Install and enable

```bash
composer require m2oidc_inc/m2oidc-oauth-sso
bin/magento module:enable M2Oidc_OAuth
bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
```

### Step 2 — Configure the provider

Navigate to **Stores > Configuration > M2Oidc > OAuth/OIDC** and fill in:

| Field | Example value |
|---|---|
| App Name | `authelia` |
| Client ID | `magento-store` |
| Client Secret | `your-secret` |
| **Well-Known Config URL** *(optional)* | `https://auth.example.com/.well-known/openid-configuration` |
| Authorize Endpoint | `https://auth.example.com/api/oidc/authorization` |
| Token Endpoint | `https://auth.example.com/api/oidc/token` |
| User Info Endpoint | `https://auth.example.com/api/oidc/userinfo` |
| Scope | `openid profile email groups` |

> **Tip — Auto-Discovery**: Enter only the **Well-Known Config URL** and save. The module fetches the OIDC discovery document and auto-fills all endpoint URLs, JWKS URL, and issuer. Manual endpoint entry is only needed for IdPs that don't publish a discovery document.

Set the **Callback URL** in your IDP to:

```
https://your-site.com/m2oidc/actions/ReadAuthorizationResponse
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
- Configurable via `m2oidc_auto_create_customer` setting (global) or per-provider column
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
- OIDC groups mapped to Magento customer groups via the normalized `m2oidc_oauth_role_mappings` table (`mapping_type = 'customer_group'`)
- Example: IdP group "VIP_Customers" → Magento "VIP" customer group with special pricing
- Default customer group assigned if no mapping matches (`defaultCustomerGroup` setting)
- Deny option: `createIfNotMapped` flag prevents login if no group mapping matches
- Dynamic updates: `sync_customer_group_on_sso` / `updateFrontendGroupsOnSso` re-maps groups on every login

**Session Continuity with Relay State**
- OAuth `state` parameter preserves target URL along with session ID, app name, login type, CSRF token, and provider ID
- Shopping cart preserved across IdP redirect
- Checkout flow uninterrupted by SSO
- Relay state host is validated against store URL (SEC-09) to prevent open redirect attacks

**CAPTCHA Bypass (Customer)**
- `OidcCustomerCaptchaBypassPlugin` intercepts `CheckUserLoginObserver` on the frontend area
- Skips CAPTCHA for customers authenticating via OIDC (IdP already authenticated them)
- Mirrors the admin-side `OidcCaptchaBypassPlugin`

### Admin Flow Use Cases

**Zero-Touch Admin Provisioning**
- First-time admin login creates Magento admin account automatically
- Enabled via `autoCreateAdmin` setting (global) or per-provider `m2oidc_auto_create_admin` column
- Email from IdP matched against `admin_user` table
- If no match and auto-create enabled, `AdminUserCreator::createAdminUser()` invoked

**Role Hierarchy Enforcement**
- OIDC groups mapped to Magento admin roles via the normalized `m2oidc_oauth_role_mappings` table (`mapping_type = 'admin_role'`)
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
- Configured via `access_control_rules` column in `m2oidc_oauth_client_apps`
- Rules run before JIT provisioning — a blocked user is never created

**CSRF Protection**
- A CSRF token is generated in `SendAuthorizationRequest`, stored in the PHP session, and embedded in the OAuth `state` parameter
- `ReadAuthorizationResponse` extracts the token from state and validates it against the session before processing any authorization code
- The `state` parameter also includes session ID, app name, login type, and provider ID (JSON+Base64 encoded)

**Rate Limiting**
- `OidcRateLimiter` enforces per-IP rate limiting on `ReadAuthorizationResponse`
- Exceeding `MAX_ATTEMPTS` within `WINDOW_SECONDS` results in a blocked response
- Prevents attackers from replaying or enumerating authorization codes

**Lockout-Prevention Guard**
- `Controller/Adminhtml/Provider/Save.php` checks `m2oidc_oauth_user_provider` before saving
- If "disable non-OIDC admin login" is requested but no admin has ever authenticated via OIDC for this provider, the setting is auto-reset to `0` and a warning is displayed
- Same guard applies to customer login restriction (`m2oidc_disable_non_oidc_customer_login`)
- `Block/Adminhtml/Provider/Edit/Tab/LoginOptions.php` surfaces warnings in the Login Options tab via `hasOidcAdminUsers()` / `hasOidcCustomerUsers()` — auto-redirect setting also guarded when multiple providers exist

**Required Field Validation**
- On provider save, `Controller/Adminhtml/Provider/Save.php` validates that `email_attribute`, `username_attribute`, `firstname_attribute`, and `lastname_attribute` are all non-empty
- Missing fields cause a validation error and redirect back to the edit form — the provider is not saved
- UI marks these fields as required in the provider edit form

**Address Integrity Guard**
- `Model/Service/CustomerUserCreator.php` only creates a billing address object if all four required fields are mapped and non-empty: street, ZIP code, city, and country
- Partial address mappings are skipped entirely to avoid creating malformed address records

**Centralized Access Revocation**
- Disable user at IdP → immediate effect across all integrated systems including Magento
- No need to disable accounts individually in each system

**MFA Enforcement at IdP Level**
- Magento inherits IdP's MFA settings (TOTP, SMS, push notifications, biometric)
- No Magento-specific MFA plugins required

**Session Security**
- Nonce cookies (`oidc_admin_nonce`, `oidc_customer_nonce`) are one-time use, 120s TTL, HttpOnly, Secure, SameSite=Lax
- Nonces are created and validated by `OAuthSecurityHelper` via Magento's cache layer
- Cross-origin session handling: `SameSite=None; Secure; HttpOnly` applied to session cookies during OIDC routes only
- `SessionCookieObserver` applies only to `/m2oidc/` routes — does not affect other cookies globally
- HTTPS required (SameSite=None only works with Secure flag)

**JWT Verification**
- Validates JWT tokens using RS256/384/512 signatures
- JWKS endpoint fetching with 24-hour cache (SHA-256 keyed); auto-refresh on signature mismatch for key rotation support
- Key ID (kid) matching, token expiration, issuer, and audience validation
- Prevents token forgery and replay attacks

**PKCE (RFC 7636)**
- Code verifier generated in `SendAuthorizationRequest`, stored in the PHP session keyed by provider ID
- Code challenge sent to IdP as S256 (SHA-256 hash) or plain
- Code verifier retrieved and cleared (one-time use) in `ReadAuthorizationResponse`
- Session-based storage eliminates the concurrent-login race condition that existed when the verifier was stored in the database row

**RP-Initiated Logout with Token Revocation**
- On admin logout, `OidcLogoutPlugin` uses `aroundLogout` (not `afterLogout`) to read `oidc_id_token` and `oidc_access_token` from the backend session before `Auth::logout()` destroys it
- If `revocation_endpoint` is configured, the access token is revoked via RFC 7009 (fire-and-forget; failure is non-fatal)
- The plugin then redirects to the IdP's `end_session_endpoint` in one of two modes:
  - **Standard OIDC**: sends `id_token_hint`, a random `state`, and `post_logout_redirect_uri`
  - **Authelia Forward-Auth**: detects path ending with `/logout` (without `/oauth2/` or `/oidc/`) and sends `rd` parameter instead
- A short-lived `oidc_logout_guard` cookie (120s) prevents `AdminLoginRestrictionPlugin` from triggering an immediate re-login loop after redirect

**Worker State Isolation (SEC-06)**
- `OidcCredentialPlugin::beforeLogin()` unconditionally resets all internal flags at the start of every login attempt
- Prevents PHP-FPM worker process recycling from leaking OIDC state between requests

### Anti-Patterns / Not Suitable For

**IdP-Initiated SSO**
- Module only supports SP-initiated flow (user starts at Magento, redirects to IdP)
- IdP-initiated flow (user starts at IdP, receives SAML-style POST assertion) not implemented
- Workaround: IdP can deep-link to Magento SSO URL, which is technically still SP-initiated

**Federated Logout** *(substantially implemented)*
- **Admin RP-Initiated Logout**: `OidcLogoutPlugin` redirects to `endsession_endpoint` with `id_token_hint`; revokes access token via RFC 7009; supports Authelia Forward-Auth (`?rd=`) mode
- **Customer RP-Initiated Logout**: `OAuthLogoutObserver` handles `customer_logout` event; reads id_token, revokes access token, redirects to IdP
- **Back-Channel Logout** (FEAT-02): `POST /m2oidc/actions/backchannel-logout` validates a signed JWT logout token (JWKS) and destroys the matching PHP session via `OidcSessionRegistry`
- **Logout guard**: `oidc_logout_guard` cookie (120s) prevents auto-redirect loops after IdP logout on both admin and customer areas

**Complex Claim Transformations**
- No built-in conditional claim mapping (e.g., "if group = X, then map attribute Y differently")
- Attribute mappings are 1:1 only: OIDC claim → Magento field
- Workaround: configure claim transformations at IdP level before sending to Magento
- Custom logic requires a plugin on `CheckAttributeMappingAction::execute()`

---

## 4. API Reference

### `\M2Oidc\OAuth\Helper\Data`

The base data-access helper. Injected as a dependency everywhere configuration values are needed.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the frontend SSO login URL. Appends `relayState` and `app_name` as query params. Defaults `relayState` to the current URL and `app_name` to the stored config value. |
| `getAdminSPInitiatedUrl` | `($relayState = null, $app_name = null)` | `string` | Builds the admin backend SSO login URL. Uses the admin URL builder so the request routes through the admin `SendAuthorizationRequest` controller (which stamps `loginType=admin` into the state). |
| `getSPInitiatedUrlForProvider` | `(int $providerId, ?string $relayState = null, string $loginType = 'customer')` | `string` | Builds the SSO URL for a specific provider ID. Preferred over `getSPInitiatedUrl()` in multi-provider setups. |
| `getCallBackUrl` | `()` | `string` | Returns `{baseUrl}m2oidc/actions/ReadAuthorizationResponse`. Register this in your IDP. |
| `getBaseUrl` | `()` | `string` | Returns Magento's `UrlInterface::getBaseUrl()`. |
| `getAdminBaseUrl` | `()` | `string` | Returns the admin home page URL. |
| `getStoreConfig` | `($config)` | `mixed` | Reads from `m2oidc/oauth/{$config}` in `core_config_data`. In `OAuthUtility` this is overridden to resolve provider-specific keys from `m2oidc_oauth_client_apps` when an active provider ID is set. |
| `setStoreConfig` | `($config, $value, $skipSanitize = false)` | `void` | Writes to `m2oidc/oauth/{$config}`. Also syncs `show_admin_link` / `show_customer_link` to the `m2oidc_oauth_client_apps` table. |
| `setOAuthClientApps` | `($app_name, $client_id, ...)` | `void` | Inserts a new row into the `m2oidc_oauth_client_apps` table with the provider's configuration. |
| `getOAuthClientApps` | `()` | `Collection` | Returns the full collection from `m2oidc_oauth_client_apps`. |
| `getAllActiveProviders` | `(string $loginType = 'customer')` | `array` | Returns all active provider rows for the given login type (`'customer'`, `'admin'`, or `'both'`). Used for multi-provider button rendering and login restriction checks. |
| `saveConfig` | `($url, $value, $id, $admin)` | `void` | Updates an attribute on either an admin user or a customer, depending on `$admin` (bool). |
| `sanitize` | `($value)` | `mixed` | Recursive `htmlspecialchars(strip_tags(trim(...)))`. Applied to all config writes by default. |

### `\M2Oidc\OAuth\Helper\OAuthUtility`

Extends `Data`. This is the class most controllers and plugins inject.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `isOAuthConfigured` | `()` | `bool` | `true` if `authorizeURL` is set in config. |
| `isUserLoggedIn` | `()` | `bool` | Checks both customer session and admin auth session. |
| `getCurrentUser` | `()` | `Customer` | Returns the logged-in customer from the customer session. |
| `getCurrentAdminUser` | `()` | `User` | Returns the logged-in admin from the auth session. |
| `setActiveProviderId` | `(int $providerId)` | `void` | Sets the active provider ID for this request. Causes `getStoreConfig()` to resolve values from the matching `m2oidc_oauth_client_apps` row instead of `core_config_data`. |
| `getActiveProviderId` | `()` | `?int` | Returns the currently active provider ID, or null if not set. |
| `customlog` | `($txt)` | `void` | Writes a plain-text line to `var/log/M2Oidc.log` if debug logging is enabled. |
| `customlogContext` | `(string $event, array $context = [])` | `void` | Writes a JSON-structured log entry: `{"ts":"...","level":"debug","message":"$event",...context}`. Automatically masks sensitive keys (`client_secret`, `access_token`, `id_token`, `refresh_token`, `password`, `token`). |
| `isLogEnable` | `()` | `bool` | Checks both the legacy and new debug-log config path. |
| `getAdminSession` | `()` | `Session` | Returns the backend session. |
| `setSessionData` / `getSessionData` | `($key, $value)` / `($key, $remove = false)` | `mixed` | Customer session read/write. |
| `setAdminSessionData` / `getAdminSessionData` | `($key, $value)` / `($key, $remove = false)` | `mixed` | Admin session read/write. |
| `getLogoutUrl` | `()` | `string` | Returns the appropriate logout URL based on who is logged in (customer or admin). |
| `getClientDetails` | `()` | `array` | Returns a flat array of the active app's client config (clientID, secret, endpoints, etc.). |
| `extractNameFromEmail` | `(string $email)` | `array` | Splits email into `['first' => 'prefix', 'last' => 'domain']`. Used as a name fallback when OIDC claims don't contain first/last name. |

### `\M2Oidc\OAuth\Helper\OAuthSecurityHelper`

Handles nonce creation and validation for both admin and customer login flows. Nonces are stored in Magento's cache layer (not the database), making them short-lived and atomic.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createAdminLoginNonce` | `(string $email, string $relayState)` | `string` | Generates a cryptographically random nonce, stores it in cache with a 120-second TTL, and returns the nonce value. The cache entry encodes the target email and relay state. |
| `redeemAdminLoginNonce` | `(string $nonce)` | `array\|null` | Looks up the nonce in cache. If found, deletes it immediately (one-time use) and returns the stored payload `['email', 'relay_state']`. Returns `null` if expired or not found. |
| `createCustomerLoginNonce` | `(string $email, string $relayState)` | `string` | Same as admin variant but for customer login flows. |
| `redeemCustomerLoginNonce` | `(string $nonce)` | `array\|null` | Same as admin variant but for customer login flows. |

### `\M2Oidc\OAuth\Model\Service\AdminUserCreator`

Service class for admin JIT provisioning.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createAdminUser` | `($email, $userName, $firstName, $lastName, array $userGroups, int $providerId = 0)` | `User\|null` | Creates an admin user with a random password, assigns a role based on group mapping. Returns `null` if no suitable role exists (group mapping fails and no default role configured). |
| `isAdminUser` | `(string $email)` | `bool` | Checks `admin_user` table by both username and email. |

**Role assignment fallback chain**: configured group mapping (case-insensitive) → `defaultRole` config → `null` (deny, user not created).

### `\M2Oidc\OAuth\Model\Service\CustomerUserCreator`

Service class for customer JIT provisioning.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createCustomer` | `($email, $userName, $firstName, $lastName, $flattenedAttrs, $rawAttrs, $providerId = 0)` | `Customer\|null` | Creates a customer with a random password. Maps DOB, gender, phone, and address fields from OIDC claims. Creates a default billing/shipping address if address data is present. |
| `updateCustomerGroupFromOidc` | `(CustomerInterface $customer, array $flattenedAttrs, array $rawAttrs)` | `bool` | Re-evaluates customer group from current OIDC claims and updates if changed. Called on subsequent logins when `sync_customer_group_on_sso` is enabled. |

### `\M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter`

Implements `Magento\Backend\Model\Auth\Credential\StorageInterface`. This is the bridge between OIDC and Magento's native admin auth.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `authenticate` | `($username, $password)` | `bool` | Verifies the OIDC token marker (`OIDC_VERIFIED_USER`), loads the admin user by email, checks active status and role assignment. Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth => true`. |
| `login` | `($username, $password)` | `$this` | Calls `authenticate()`, then records the login and reloads the user. |
| `reload` | `()` | `$this` | Reloads the user model from the database. |

### `\M2Oidc\OAuth\Helper\SessionHelper`

Handles cross-origin session cookie compatibility for OIDC redirects.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `configureSSOSession` | `()` | `void` | Delegates to `updateSessionCookies()`. Available but no longer called from `SendAuthorizationRequest` (see Gotcha #19). |
| `updateSessionCookies` | `()` | `void` | Re-sets session cookies with `SameSite=None; Secure; HttpOnly` via Magento's `CookieManager`. |
| `forceSameSiteNone` | `()` | `void` | Rewrites the PHP session cookie in the response to enforce `SameSite=None`. Called by `SessionCookieObserver` only for `/m2oidc/` routes. |

### CLI Commands

| Command | Description |
|---|---|
| `bin/magento oauth:config:export` | Exports all provider configuration to a JSON file. Use to migrate settings across environments. |
| `bin/magento oauth:config:import` | Imports provider configuration from a JSON file exported by `oauth:config:export`. |

---

## 5. Common Patterns

### Pattern 1: Add a "Login with SSO" button to your theme

```php
<?php
// In your .phtml template — inject \M2Oidc\OAuth\Helper\Data as $oauthHelper
$loginUrl = $oauthHelper->getSPInitiatedUrl();
?>
<a href="<?= $loginUrl ?>" class="btn-sso">Login with SSO</a>
```

For admin login, use `getAdminSPInitiatedUrl()` instead. The admin variant stamps `loginType=admin` into the OAuth state so the callback routes the user through the admin auth flow.

For multi-provider setups, iterate over `getAllActiveProviders()` and use `getSPInitiatedUrlForProvider($provider['id'])` to generate one button per provider.

### Pattern 2: Check if the current user logged in via OIDC

```php
// Inject \M2Oidc\OAuth\Helper\OAuthUtility as $oauthUtility

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

For customer-specific OIDC detection, check the `oidc_customer_authenticated` cookie:

```php
$isOidcCustomer = ($cookieManager->getCookie('oidc_customer_authenticated') === '1');
```

### Pattern 3: Extend attribute mapping

The module maps standard OIDC claims via config. For custom logic, create a plugin on `CheckAttributeMappingAction::execute()` or observe the process via events.

Key files to understand:
- `Controller/Actions/CheckAttributeMappingAction.php` — routes admin vs. customer, evaluates access control rules, handles attribute extraction
- `Controller/Actions/ProcessUserAction.php` — creates/updates customers based on mapped attributes
- `Model/Service/CustomerUserCreator.php` — the actual customer creation logic (DOB, gender, address, etc.)
- `Model/Service/AdminUserCreator.php` — admin creation with group-to-role mapping

Attribute mappings per provider are stored in both the `m2oidc_oauth_client_apps` row (legacy) and the normalized `m2oidc_oauth_attribute_mappings` table. Per-provider values always take priority over global `core_config_data`.

### Pattern 4: Configure claims-based access control

Add an `access_control_rules` JSON array to the provider's row in `m2oidc_oauth_client_apps`:

```json
[
  {"claim": "email_verified", "operator": "eq", "value": "true"},
  {"claim": "groups", "operator": "contains", "value": "magento-users"}
]
```

All rules must pass. A failed rule blocks login and JIT provisioning entirely — the user is never created. The `groups` claim (if it's an array) is joined with commas before string comparison.

Supported operators: `eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`.

### Pattern 5: Auto-populate endpoints via OIDC discovery

When adding a new provider, enter only the `well_known_config_url` (e.g., `https://auth.example.com/.well-known/openid-configuration`) and save. `Controller/Adminhtml/Provider/Save.php` fetches the discovery document and populates:

- `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint`
- `jwks_endpoint`, `endsession_endpoint`, `revocation_endpoint`, `issuer`

If auto-discovery fails (unreachable URL, malformed JSON), a warning is shown and the manual fields are left as-is. The `well_known_config_url` is cleared on save if the fetch fails so the next save attempt re-triggers discovery.

### Pattern 7: Add a security bypass for OIDC users in a third-party module

Follow the pattern from `Plugin/Captcha/OidcCaptchaBypassPlugin.php`. Check the `oidc_auth` event marker (on auth events) or the `oidc_authenticated` cookie (for ongoing admin requests):

```php
// In an around plugin on some auth observer:
public function aroundExecute($subject, callable $proceed, \Magento\Framework\Event\Observer $observer)
{
    $loginData = $observer->getEvent()->getData();
    if (!empty($loginData['oidc_auth'])) {
        return; // Skip for OIDC users
    }
    return $proceed($observer);
}
```

---

## 6. Gotchas

### 1. Admin and customer flows are separate entry points

Admin login starts from `Controller/Adminhtml/Actions/SendAuthorizationRequest.php` which stamps `loginType=admin` into the OAuth state. Customer login starts from `Controller/Actions/SendAuthorizationRequest.php` which stamps `loginType=customer`. **Both use the same callback URL** — the `loginType` in the state determines routing.

If you trigger the customer SSO URL but the user is an admin, they'll be logged in as a customer (or rejected if they don't have a customer account). Always use `getAdminSPInitiatedUrl()` for admin-intent logins.

### 2. The IDP email MUST match

For admin login, the email from the OIDC provider is matched against the `email` column in `admin_user`. If there's no match and auto-create is disabled, the login fails with `ADMIN_ACCOUNT_NOT_FOUND`. Check `var/log/M2Oidc.log` — every decision is logged there when debug logging is enabled.

### 3. Mixed content / callback URL protocol

The callback URL is built from `storeManager->getBaseUrl()`. If your load balancer terminates SSL but Magento sees HTTP internally, the callback URL will be `http://...` and most IDPs will reject it. Fix: configure `web/secure/base_url` correctly and handle `X-Forwarded-Proto` in your web server config.

### 4. Attribute keys are case-sensitive

Authelia sends `preferred_username`, `email`, `name`, `groups`. Other providers might send `Email`, `firstName`, etc. If mapping fails silently, check the exact key names via **Test Configuration** and verify they match your Attribute Mapping settings.

### 5. Admin role mapping fallback does NOT include a role-name fallback

For security, if no group-to-role mapping matches and no default role is configured, admin auto-creation is **denied** (`AdminUserCreator::getAdminRoleFromGroups()` returns `null`). The fallback chain is strictly: group mapping → `defaultRole` config → deny. There is no implicit fallback to an "Administrators" role or role ID 1. Configure your role mappings explicitly.

### 6. OIDC-authenticated admins bypass password re-verification

The `OidcIdentityVerificationPlugin` skips the "enter your current password" prompt for admin users with the `oidc_authenticated` cookie. This cookie is set on login and cleared on logout. It's scoped to the admin path and lasts for the admin session lifetime.

### 7. `SameSite=None` cookies are scoped to OIDC routes only

The `SessionCookieObserver` (event: `controller_front_send_response_before`) checks whether the request URI contains `/m2oidc/` before rewriting session cookies. Non-OIDC requests are unaffected. If you're debugging session cookie issues on non-OIDC pages, this observer is not the cause.

### 8. Debug logs auto-expire after 7 days

When debug logging is enabled, the `SendAuthorizationRequest` controller checks if the log file is older than 7 days. If so, it disables logging and deletes the log file. You'll need to re-enable it in the admin panel.

### 9. The OAuth `state` parameter encodes multiple values

The state is formatted as a JSON+Base64 structure containing relay state, session ID, app name, login type, CSRF token, and provider ID. A legacy pipe-delimited format is also supported for backward compatibility. If you're debugging state issues, decode it as Base64 JSON first, fall back to pipe-splitting.

### 10. Non-OIDC admin login can be disabled (with a safety net)

When `m2oidc_disable_non_oidc_admin_login` is enabled on a provider, the `AdminLoginRestrictionPlugin` throws an `AuthenticationException` for any password-based admin login — but only if the OIDC button (`show_admin_link`) is visible on that provider. If the button is hidden, password login is allowed regardless, preventing a total lockout.

### 11. Nonce cookies are one-time use with a 120-second TTL

Both `oidc_admin_nonce` and `oidc_customer_nonce` are deleted immediately upon use (via cache delete in `OAuthSecurityHelper`). If a browser is slow, has cookies blocked, or if the user navigates away and back, the nonce will be missing or expired and the callback will redirect to the login page with an error. Ensure the browser has cookies enabled and the session path is not overly restrictive.

### 12. PKCE verifier race condition — FIXED in current version

Previously, the PKCE code verifier was stored in the `m2oidc_oauth_client_apps` database row, meaning two concurrent logins for the same provider would overwrite each other's verifier. This is fixed: the verifier is now stored in the PHP session, keyed by provider ID (`PKCE_VERIFIER_SESSION_KEY + $providerId`). Concurrent logins for the same provider are safe.

### 13. `ProcessResponseAction` is a deprecated shim — do not add logic there

The file `Controller/Actions/ProcessResponseAction.php` exists for backward compatibility. All attribute mapping and routing logic was moved to `CheckAttributeMappingAction`. If you need to add custom processing, create a plugin on `CheckAttributeMappingAction::execute()` instead.

### 14. Do not remove the flag reset in `OidcCredentialPlugin::beforeLogin()`

`OidcCredentialPlugin` unconditionally resets `$isOidcAuth = false` and `$adapterLogged = false` at the very start of every `beforeLogin()` call, even before checking whether it's an OIDC login. This is intentional — it guards against PHP-FPM worker processes recycling request state between HTTP requests. Removing it would cause sporadic OIDC adapter injection on non-OIDC logins.

### 15. Access control rules block JIT provisioning entirely

Access control rules are evaluated in `CheckAttributeMappingAction` before any user lookup or creation. If a rule denies access, the user is never created — even if auto-create is enabled and this is the user's first login. This is intentional security-by-default behavior: configure rules carefully.

### 16. `OidcLogoutPlugin` must use `aroundLogout`, not `afterLogout`

`Auth::logout()` destroys the admin session. If you use `afterLogout`, session data (`oidc_id_token`, `oidc_provider_id`) is already gone. The plugin reads those values before calling `$proceed()` (the original logout) for exactly this reason. If you add your own logout plugin and need OIDC session data, ensure your plugin has a lower sort order than `OidcLogoutPlugin` (sort order 20) or also uses `aroundLogout`.

### 17. Two logout URL formats — selection is heuristic-based

`OidcLogoutPlugin` detects whether to use Authelia Forward-Auth logout or standard OIDC RP-Initiated Logout based on the path of `end_session_endpoint`:
- Path ends with `/logout` AND does not contain `/oauth2/` or `/oidc/` → **Authelia mode**: appends `?rd=<admin_base_url>`
- Anything else → **Standard OIDC**: appends `id_token_hint`, `state`, and `post_logout_redirect_uri`

If your IdP's logout path happens to match the Authelia heuristic but expects standard OIDC parameters, the redirect will be malformed. Verify by checking the debug log for `mode=forward-auth(rd)` vs `mode=oidc-rp-logout`.

### 18. Multi-website customer login uses website context validation (SEC-08)

`CustomerOidcCallback` checks that the authenticated customer belongs to the current Magento website. A customer created on website A cannot log in via OIDC on website B. This prevents cross-site session injection in multi-website setups. If a customer reports SSO working in one store but not another, verify their customer account's website assignment in **Customers > All Customers**.

### 19. Lockout-prevention guard silently reverts invalid settings

If you enable "Disable non-OIDC admin login" on a provider where no admin has yet authenticated via OIDC, the `Provider/Save.php` controller automatically resets the setting to `0` before saving. A warning message is displayed but no exception is thrown. The same applies to the customer restriction. Check the save response for warnings if the setting doesn't appear to stick.

### 20. Billing address requires all four fields

`CustomerUserCreator` only creates a billing address when all four required fields are mapped AND return non-empty values from the OIDC token: `billing_address_attribute` (street), `billing_zip_attribute`, `billing_city_attribute`, `billing_country_attribute`. If any of these is blank (either not mapped or the claim is missing), the entire address object is skipped. This is intentional — a partial address would fail Magento's address validation at checkout.

### 21. Required attribute fields block provider save

Provider save validates that `email_attribute`, `username_attribute`, `firstname_attribute`, and `lastname_attribute` are all non-empty strings. A save attempt with any missing field returns an error and redirects back to the edit form without persisting any changes. This prevents a provider configuration that would silently fail during the OIDC login flow.

### 22. Auto-discovery overwrites manually entered endpoints

When a `well_known_config_url` is configured, every provider save fetches the discovery document and overwrites all endpoint fields (authorize, token, userinfo, JWKS, logout, revocation, issuer) with the discovered values. If you need to override a specific endpoint (e.g., a custom revocation URL), remove or clear the `well_known_config_url` first to prevent it from being overwritten on the next save.

### 23. `configureSSOSession()` is no longer called from `SendAuthorizationRequest`

An earlier version called `SessionHelper::configureSSOSession()` at the start of the authorization request to set `SameSite=None` on session cookies. This was removed because it conflicted with `session_regenerate_id()` in the callback handlers, causing session data loss. `SameSite=None` is now applied exclusively at response time via `SessionCookieObserver` for `/m2oidc/` routes. Do not re-add a `configureSSOSession()` call to `SendAuthorizationRequest`.

---

## 7. Related Modules

### Magento Core Dependencies

| Module | Usage |
|---|---|
| `Magento_Customer` | `CustomerFactory`, `Session`, `CustomerRepositoryInterface` — customer creation and session management |
| `Magento_User` | `UserFactory`, `User` model — admin user CRUD and lookup |
| `Magento_Backend` | `Auth`, `Auth\Session`, `UrlInterface` — admin authentication, session, and URL generation |
| `Magento_Authorization` | `Role\Collection` — querying available admin roles for group mapping |
| `Magento_Captcha` | `CheckUserLoginBackendObserver` (admin), `CheckUserLoginObserver` (frontend) — intercepted by captcha bypass plugins to skip CAPTCHA for OIDC |
| `Magento_Directory` | `CountryFactory`, `DirectoryData` — country resolution for customer address mapping |
| `Magento_Framework` | `CookieManager`, `CookieMetadataFactory`, `Random`, `Curl`, `Event\Manager`, `Cache\Frontend\Pool` |

### Plugin Interceptions (defined in `etc/di.xml`)

| Target | Plugin | Sort Order | Area | Purpose |
|---|---|---|---|---|
| `Magento\Backend\Model\Auth` | `AdminLoginRestrictionPlugin` | 5 | adminhtml | Blocks non-OIDC admin login when restriction is enabled; allows through if OIDC button is hidden (safety net) |
| `Magento\Backend\Model\Auth` | `OidcCredentialPlugin` | 10 | adminhtml | Injects `OidcCredentialAdapter` during OIDC login; resets guard flags on every `beforeLogin()` |
| `Magento\Backend\Model\Auth` | `OidcLogoutPlugin` | 20 | adminhtml | Reads OIDC session data before session destroy, revokes access token (RFC 7009), redirects to IdP logout |
| `Magento\Captcha\Observer\CheckUserLoginBackendObserver` | `OidcCaptchaBypassPlugin` | 10 | adminhtml | Skips CAPTCHA for OIDC-authenticated admin logins |
| `Magento\Captcha\Observer\CheckUserLoginObserver` | `OidcCustomerCaptchaBypassPlugin` | 10 | frontend | Skips CAPTCHA for OIDC-authenticated customer logins |
| `Magento\User\Model\User` | `OidcIdentityVerificationPlugin` | 10 | adminhtml | Bypasses password re-verification for OIDC admins |
| `Magento\User\Model\User` | `OidcPasswordExpirationPlugin` | 10 | adminhtml | Suppresses password expiration warnings for OIDC admins |
| `Magento\User\Model\User` | `OidcForcePasswordChangePlugin` | 10 | adminhtml | Suppresses forced password change redirects for OIDC admins |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Removes "required" from password field in user edit form |
| `Magento\User\Block\Role\Tab\Info` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Same for role edit form |
| `Magento\Backend\Block\System\Account\Edit\Form` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Same for account settings form |

### Events Observed

| Event | Observer | Area |
|---|---|---|
| `controller_front_send_response_before` | `SessionCookieObserver` | frontend (scoped to `/m2oidc/` routes only) |
| `customer_login` or auto-redirect event | `CustomerLoginAutoRedirectObserver` | frontend |
| `customer_logout` | `OAuthLogoutObserver` | frontend (customer RP-Initiated Logout) |
| `customer_delete` | `CustomerDeleteObserver` | frontend (removes `m2oidc_oauth_user_provider` row) |
| `admin_user_delete_before` | `AdminUserDeleteObserver` | adminhtml (removes `m2oidc_oauth_user_provider` row) |
| Admin logout event | `OAuthLogoutObserver` | adminhtml |

---

## 8. Structure

```
M2Oidc_OAuth/
├── registration.php                          # Registers module with Magento
├── composer.json                             # Package metadata (v4.2.0)
├── etc/
│   ├── module.xml                            # Module declaration
│   ├── di.xml                                # DI config: plugins, constructor args
│   ├── db_schema.xml                         # DB tables (4 tables — see below)
│   ├── acl.xml                               # ACL resources for admin pages
│   ├── events.xml                            # Global event observers
│   ├── csp_whitelist.xml                     # Content Security Policy whitelist
│   ├── frontend/
│   │   ├── routes.xml                        # Frontend route: m2oidc
│   │   └── events.xml                        # Frontend event observers
│   └── adminhtml/
│       ├── routes.xml                        # Admin route: m2oidc
│       ├── events.xml                        # Admin event observers
│       ├── menu.xml                          # Admin menu entries
│       └── csp_whitelist.xml                 # Admin CSP whitelist
│
├── Console/
│   ├── ExportOidcConfig.php                  # CLI: bin/magento oauth:config:export
│   └── ImportOidcConfig.php                  # CLI: bin/magento oauth:config:import
│
├── Controller/
│   ├── Health/                               # Health check endpoint (/m2oidc/health/check)
│   ├── Actions/                              # Frontend controllers (OAuth flow)
│   │   ├── BaseAction.php                    # Base class for frontend actions
│   │   ├── BaseAdminAction.php               # Base class for admin config pages
│   │   ├── SendAuthorizationRequest.php      # Step 1: Redirect to IDP (customer); generates PKCE verifier (session-based)
│   │   ├── ReadAuthorizationResponse.php     # Step 2: Receive auth code, exchange for tokens, verify JWT; rate-limited
│   │   ├── ProcessResponseAction.php         # [DEPRECATED SHIM] Superseded by CheckAttributeMappingAction
│   │   ├── CheckAttributeMappingAction.php   # Step 3: Evaluate access rules, route admin vs customer, map attributes
│   │   ├── ProcessUserAction.php             # Step 4: Create/match customer, validate relay state, delegate login
│   │   ├── CustomerLoginAction.php           # Step 5: Create customer nonce cookie, redirect to CustomerOidcCallback
│   │   ├── CustomerOidcCallback.php          # Step 6: Redeem nonce, validate website, set customer session, redirect
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
│   ├── Attribute/                            # Normalized attribute mapping models
│   ├── Provider/                             # Provider config abstraction layer
│   ├── Security/                             # Rate limiter and related security models
│   ├── Service/
│   │   ├── AdminUserCreator.php              # JIT admin provisioning + group-to-role mapping
│   │   ├── CustomerUserCreator.php           # JIT customer provisioning + address/group creation
│   │   ├── OidcAuthenticationService.php     # Validates extracted user info post-token exchange
│   │   └── UserProvisioningService.php       # Orchestrates admin/customer provisioning
│   ├── Resolver/                             # GraphQL resolvers (if GraphQL module present)
│   ├── M2oidcOauthClientApps.php             # Model for oauth_client_apps table
│   └── ResourceModel/
│       └── M2OidcOauthClientApps/
│           ├── Collection.php                # Collection model
│           └── (ResourceModel).php           # Resource model
│
├── Plugin/
│   ├── AdminLoginRestrictionPlugin.php       # Blocks non-OIDC admin login (with safety net)
│   ├── Auth/
│   │   ├── OidcCredentialPlugin.php          # Injects OIDC adapter; resets guard flags on every login
│   │   └── OidcLogoutPlugin.php              # Token revocation + RP-Initiated Logout (aroundLogout)
│   ├── Captcha/
│   │   ├── OidcCaptchaBypassPlugin.php       # Skips admin CAPTCHA for OIDC logins
│   │   └── OidcCustomerCaptchaBypassPlugin.php # Skips customer CAPTCHA for OIDC logins
│   └── User/
│       ├── OidcIdentityVerificationPlugin.php  # Bypasses password re-verification
│       ├── OidcPasswordExpirationPlugin.php    # Suppresses password expiry warnings
│       ├── OidcForcePasswordChangePlugin.php   # Suppresses forced password change
│       └── Block/
│           └── OidcIdentityFieldPlugin.php     # Removes required from password field in forms
│
├── Helper/
│   ├── Data.php                              # Base config data access + provider management
│   ├── OAuthUtility.php                      # Extended utility (sessions, cache, logging, provider resolution)
│   ├── OAuthConstants.php                    # All constants (config keys, defaults, URLs)
│   ├── OAuthMessages.php                     # User-facing message templates
│   ├── OAuthSecurityHelper.php               # Nonce create/validate via cache (admin + customer)
│   ├── SessionHelper.php                     # SameSite=None cookie handling (OIDC routes only)
│   ├── Curl.php                              # HTTP client for token/userinfo requests
│   ├── JwtVerifier.php                       # JWT signature validation using JWKS (pure PHP, cached)
│   ├── TestResults.php                       # Test configuration HTML output
│   └── OAuth/
│       ├── AuthorizationRequest.php          # Builds the authorize URL query string (includes PKCE challenge)
│       ├── AccessTokenRequest.php            # Builds the token exchange POST body
│       └── AccessTokenRequestBody.php        # Alternate token body (header auth variant)
│
├── Service/                                  # Additional service layer classes
├── UI/
│   ├── Component/DataProvider.php            # Provider grid data provider
│   ├── Component/DataProvider/SessionDataProvider.php  # Active sessions grid data provider
│   ├── Component/Listing/Column/Actions.php  # Provider grid row actions
│   ├── Component/Listing/Column/ActiveUserCount.php    # "total (active)" user count per provider
│   ├── Component/Listing/Column/OnlineStatus.php       # Shows providers with active sessions
│   ├── Component/Listing/Column/PkceStatus.php         # PKCE configuration status badge
│   ├── Component/Listing/Column/JwksStatus.php         # JWKS endpoint status badge
│   └── Component/Listing/Column/TestStatusOptions.php  # Test result status badge
├── ViewModel/                                # View models for admin templates
│
├── Block/
│   ├── OAuth.php                             # Admin template block (config getters)
│   └── Adminhtml/
│       ├── Debug.php                         # Debug info block
│       └── OidcErrorMessage.php              # OIDC error display block
│
├── Observer/
│   ├── SessionCookieObserver.php             # Forces SameSite=None on session cookies (/m2oidc/ routes only)
│   ├── OAuthObserver.php                     # OAuth event handler
│   ├── OAuthLogoutObserver.php               # Redirects to IDP logout URL (customer_logout event)
│   ├── CustomerLoginAutoRedirectObserver.php # Auto-redirects customers to OIDC login if configured
│   ├── AdminUserDeleteObserver.php           # Removes OIDC user mapping when admin user is deleted
│   └── CustomerDeleteObserver.php            # Removes OIDC user mapping when customer is deleted
│
├── Logger/
│   ├── Logger.php                            # Custom Monolog logger
│   └── Handler.php                           # Writes to var/log/M2Oidc.log
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
    │   ├── layout/                           # Admin layout XML files (m2oidc_* prefix)
    │   ├── templates/                        # Admin .phtml templates
    │   └── web/
    │       ├── css/
    │       │   ├── m2oidc.css                # Admin branding/logo styles
    │       │   └── adminSettings.css         # Dirty-field highlight styles (amber borders)
    │       ├── images/
    │       │   └── m2oidc_logo.png           # Module logo (admin menu + README)
    │       └── js/
    │           └── dirtyTracking.js          # Vanilla JS dirty-field tracking for provider edit form
    └── frontend/
        ├── layout/                           # Frontend layout XML files
        ├── templates/                        # Frontend .phtml templates (SSO buttons, popups)
        └── web/                              # Frontend CSS, images, JS templates
```

### Authentication Flow Diagram

```
CUSTOMER FLOW:
  Browser -> SendAuthorizationRequest (frontend, stamps loginType=customer, generates PKCE verifier in session)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback: validates CSRF+state, rate-limit check, exchanges code + PKCE verifier for tokens, verifies JWT)
    -> CheckAttributeMappingAction (evaluates access control rules, maps attributes, per-provider config override)
    -> ProcessUserAction (find/create customer, validate relay state)
    -> CustomerLoginAction (creates oidc_customer_nonce cookie via OAuthSecurityHelper, 120s TTL)
    -> CustomerOidcCallback (redeems nonce via OAuthSecurityHelper, validates website context SEC-08,
                             sets customer session, sets oidc_customer_authenticated cookie, redirects to relay state)

ADMIN FLOW:
  Browser -> SendAuthorizationRequest (adminhtml, stamps loginType=admin, generates PKCE verifier in session)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback, same as customer: CSRF validation, rate limiting, JWT verification)
    -> CheckAttributeMappingAction (evaluates access control rules, loginType=admin)
    -> [if user exists] -> creates oidc_admin_nonce cookie via OAuthSecurityHelper (120s TTL) -> redirect to Oidccallback
    -> [if auto-create] -> AdminUserCreator (group→role mapping) -> creates nonce -> redirect to Oidccallback
    -> Oidccallback -> redeems nonce via OAuthSecurityHelper -> Auth::login($email, 'OIDC_VERIFIED_USER')
       |-> OidcCredentialPlugin detects marker (after resetting guard flags) -> injects OidcCredentialAdapter
       |-> OidcCaptchaBypassPlugin skips CAPTCHA
       |-> OidcCredentialAdapter authenticates (no password check; fires auth events with oidc_auth=true)
       |-> All Magento security events fire normally
    -> Stores oidc_id_token + oidc_provider_id in auth session (used by OidcLogoutPlugin on logout)
    -> Sets oidc_authenticated cookie -> Admin dashboard

ADMIN LOGOUT FLOW:
  OidcLogoutPlugin::aroundLogout() (before session destroy):
    -> Reads oidc_id_token + oidc_access_token + oidc_provider_id from auth session
    -> $proceed() — destroys admin session
    -> Deletes oidc_authenticated cookie
    -> Sets oidc_logout_guard cookie (120s, prevents re-login loop)
    -> [if revocation_endpoint] -> RFC 7009 token revocation (fire-and-forget, non-fatal)
    -> Detects logout URL mode (Authelia forward-auth vs standard OIDC RP-Initiated)
    -> Redirects to IdP end_session_endpoint -> exit
```

### Database Tables

**`m2oidc_oauth_client_apps`** — stores the OIDC provider configuration. One row per configured provider.

Key columns:

| Column | Purpose |
|---|---|
| `id` | Primary key; used as `provider_id` throughout the module |
| `app_name` | Provider identifier (e.g., "authelia") |
| `clientID`, `client_secret` | OAuth credentials |
| `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint` | OIDC endpoints |
| `endsession_endpoint` | IdP logout URL for RP-Initiated Logout redirect |
| `revocation_endpoint` | RFC 7009 token revocation endpoint; if set, access token is revoked on logout |
| `jwks_endpoint` | JWKS URL for JWT signature verification |
| `issuer` | Expected OIDC issuer claim for JWT validation |
| `well_known_config_url` | OIDC discovery document URL |
| `scope` | OAuth scopes (e.g., "openid profile email groups") |
| `pkce_flow`, `pkce_code_verifier` | PKCE method ('S256'/'plain'). `pkce_code_verifier` column is legacy — verifier is now stored in session. |
| `email_attribute`, `username_attribute`, `firstname_attribute`, `lastname_attribute` | Attribute mapping overrides (legacy; normalized table takes priority if populated) |
| `group_attribute` | OIDC claim containing group memberships |
| `oauth_admin_role_mapping` | JSON: legacy group→role mappings (normalized table preferred) |
| `oauth_customer_group_mapping` | JSON: legacy group→group mappings (normalized table preferred) |
| `access_control_rules` | JSON: claims-based access control rules |
| `m2oidc_auto_create_customer`, `m2oidc_auto_create_admin` | Per-provider JIT provisioning toggles |
| `m2oidc_disable_non_oidc_admin_login`, `m2oidc_disable_non_oidc_customer_login` | Per-provider: block password-based login |
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

**`m2oidc_oauth_attribute_mappings`** — normalized attribute mapping. One row per attribute slot per provider. Takes priority over legacy JSON columns in `m2oidc_oauth_client_apps`.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `provider_id` | FK → `m2oidc_oauth_client_apps.id` |
| `attribute_type` | Attribute slot (e.g., `email`, `firstname`, `billing_city`) |
| `attribute_name` | OIDC claim key to read from the token response |
| `sync_on_sso` | If `1`, re-sync this attribute on every login (not just first login) |

---

**`m2oidc_oauth_role_mappings`** — normalized role/group mapping. One row per OIDC group per provider. Takes priority over legacy JSON columns.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `provider_id` | FK → `m2oidc_oauth_client_apps.id` |
| `mapping_type` | `'admin_role'` or `'customer_group'` |
| `oidc_group` | OIDC group value (case-insensitive match at runtime) |
| `magento_role_id` | Magento admin role ID or customer group ID |
| `sort_order` | Evaluation order (lower = checked first) |

---

**`m2oidc_oauth_user_provider`** — tracks which OIDC provider created each Magento user.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `user_type` | `'customer'` or `'admin'` |
| `user_id` | Magento `entity_id` (customer) or `user_id` (admin) |
| `provider_id` | References `m2oidc_oauth_client_apps.id` |
| `created_at` | Timestamp, auto-set on insert |

Unique constraint on `(user_type, user_id)` — each Magento user is linked to at most one provider. Used by the logout observer to retrieve the correct `endsession_endpoint` for the logged-in user's provider.

---

## 9. Future Improvements

This section documents remaining technical debt and potential enhancements. Items already implemented in the current version have been removed.

### Testing & Code Quality

**Extend Unit Test Coverage** *(partially done)*
- **Current state**: Core test coverage added in `Test/Unit/` and `Test/Integration/`. Covered (3,100+ test cases):
  - `OidcCredentialAdapterTest` (353), `OidcCredentialPluginTest`, `JwtVerifierTest` (521)
  - `AdminAttributeMapperTest` (157), `CustomerAttributeMapperTest` (252)
  - `AdminUserCreatorRoleMappingTest` (389), `CustomerUserCreatorAddressTest` (517)
  - Integration: `AdminOidcLoginFlowTest` (175), `CustomerOidcLoginFlowTest` (148), `SecurityPluginsTest` (168), `AccessControlRulesTest`
- **Remaining gap**: `BackChannelLogout`, `OidcLogoutPlugin`, `OAuthLogoutObserver`, `OidcRateLimiter`, `AdminUserDeleteObserver`, `CustomerDeleteObserver` have no dedicated tests
- **Recommendation**: Add tests for the logout flow, rate limiter, back-channel logout controller, and new delete observers

**Fix Unsafe ObjectManager Usage**
- **Location**: `Model/Auth/OidcCredentialAdapter.php` `__wakeup()` method
- **Issue**: Uses `ObjectManager::getInstance()` for post-deserialization dependency injection
- **Risk**: Tight coupling, hard to test, violates dependency injection principle
- **Fix**: Implement `Serializable` properly or use a factory pattern registered in DI

**Extend Static Analysis Compliance** *(partially done)*
- `phpstan.neon`, `phpstan.local.neon`, `phpstan-stubs.stub`, `psalm-stubs.stub` added
- **Current target**: PHPStan Level 4, Psalm Level 4
- **Long-term goal**: PHPStan Level 6, Psalm Level 3
- **Remaining**: Fix remaining type hint inconsistencies in `OidcCredentialAdapter`; ensure strict return types across new code

### Architecture & Scalability

**Attribute Mapping Strategy Pattern** *(implemented)*
- `AttributeMapperInterface` with `AdminAttributeMapper` and `CustomerAttributeMapper` introduced
- Accessed via `MappingRepository` backed by `m2oidc_oauth_attribute_mappings` table
- **Remaining**: DI-registered per-provider custom mapper implementations not yet exposed as a public extension point

**Event-Driven Hooks** *(partially implemented)*
- `UserProvisioningService` fires `oidc_before_user_create` and `oidc_after_user_create` for both admin and customer paths
- **Remaining**: Attribute-mapping hooks (`oidc_after_attribute_mapping`) not yet available

### Operational Improvements

**Token Refresh Handling**
- **Current state**: `TokenRefreshService` exists; refresh tokens stored in session, but automatic refresh on expiry is not yet wired in
- **Issue**: Long sessions may outlive access token expiration
- **Recommendation**: Check token expiration before upstream API calls and refresh silently
- **Requires**: Hook into a Magento event that fires before authenticated API calls

### Developer Experience

**Better Error Messages** *(partially done)*
- `Helper/OAuthMessages.php` centralizes user-facing messages
- **Remaining**: Error messages for attribute mapping mismatches and role-mapping failures are still generic in some paths
  - "OIDC provider returned email claim 'Email' but expected 'email' — check attribute mapping"
  - "Admin role mapping failed: no role found for group 'Engineers' — configure role mapping or set default role"
