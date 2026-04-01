# M2Oidc OAuth/OIDC SSO Module — Technical Documentation

> **Module**: `M2Oidc_OAuth` (`m2oidc_inc/m2oidc-oauth-sso` v4.2.0)
> **Requires**: PHP 8.1+, Magento 2.4.7+

---

## 1. Overview

This Magento 2 module adds **OAuth 2.0 / OpenID Connect** single sign-on for both **Customer** (frontend) and **Admin** (backend) users. It replaces Magento's native login flow with an external Identity Provider (Authelia, Keycloak, Auth0, Zitadel, etc.) while keeping all of Magento's built-in security events, ACL checks, and session handling intact.

### What it does

- Redirects users to an OIDC provider, receives an authorization code, exchanges it for tokens, and extracts user attributes.
- **SP-initiated flow**: user starts at Magento and is redirected to the IdP.
- **IdP-initiated flow** (OIDC Third-Party Initiated Login §4): user starts at the IdP portal; the IdP redirects to `https://<store>/m2oidc/actions/idpInitiatedLogin?provider_id=<id>`. Enabled per provider via `idp_initiated_enabled`. Supports optional `relay_state`, `login_hint`, and `login_type` parameters. Rate-limited and PKCE-protected.
- **Customer flow**: creates or matches a Magento customer, creates a one-time nonce cookie, and redirects to `CustomerOidcCallback` which sets the session and redirects to the relay state.
- **Admin flow**: uses Magento's native `Auth::login()` with a plugin-injected credential adapter — no bootstrap hacking, all security events fire normally. A nonce cookie bridges from the OIDC callback into the admin-authenticated context.
- **Token auto-refresh**: `TokenAutoRefreshObserver` (frontend) and `AdminTokenAutoRefreshObserver` (adminhtml) fire on every `controller_action_predispatch` and silently refresh the access token 60 seconds before expiry using the stored refresh token.
- **JIT provisioning**: auto-creates customers and admins on first login (configurable per provider).
- **Attribute mapping**: maps OIDC claims to Magento user fields (email, name, groups, address, DOB, gender, phone). Per-provider overrides take priority over global config.
- **Auto-discovery**: if a `well_known_config_url` is configured, all OIDC endpoints (authorize, token, userinfo, JWKS, logout, revocation, issuer) are auto-populated from the provider's discovery document on save.
- **Group-to-role/group mapping**: maps OIDC groups to Magento admin roles or customer groups with a configurable fallback. Stored in a normalized `m2oidc_oauth_role_mappings` table.
- **Claims-Based Access Control**: evaluates per-provider JSON rules against OIDC claims before allowing login — can block access based on any claim value.
- **Profile sync**: optionally re-syncs profile, address, and group/role assignments on every SSO login.
- **Identity verification bypass**: OIDC-authenticated admins skip the "enter your password" prompt when editing users/roles/account settings.
- **Password lifecycle suppression**: `OidcPasswordExpirationPlugin` and `OidcForcePasswordChangePlugin` prevent password expiry warnings and forced password-change redirects for OIDC-authenticated admins.
- **Per-user IdP binding**: the `m2oidc_oauth_user_provider` table is now read during authentication — not just written. `ProcessUserAction` (customer flow) and `CheckAttributeMappingAction` (admin flow) call `UserProviderResource::getBoundProviderId()` on every login. If a binding exists and does not match the current provider the login is rejected with `OAuthMessages::PROVIDER_MISMATCH`. If no binding exists (pre-OIDC account), the first IdP to authenticate the user claims the binding immediately via `saveMapping()`, making all subsequent cross-IdP attempts fail.
- **Login restriction**: optionally blocks all non-OIDC admin or customer logins, per provider. Protected by a lockout-prevention guard that reverts the setting if no OIDC users exist yet.
- **Lockout-prevention guards**: on provider save, if "disable non-OIDC login" is enabled but no users of that type have authenticated via OIDC for that provider, the setting is automatically reverted with a warning.
- **User-delete cleanup**: `AdminUserDeleteObserver` and `CustomerDeleteObserver` remove OIDC provider mappings from `m2oidc_oauth_user_provider` when Magento users are deleted, keeping the Sessions activity view accurate.
- **Dirty-field tracking**: `view/adminhtml/web/js/dirtyTracking.js` highlights modified provider form fields with an amber border before save, using `MutationObserver` to track dynamically added mapping rows.
- **PKCE (RFC 7636)**: supports S256 and plain code challenge methods. The code verifier is stored per provider ID in the session — concurrent logins for the same provider do not collide.
- **Rate limiting**: `OidcRateLimiter` enforces a fixed-window IP-based rate limit (10 attempts / 60 s) on the customer callback (`ReadAuthorizationResponse`), admin callback (`Oidccallback`), and back-channel logout (`BackChannelLogout`) endpoints to prevent code-stuffing and endpoint abuse attacks.
- **CSRF protection**: a token is embedded in the OAuth `state` parameter, generated on authorization request and validated on callback.
- **RP-Initiated Logout**: on admin logout, the `OidcLogoutPlugin` captures session tokens before destruction, calls the IdP's `end_session_endpoint`, and optionally revokes the access token via RFC 7009. Supports both standard OIDC and Authelia Forward-Auth logout modes. When the IdP only allows registering a single Post Logout Redirect URI, both admin and customer flows are routed through a unified callback (`m2oidc/actions/postlogout`) that uses a context-prefix in the `state` parameter to determine the final redirect destination.
- **Back-Channel Logout**: `POST /m2oidc/actions/backchannellogout` accepts a signed JWT logout token from the IdP and destroys the matching PHP session via `OidcSessionRegistry`. The `aud` claim is supported in both string and array formats per the OIDC spec.
- **Hybrid flow nonce validation (M-06)**: `ReadAuthorizationResponse` validates the nonce in the `id_token` from the token endpoint even when user data is sourced from the userinfo endpoint, preventing replay attacks in hybrid flows.
- **Debug log cleanup**: a dedicated cron job (`Cron/LogCleanup.php`, registered as `m2oidc_log_rotation`, scheduled `0 3 * * *`) deletes `var/log/M2Oidc.log` and disables debug logging when the log exceeds 7 days or when debug logging has been disabled in the admin UI.
- **OIDC discovery auto-refresh**: `Cron/RefreshOidcDiscovery.php` (registered as `m2oidc_refresh_oidc_discovery`, schedule `0 */6 * * *`) re-fetches `.well-known/openid-configuration` for every active provider every 6 hours and dirty-checks before writing, keeping endpoints up-to-date without requiring a manual provider save.
- **Structured logging service**: `Logger/OidcLogger.php` is the dedicated logging service extracted from `OAuthUtility`. Supports dual format: legacy Monolog envelope (default) and true JSON Lines (`{"ts":"...","level":"debug","message":"..."}`) controlled by `oidc/logging/json_lines` config. Automatically masks sensitive fields.
- **Extracted services (god-class split)**: `OAuthUtility` is now a thin facade. `Logger/OidcLogger` handles all log output, `Model/Provider/ProviderResolver` handles per-request provider context and resolution, `Model/Config/OidcConfigReader` maps 80+ config keys to `m2oidc_oauth_client_apps` columns. `Helper/Data` delegates all DB operations to `Model/ResourceModel/OidcProviderRepository`.
- **Atomic token consumption**: `Model/Cache/AtomicCacheInterface` (`getAndDelete()`) eliminates the TOCTOU race condition in one-time token consumption (nonces, state tokens, PKCE verifiers, ephemeral auth tokens). Default implementation: `RedisAtomicCache` — uses Lua `GETDEL` for true atomicity when Magento's cache backend is `Cm_Cache_Backend_Redis`; transparently falls back to sequential load + remove when Redis is not the active backend, providing identical single-server safety to the former `FileAtomicCache` default. **Production/HA deployments must configure Magento's cache and session backends to use Redis** so that tokens and session data are shared across all web nodes; see Section 5 (High Availability deployment).
- **Per-provider attribute mapper overrides**: `Model/Attribute/MapperPool` is a DI-registered registry that resolves the correct `AttributeMapperInterface` for a given provider and type. Third-party modules inject per-provider overrides via `{providerId}_{type}` keys in `etc/di.xml`. Both `AdminUserCreator` and `CustomerUserCreator` resolve mappers through the pool before falling back to the default.
- **Per-attribute sync control**: `AdminProfileSyncService` and `CustomerProfileSyncService` respect per-attribute `sync_on_sso` flags stored in `m2oidc_oauth_attribute_mappings`. A `shouldSync()` guard skips individual attributes where the normalized mapping row exists but `sync_on_sso = 0`, while legacy mode (no normalized row) always syncs.
- **Attribute mapping hook**: fires `oidc_after_attribute_mapping` event after all OIDC claims have been mapped; transport keys: `provider_id` (int), `mapped_attrs` (DataObject — writable), `raw_claims` (DataObject — read-only snapshot). Observers may mutate `mapped_attrs` before user creation or profile sync.
- **Rate-limiter strategy pattern**: `OidcRateLimiter` is now a thin facade delegating to an injected `StrategyInterface`. Default: `FixedWindowStrategy` (safe for all backends). DI virtual type `OidcSlidingWindowRateLimiter` provides a true sliding-window implementation via `SlidingWindowStrategy` (Lua-based on Redis) for deployments that need burst tolerance.
- **PHP 8 serialization for `OidcCredentialAdapter`**: `__sleep()`/`__wakeup()` replaced by `__serialize()`/`__unserialize()` — dependencies are restored eagerly on unserialize (no lazy `ObjectManager::getInstance()`).
- **Multi-provider**: database schema and utility layer support multiple active providers with per-provider settings, managed via the Provider grid at `/admin/m2oidc/provider/index`.
- **Session Activity view**: `/admin/m2oidc/sessions/index` lists all users who authenticated via OIDC, with total and active user counts per provider. Individual session records can be deleted via `Sessions/Delete.php` (POST, with confirmation dialog).
- **Zitadel support**: `OidcAuthenticationService` handles Zitadel-specific claim encoding (`claim_encoding = base64`) and nested role objects (`{"role_name": {"orgId": ...}}`), normalizing them into the standard flat group list consumed by the rest of the module.
- **Public client support**: `AccessTokenRequest` omits `client_secret` when `public_client` is enabled, supporting RFC 6749 §2.1 public clients (e.g., Zitadel PKCE apps without a secret).
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
- Centralize identity management with corporate IdP (Authelia, Keycloak, Auth0, Azure AD, Okta, Google, Zitadel)
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
- **Country name resolution**: `CustomerAttributeMapper` resolves country values to ISO codes via Magento's `CountryCollection`. When the PHP `intl` extension is available, English country names (e.g., `Germany` from Authelia) are additionally matched via `Locale::getDisplayRegion()` against Magento's active country list — this handles IdPs that always send English names regardless of the store locale.

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
- `CustomerCaptchaBypassPlugin` intercepts `CheckUserLoginObserver` on the frontend area
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
- `OidcRateLimiter` is a thin facade delegating to an injected `StrategyInterface`. Default strategy: `FixedWindowStrategy` — stores `{count, start}` JSON in cache; window start is recorded on the first request and the TTL is never reset on subsequent activity within the window; corrupted cache entries are detected and reset safely.
- For Redis deployments requiring burst tolerance, inject the `OidcSlidingWindowRateLimiter` DI virtual type which uses `SlidingWindowStrategy` (Lua-based true sliding window).
- Constants: `MAX_ATTEMPTS = 10`, `WINDOW_SECONDS = 60`
- Applied to `ReadAuthorizationResponse` (customer callback), `Oidccallback` (admin callback), and `BackChannelLogout` (back-channel logout); returns HTTP 429 when the limit is exceeded on `BackChannelLogout`; redirects to the admin login page with an error on `Oidccallback`
- Prevents attackers from replaying or enumerating authorization codes and from hammering both callback and back-channel logout endpoints

**Lockout-Prevention Guard**
- `Controller/Adminhtml/Provider/Save.php` checks `m2oidc_oauth_user_provider` before saving
- If "disable non-OIDC admin login" is requested but no admin has ever authenticated via OIDC for this provider, the setting is auto-reset to `0` and a warning is displayed
- Same guard applies to customer login restriction (`m2oidc_disable_non_oidc_customer_login`)
- `Block/Adminhtml/Provider/Edit/Tab/LoginOptions.php` surfaces warnings in the Login Options tab via `hasOidcAdminUsers()` / `hasOidcCustomerUsers()` — auto-redirect setting also guarded when multiple providers exist

**Per-User IdP Binding**
- When multiple OIDC providers are configured, an account with the same email can exist in both. Without binding enforcement, the effective security level is the lowest common denominator of all IdPs — revoking a user in one IdP leaves them able to log in via another.
- Binding is recorded in `m2oidc_oauth_user_provider.provider_id` and enforced at login time:
  - **Customer flow**: `ProcessUserAction::processUserAction()` calls `UserProviderResource::getBoundProviderId('customer', $userId)` after the email lookup. Mismatch → redirects to customer login with `PROVIDER_MISMATCH` error. No binding → claims it immediately.
  - **Admin flow**: `CheckAttributeMappingAction::execute()` calls `AdminUserCreator::getAdminUserByEmail()` to get the admin's ID, then `getBoundProviderId('admin', $adminId)`. Mismatch → redirects to admin login with error. No binding → claims it immediately.
- **First-login claim**: existing Magento accounts (created before OIDC was set up) have no row in `m2oidc_oauth_user_provider`. The first IdP to authenticate them writes the binding via `saveMapping()`, locking out all other IdPs for that account.
- **Manual override**: an admin can re-assign a user's IdP by updating `provider_id` in `m2oidc_oauth_user_provider` (e.g., when migrating users from one IdP to another).
- Log entries: `"Provider mismatch for customer <email> (bound=X, current=Y)"` and `"Provider binding claimed for existing customer <email> → provider Y"`.

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
- Nonce cookies (`oidc_admin_nonce`, `oidc_customer_nonce`) are one-time use, 300s TTL (5 minutes), HttpOnly, Secure, SameSite=Lax
- Nonces are created and validated by `OAuthSecurityHelper` via Magento's cache layer
- Cross-origin session handling: `SameSite=None; Secure; HttpOnly` applied to session cookies during OIDC routes only
- `SessionCookieObserver` applies only to `/m2oidc/` routes — does not affect other cookies globally
- HTTPS required (SameSite=None only works with Secure flag)

**JWT Verification**
- Validates JWT tokens using RS256/384/512 signatures
- JWKS endpoint fetching with a configurable per-provider cache TTL (default 86400 s / 24 h) via the `jwks_cache_ttl` column in `m2oidc_oauth_client_apps`; SHA-256 keyed; auto-refresh on signature mismatch for key rotation support
- **Circuit-breaker**: after a failed JWKS re-fetch, a `m2oidc_jwks_fail_*` cache key blocks further re-fetch attempts for 60 s to prevent hammering an unavailable IdP
- Key ID (kid) matching, token expiration, issuer, and audience validation
- Nonce validation: if `expectedNonce` is `null`, a WARNING is logged so operators can detect misconfigured hybrid flows
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
  - **Standard OIDC**: sends `id_token_hint`, `state` (prefixed `admin:<hex>` for context routing), and `post_logout_redirect_uri` pointing to the unified callback `/m2oidc/actions/postlogout`
  - **Authelia Forward-Auth**: detects path ending with `/logout` (without `/oauth2/` or `/oidc/`) and sends `rd=<adminBaseUrl>` parameter instead (unchanged)
- Customer logout (`OAuthLogoutObserver`) follows the same pattern: `state` is prefixed `customer:<hex>`, `post_logout_redirect_uri` points to the unified callback; Authelia mode uses `rd=<customerLoginUrl>` unchanged
- A short-lived `oidc_logout_guard` cookie (120s) prevents `AdminLoginRestrictionPlugin` from triggering an immediate re-login loop after redirect
- **Unified Post Logout Callback** (`Controller/Actions/PostLogoutCallback.php`): `GET /m2oidc/actions/postlogout` reads the `state` parameter echoed back by the IdP, parses the `admin:` or `customer:` prefix, and redirects to the admin login page or customer login page respectively; unknown/absent state falls back to store home

**Open Redirect Protection**
- `validateRedirectUrl()` in `OAuthSecurityHelper` enforces same-origin validation for all relay-state redirects
- Additionally rejects URLs containing null bytes (`\x00`) or backslashes (`\`) — common browser-specific bypass vectors
- Login-page relay states are also blocked to prevent redirect loops

**Admin SSO Button XSS Prevention**
- `view/adminhtml/templates/adminssobutton.phtml` uses a `data-url` attribute with a JavaScript event listener instead of an inline `onclick` handler to prevent XSS via the URL
- Hidden login form fields are set to `.disabled = true` to prevent form submission with empty credentials when the SSO button is present

**Worker State Isolation (SEC-06)**
- `OidcCredentialPlugin::beforeLogin()` unconditionally resets all internal flags at the start of every login attempt
- Prevents PHP-FPM worker process recycling from leaking OIDC state between requests

### Anti-Patterns / Not Suitable For

**IdP-Initiated SSO** *(now implemented)*
- **Supported** via `Controller/Actions/IdpInitiatedLogin.php` (OIDC Third-Party Initiated Login §4)
- Register `https://<store>/m2oidc/actions/idpInitiatedLogin?provider_id=<id>` in your IdP as the initiation URL
- Enable per provider via `idp_initiated_enabled` (Login Options tab); disabled by default
- Optional query parameters: `relay_state`, `login_hint`, `login_type`

**Federated Logout** *(substantially implemented)*
- **Admin RP-Initiated Logout**: `OidcLogoutPlugin` redirects to `endsession_endpoint` with `id_token_hint`; revokes access token via RFC 7009; supports Authelia Forward-Auth (`?rd=`) mode
- **Customer RP-Initiated Logout**: `OAuthLogoutObserver` handles `customer_logout` event; reads id_token, revokes access token, redirects to IdP
- **Back-Channel Logout** (FEAT-02): `POST /m2oidc/actions/backchannellogout` validates a signed JWT logout token (JWKS) and destroys the matching PHP session via `OidcSessionRegistry`; rate-limited via `OidcRateLimiter` (HTTP 429 on exceeded limit); supports `aud` claim in both string and array formats (per OIDC spec)
- **Logout guard**: `oidc_logout_guard` cookie (120s) prevents auto-redirect loops after IdP logout on both admin and customer areas
- **Single Post Logout Redirect URI**: `GET /m2oidc/actions/postlogout` is a unified callback that handles both admin and customer post-logout redirects; context is encoded in the OIDC `state` parameter (`admin:<hex>` or `customer:<hex>`); register only this one URL when the IdP only allows a single Post Logout Redirect URI

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

Handles nonce creation and validation for both admin and customer login flows. Nonces are stored in Magento's cache layer (not the database), making them short-lived and atomic. All one-time token consumption uses `AtomicCacheInterface::getAndDelete()` to eliminate the TOCTOU race condition present in a separate load+remove pattern.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `createAdminLoginNonce` | `(string $email, string $relayState)` | `string` | Generates a cryptographically random nonce, stores it in cache with a 300-second TTL (5 minutes), and returns the nonce value. The cache entry encodes the target email and relay state. |
| `redeemAdminLoginNonce` | `(string $nonce)` | `array\|null` | Atomically reads and deletes the nonce (via `AtomicCacheInterface::getAndDelete()`). Returns the stored payload `['email', 'relay_state']` or `null` if expired/not found. |
| `createCustomerLoginNonce` | `(string $email, string $relayState)` | `string` | Same as admin variant but for customer login flows. 300-second TTL. |
| `redeemCustomerLoginNonce` | `(string $nonce)` | `array\|null` | Same as admin variant but for customer login flows. Uses atomic `getAndDelete()`. |

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

### `\M2Oidc\OAuth\Model\Service\TokenRefreshService` / `\M2Oidc\OAuth\Model\Service\AdminTokenRefreshService`

Manage access token lifecycle for customer and admin sessions respectively. `AdminTokenRefreshService` mirrors `TokenRefreshService` but operates on the admin `AuthSession`.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `storeTokens` | `(string $accessToken, int $expiresIn, string $refreshToken)` | `void` | Persists `oidc_access_token`, `oidc_access_token_expires` (Unix timestamp), and `oidc_refresh_token` into the current session. |
| `refreshIfNeeded` | `()` | `void` | Reads the stored expiry; if the token expires within 60 seconds, silently calls the IdP token endpoint with the stored refresh token and calls `storeTokens()` with the new values. Called on every `controller_action_predispatch`. |

### `\M2Oidc\OAuth\Model\Service\CustomerProfileSyncService`

Syncs customer profile and address fields from OIDC claims on every login when the corresponding sync flags are enabled.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `syncProfile` | `(CustomerInterface $customer, array $flattenedAttrs)` | `void` | Re-maps core profile fields (name, DOB, gender, phone) from the current OIDC claim set and saves the customer. Called by `ProcessUserAction` when `sync_customer_profile_on_sso` is set. |
| `syncAddress` | `(CustomerInterface $customer, array $flattenedAttrs)` | `void` | Re-maps billing and shipping address fields from OIDC claims and upserts the customer's default addresses. Called by `ProcessUserAction` when `sync_customer_address_on_sso` is set. |

### `\M2Oidc\OAuth\Model\Service\OidcAuthenticationService`

Core service for processing OIDC provider responses. Centralizes parsing logic previously scattered across controllers. Injected into `CheckAttributeMappingAction` and `CustomerUserCreator`.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `validateUserInfo` | `(array $userInfo): void` | `void` | Validates that the OAuth provider response is not empty and contains no error keys. Throws `IncorrectUserInfoDataException` on failure. |
| `flattenAttributes` | `(array $attrs, string $prefix = '', int $depth = 0): array` | `array` | Recursively flattens a nested OIDC attribute array into a dot-notation keyed flat array (e.g., `address.city`). When `claim_encoding = base64` is active, attempts to Base64-decode string values; validates UTF-8 and rejects decoded strings containing C0/C1 control characters (null bytes, non-printable chars) before including them. Depth limit: `MAX_RECURSION_DEPTH = 5`. **`@internal public`** — callable from `ReadAuthorizationResponse` and `ShowTestResults` controllers but not part of the stable module API. |
| `extractEmail` | `(array $flatAttrs, string $emailAttrKey): string` | `string` | Reads `$emailAttrKey` from the flattened attributes. Falls back to `findEmailRecursive()` on the raw response if not found. Returns an empty string if no email is located. |
| `extractLoginType` | `(array $flatAttrs): string` | `string` | Determines whether the current flow is an admin or customer login based on the stored session context. Returns `'admin'` or `'customer'`. |
| `normalizeGroups` | `(mixed $groups): array` | `array` | Normalizes the OIDC group claim into a plain PHP string array. Handles three formats: (1) plain string → single-element array, (2) flat array of strings → returned as-is, (3) Zitadel nested object (`{"role_name": {"orgId": ...}}`) → extracts top-level keys as group names. |
| `normalizeZitadelRoleClaimsForDisplay` | `(array $flatAttrs, string $groupAttr): array` | `array` | For Zitadel providers, reconstructs human-readable parent role keys from dot-notation flattened subkeys (e.g., `roles.admin.orgId → admin`). Used in the Test Configuration display to show the original role names. |
| `reconstructNestedGroupClaim` | `(array $flatAttrs, string $groupAttr): void` | `void` | Synthesizes a parent group key from dot-notation subkeys in the flattened attribute array, mutating `$flatAttrs` in place. Called internally by `normalizeZitadelRoleClaimsForDisplay()`. |
| `findEmailRecursive` | `(mixed $data): ?string` | `string\|null` | Recursively searches any depth of the raw OIDC response for a value that passes PHP's `filter_var($v, FILTER_VALIDATE_EMAIL)`. Used as a last-resort fallback when the configured `email_attribute` is not present. |

---

### `\M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter`

Implements `Magento\Backend\Model\Auth\Credential\StorageInterface`. This is the bridge between OIDC and Magento's native admin auth.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `authenticate` | `($username, $password)` | `bool` | Validates the single-use ephemeral OIDC auth token (300 s cache TTL; generated by `OAuthSecurityHelper::createOidcAuthToken()`). Loads the admin user by email, checks active status and role assignment. Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth => true`. |
| `login` | `($username, $password)` | `$this` | Calls `authenticate()`, then records the login and reloads the user. |
| `reload` | `()` | `$this` | Reloads the user model from the database. |

### `\M2Oidc\OAuth\Model\Cache\AtomicCacheInterface`

Single-method interface for atomic read-and-delete operations on the cache layer. Eliminates the TOCTOU window inherent in a separate `load()` + `remove()` pattern for one-time tokens.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getAndDelete` | `(string $key): ?string` | `string\|null` | Returns the cached value for `$key` and removes it in the same logical operation. Returns `null` if the key does not exist or has expired. Default implementation: `RedisAtomicCache` — Lua `GETDEL` when Magento's cache backend is Redis (true atomicity, HA-safe); sequential load + remove fallback otherwise (safe for single-server). |

**DI preference** (default — `RedisAtomicCache` with transparent fallback):
```xml
<preference for="M2Oidc\OAuth\Model\Cache\AtomicCacheInterface"
            type="M2Oidc\OAuth\Model\Cache\RedisAtomicCache"/>
```

---

### `\M2Oidc\OAuth\Logger\OidcLogger`

Dedicated structured logging service extracted from `OAuthUtility`. Writes to `var/log/M2Oidc.log` via Magento's Monolog logger.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `customlog` | `(string $txt): void` | `void` | Writes a plain-text line if debug logging is enabled. |
| `customlogContext` | `(string $event, array $context = []): void` | `void` | Writes a structured log entry. Default format: JSON wrapped in Monolog envelope. When `oidc/logging/json_lines` config is enabled, emits raw newline-delimited JSON (`{"ts":"...","level":"debug","message":"$event",...context}`). Automatically masks sensitive fields: `client_secret`, `access_token`, `id_token`, `refresh_token`, `password`, `token`. |
| `isLogEnable` | `(): bool` | `bool` | Checks both legacy and new debug-log config path. |
| `isCustomLogExist` | `(): bool` | `bool` | Returns true if `var/log/M2Oidc.log` exists. |
| `deleteCustomLogFile` | `(): void` | `void` | Deletes `var/log/M2Oidc.log`. Called by `LogCleanup` cron. |

---

### `\M2Oidc\OAuth\Model\Provider\ProviderResolver`

Per-request provider context service extracted from `OAuthUtility`. Maintains a single in-memory cache to avoid redundant DB queries.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `setActiveProviderId` | `(int $providerId): void` | `void` | Sets the active provider ID for the current request. Subsequent calls to `resolveActiveProvider()` will use this ID. |
| `getActiveProviderId` | `(): ?int` | `int\|null` | Returns the currently active provider ID, or `null` if not set. |
| `resolveActiveProvider` | `(): array` | `array` | Returns the active provider row. Resolution order: (1) explicit ID set via `setActiveProviderId()`; (2) first active provider in table (single-provider / legacy); (3) empty array if none found. At most one DB query per request instance. |
| `getAllActiveProviders` | `(string $loginType = 'customer'): array` | `array` | Returns all active providers filtered by login type (`'customer'`, `'admin'`, `'both'`). |

---

### `\M2Oidc\OAuth\Model\Config\OidcConfigReader`

Config-resolution service extracted from `OAuthUtility`. Maps 80+ `OAuthConstants` keys to `m2oidc_oauth_client_apps` column names.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getStoreConfig` | `(string $key): mixed` | `mixed` | Resolves the config value for `$key`. Provider-specific keys are read from the active provider row (via `ProviderResolver`); global keys are read from `core_config_data` via `ScopeConfigInterface`. Returns `null` for empty provider-specific values. |

---

### `\M2Oidc\OAuth\Model\Attribute\MapperPool`

DI-registered registry that resolves the correct `AttributeMapperInterface` for a given provider and user type.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getMapper` | `(int $providerId, string $type): AttributeMapperInterface` | `AttributeMapperInterface` | Resolves a mapper. Lookup order: `"{providerId}_{type}"` key → `"default_{type}"` key. Returns the default mapper injected at construction if no pool entry matches. |

**DI registration** (defaults in `etc/di.xml`):
```xml
<type name="M2Oidc\OAuth\Model\Attribute\MapperPool">
    <arguments>
        <argument name="mappers" xsi:type="array">
            <item name="default_admin"    xsi:type="object">M2Oidc\OAuth\Model\Attribute\AdminAttributeMapper</item>
            <item name="default_customer" xsi:type="object">M2Oidc\OAuth\Model\Attribute\CustomerAttributeMapper</item>
        </argument>
    </arguments>
</type>
```

Third-party modules add per-provider overrides:
```xml
<type name="M2Oidc\OAuth\Model\Attribute\MapperPool">
    <arguments>
        <argument name="mappers" xsi:type="array">
            <!-- Override admin mapper for provider ID 3 -->
            <item name="3_admin" xsi:type="object">Vendor\Module\Model\Attribute\MyAdminMapper</item>
        </argument>
    </arguments>
</type>
```

---

### `\M2Oidc\OAuth\Model\ResourceModel\OidcProviderRepository`

Data-access repository for `m2oidc_oauth_client_apps` extracted from `Helper/Data`. All direct DB operations on the provider table should go through this class.

| Method | Signature | Returns | Description |
|---|---|---|---|
| `getOAuthClientApps` | `(): Collection` | `Collection` | Returns the full provider collection. |
| `getClientDetailsByAppName` | `(string $appName): array\|null` | `array\|null` | Looks up a provider by `app_name`; auto-decrypts `client_secret`. |
| `getClientDetailsById` | `(int $id): array\|null` | `array\|null` | Looks up a provider by primary key; auto-decrypts `client_secret`. |
| `getAllActiveProviders` | `(string $loginType = 'customer'): array` | `array` | Returns active providers filtered by login type, ordered by `sort_order`. |
| `saveTestStatus` | `(string $status, string $appName): void` | `void` | Updates `last_test_status` and `last_test_at` by app name. |
| `saveTestStatusById` | `(string $status, int $id): void` | `void` | Same by provider ID. |
| `saveReceivedOidcClaims` | `(array $claims, int $id): void` | `void` | Persists received claim keys as JSON in `received_oidc_claims`. |

---

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

### Pattern 6: Configure a single Post Logout Redirect URI for providers with one-URL limits

Some IdPs (e.g., certain Keycloak realm settings, small SaaS IdPs) allow only **one** Post Logout Redirect URI per OIDC client. Register this URL:

```
https://your-site.com/m2oidc/actions/postlogout
```

The module automatically uses this endpoint as `post_logout_redirect_uri` for both admin and customer logout flows when no `post_logout_url` override is set on the provider. Context is encoded in the OIDC `state` parameter:

| Flow | state value | Final destination |
|---|---|---|
| Admin logout | `admin:<random>` | Admin login page (`/admin/`) |
| Customer logout | `customer:<random>` | Customer login page (`/customer/account/login/`) |
| Unknown/absent state | — | Store home (`/`) |

**Override per provider**: if you need a different destination URL (e.g., a custom branded landing page), set `post_logout_url` on the provider row — both flows will use that URL and the state-based routing is bypassed.

**Authelia note**: Authelia's `?rd=` parameter bypasses this mechanism entirely. No change is needed for Authelia setups.

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

### Pattern 8: High Availability / Multi-Server Deployment

For production deployments running multiple web nodes (load-balanced), two backend systems must be shared across all nodes so that OIDC state is consistent regardless of which node handles a request.

#### Cache backend — Redis (required for atomic token operations)

Configure Magento's default cache to use Redis in `app/etc/env.php`:

```php
'cache' => [
    'frontend' => [
        'default' => [
            'backend' => 'Cm_Cache_Backend_Redis',
            'backend_options' => [
                'server'   => '127.0.0.1',
                'port'     => '6379',
                'database' => '0',
            ],
        ],
    ],
],
```

`RedisAtomicCache` (the default `AtomicCacheInterface` implementation) detects `Cm_Cache_Backend_Redis` via reflection and switches to a Lua `GETDEL` script for true atomic read-and-delete. This eliminates the TOCTOU window on state tokens, nonces, PKCE verifiers, and ephemeral auth tokens across all web nodes.

> When Redis is **not** the active backend, `RedisAtomicCache` falls back transparently to sequential load + remove — identical behaviour to the former `FileAtomicCache`. No configuration override is needed for single-server deployments.

#### Session backend — Redis (required for shared session data)

OIDC session keys (`oidc_id_token`, `oidc_access_token`, `oidc_provider_id`, nonce cookies, etc.) are stored in the PHP session. For multi-node setups, sessions must be shared:

```php
'session' => [
    'save'  => 'redis',
    'redis' => [
        'host'     => '127.0.0.1',
        'port'     => '6379',
        'database' => '2',
    ],
],
```

#### Rate limiter — already HA-safe

`FixedWindowStrategy` (the default `OidcRateLimiter`) uses `CacheInterface` — i.e., Magento's shared cache backend. Once you configure the cache backend to Redis, rate limiting is automatically shared across all nodes. No DI change is needed for the rate limiter in most HA setups.

To use true sliding-window semantics (Redis Lua ZADD/ZCOUNT), inject the `OidcSlidingWindowRateLimiter` virtual type in the controller(s) where burst tolerance is desired.

#### HTTP Timeout — per provider

If your IdP responds slowly (e.g., across cloud regions), set the **HTTP Timeout** field in the provider's OAuth Settings tab to a value that accounts for latency without hanging PHP-FPM workers. The default is 30 seconds; a per-provider `http_timeout` column in `m2oidc_oauth_client_apps` controls this value for both token-endpoint requests (`Curl::callAPI`) and JWKS fetches (`JwtVerifier::fetchJwks`).

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

A dedicated cron job (`Cron/LogCleanup.php`, registered as `m2oidc_log_rotation`, scheduled at `0 3 * * *`) handles log cleanup. It disables logging and **deletes** (does not rotate) `var/log/M2Oidc.log` when the log exceeds 7 days or when debug logging has been disabled in the admin UI. This logic was removed from `SendAuthorizationRequest` to avoid triggering it on every auth attempt. You'll need to re-enable debug logging in the admin panel after the file is deleted. Note: `Cron/LogRotation.php` still exists as a `@deprecated 3.0.8` backward-compatibility wrapper — it is no longer the class used by the cron scheduler.

### 9. The OAuth `state` parameter encodes multiple values

The state is formatted as a JSON+Base64 structure containing relay state, session ID, app name, login type, CSRF token, and provider ID. A legacy pipe-delimited format is also supported for backward compatibility. If you're debugging state issues, decode it as Base64 JSON first, fall back to pipe-splitting.

### 10. Non-OIDC admin login can be disabled (with a safety net)

When `m2oidc_disable_non_oidc_admin_login` is enabled on a provider, the `AdminLoginRestrictionPlugin` throws an `AuthenticationException` for any password-based admin login — but only if the OIDC button (`show_admin_link`) is visible on that provider. If the button is hidden, password login is allowed regardless, preventing a total lockout.

### 11. Nonce cookies are one-time use with a 300-second (5-minute) TTL

Both `oidc_admin_nonce` and `oidc_customer_nonce` are deleted immediately upon use (via cache delete in `OAuthSecurityHelper`). The 300 s TTL provides a comfortable window for slow IdPs and users with intermittent connectivity. If a browser has cookies blocked or the user navigates away and back, the nonce will be missing or expired and the callback will redirect to the login page with an error. Ensure the browser has cookies enabled and the session path is not overly restrictive.

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
- Path ends with `/logout` AND does not contain `/oauth2/` or `/oidc/` → **Authelia mode**: appends `?rd=<admin_base_url>` (admin) or `?rd=<customer_login_url>` (customer)
- Anything else → **Standard OIDC**: appends `id_token_hint`, context-prefixed `state`, and `post_logout_redirect_uri=<callback_url>`

If your IdP's logout path happens to match the Authelia heuristic but expects standard OIDC parameters, the redirect will be malformed. Verify by checking the debug log for `mode=forward-auth(rd)` vs `mode=oidc-rp-logout`.

### 18. Single Post Logout Redirect URI — use the unified callback

Some OIDC providers only allow registering **one** Post Logout Redirect URI per client. Since admin logout and customer logout historically used different destination URLs (`/admin/` vs `/customer/account/login/`), both flows would break if only one was registered.

**Solution**: register only the unified callback URL with your IdP:
```
https://your-site.com/m2oidc/actions/postlogout
```

The context is carried in the OIDC `state` parameter that the IdP echoes back verbatim:
- Admin state: `admin:<16-byte-hex>` → callback redirects to admin login page
- Customer state: `customer:<16-byte-hex>` → callback redirects to customer login page
- No state / unknown → callback redirects to store home

**Authelia is unaffected**: Authelia uses `?rd=<url>` and never calls this callback endpoint — it redirects directly to the URL in `rd`. No configuration change is needed for Authelia setups.

### 19. Zitadel sends claims Base64-encoded — set `claim_encoding = base64`

By default Zitadel encodes custom metadata claim values as Base64 strings. If you see garbled or unreadable attribute values in the Test Configuration view, set **Claim Encoding** to `base64` in the OAuth Settings tab for the Zitadel provider. `OidcAuthenticationService::flattenAttributes()` will then attempt to Base64-decode each string value and validate UTF-8 before including it in the flattened attribute map. If decoding fails or the result is not valid UTF-8, the original (encoded) value is used instead.

### 20. Zitadel roles arrive as nested objects — not a flat group array

Zitadel sends role claims in the format `{"role_name": {"orgId": "..."}}` rather than a simple `["role1", "role2"]` array. `OidcAuthenticationService::normalizeGroups()` detects this structure and extracts the top-level keys (`role_name`) as the effective group names, which are then matched against `m2oidc_oauth_role_mappings`. Configure your role mappings using the role **name** (the object key), not the nested `orgId` value.

### 21. Public clients must have `public_client = 1` set — not just an empty secret

If your Zitadel application is a PKCE app without a client secret (RFC 6749 §2.1), leave **Client Secret** blank **and** enable the **Public Client** toggle. Without the toggle, `AccessTokenRequest` will still include an empty `client_secret` parameter in the POST body, which some IdPs (including Zitadel) reject as an invalid client assertion. The toggle instructs the module to omit the parameter entirely.

### 22. JWKS fetch failures trigger a circuit-breaker

`JwtVerifier` opens a 60-second circuit-breaker (`m2oidc_jwks_fail_*` cache key) after a failed JWKS re-fetch. While the breaker is open, signature verification returns `null` immediately rather than hammering the IdP endpoint on every auth attempt. The breaker expires automatically after 60 s. If JWKS failures persist longer, check IdP reachability and network connectivity from Magento. Check `var/log/M2Oidc.log` for `JWKS circuit-breaker open` log entries.

### 23. Admin callback is now rate-limited

The admin callback controller (`Controller/Adminhtml/Actions/Oidccallback.php`) applies the same IP-based rate limit (10 attempts / 60 s) as the frontend callback. Requests exceeding the limit are redirected to the admin login page with an error message (not a bare HTTP 429). All three OIDC entry points are now protected: `ReadAuthorizationResponse`, `BackChannelLogout`, and `Oidccallback`. If a legitimate admin triggers the rate limit (e.g., multiple rapid test logins), they must wait 60 s for the window to reset.

### 24. Multi-website customer login uses website context validation (SEC-08)

`CustomerOidcCallback` checks that the authenticated customer belongs to the current Magento website. A customer created on website A cannot log in via OIDC on website B. This prevents cross-site session injection in multi-website setups. If a customer reports SSO working in one store but not another, verify their customer account's website assignment in **Customers > All Customers**.

### 25. Lockout-prevention guard silently reverts invalid settings

If you enable "Disable non-OIDC admin login" on a provider where no admin has yet authenticated via OIDC, the `Provider/Save.php` controller automatically resets the setting to `0` before saving. A warning message is displayed but no exception is thrown. The same applies to the customer restriction. Check the save response for warnings if the setting doesn't appear to stick.

### 26. Billing address requires all four fields

`CustomerUserCreator` only creates a billing address when all four required fields are mapped AND return non-empty values from the OIDC token: `billing_address_attribute` (street), `billing_zip_attribute`, `billing_city_attribute`, `billing_country_attribute`. If any of these is blank (either not mapped or the claim is missing), the entire address object is skipped. This is intentional — a partial address would fail Magento's address validation at checkout.

### 27. Required attribute fields block provider save

Provider save validates that `email_attribute`, `username_attribute`, `firstname_attribute`, and `lastname_attribute` are all non-empty strings. A save attempt with any missing field returns an error and redirects back to the edit form without persisting any changes. This prevents a provider configuration that would silently fail during the OIDC login flow.

### 28. Auto-discovery overwrites manually entered endpoints

When a `well_known_config_url` is configured, every provider save fetches the discovery document and overwrites all endpoint fields (authorize, token, userinfo, JWKS, logout, revocation, issuer) with the discovered values. If you need to override a specific endpoint (e.g., a custom revocation URL), remove or clear the `well_known_config_url` first to prevent it from being overwritten on the next save.

### 29. `configureSSOSession()` is no longer called from `SendAuthorizationRequest`

An earlier version called `SessionHelper::configureSSOSession()` at the start of the authorization request to set `SameSite=None` on session cookies. This was removed because it conflicted with `session_regenerate_id()` in the callback handlers, causing session data loss. `SameSite=None` is now applied exclusively at response time via `SessionCookieObserver` for `/m2oidc/` routes. Do not re-add a `configureSSOSession()` call to `SendAuthorizationRequest`.

### 30. Test mode is detected from the relay state URL, not `core_config_data`

Previously, a test-mode run would write an `IS_TEST` flag to `core_config_data` and read it back in the callback. This was replaced: test mode is now detected by checking whether the relay state URL matches the `TEST_RELAYSTATE` constant. The IS_TEST flag is no longer written to or read from the database, which prevents the flag from leaking if the IdP never completes the callback (e.g., the user closes the browser mid-flow).

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
| `Magento\Captcha\Observer\CheckUserLoginObserver` | `CustomerCaptchaBypassPlugin` | 10 | frontend | Skips CAPTCHA for OIDC-authenticated customer logins |
| `Magento\Customer\Api\AccountManagementInterface` | `CustomerLoginRestrictionPlugin` | 5 | frontend | Blocks non-OIDC customer logins when restriction is enabled |
| `Magento\User\Model\User` | `OidcIdentityVerificationPlugin` | 10 | adminhtml | Bypasses password re-verification for OIDC admins |
| `Magento\User\Model\User` | `OidcPasswordExpirationPlugin` | 10 | adminhtml | Suppresses password expiration warnings for OIDC admins |
| `Magento\User\Model\User` | `OidcForcePasswordChangePlugin` | 10 | adminhtml | Suppresses forced password change redirects for OIDC admins |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Removes "required" from password field in user edit form |
| `Magento\User\Block\Role\Tab\Info` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Same for role edit form |
| `Magento\Backend\Block\System\Account\Edit\Form` | `OidcIdentityFieldPlugin` | 20 | adminhtml | Same for account settings form |
| `Magento\User\Block\User\Edit\Tab\Main` | `OidcUserInfoPlugin` | 20 | adminhtml | Injects OIDC provider info into admin user profile page |
| `Magento\Customer\Block\Account\Dashboard` | `OidcInfoPlugin` | 20 | frontend | Injects OIDC provider info into customer account page |
| `Magento\User\Model\User` | `AdminUserDeletePlugin` | 10 | global | `afterDelete`: removes matching row from `m2oidc_oauth_user_provider`; belt-and-suspenders alongside `AdminUserDeleteObserver` |

### Events Observed

| Event | Observer | Area |
|---|---|---|
| `controller_front_send_response_before` | `SessionCookieObserver` | frontend (scoped to `/m2oidc/` routes only) |
| `controller_action_predispatch` | `TokenAutoRefreshObserver` | frontend (silent access-token refresh) |
| `controller_action_predispatch` | `AdminTokenAutoRefreshObserver` | adminhtml (silent access-token refresh) |
| `controller_action_predispatch` | `CustomerLoginAutoRedirectObserver` | frontend (auto-redirects unauthenticated customers to IdP when enabled; suppressed by `oidc_logout_guard` cookie) |
| `customer_logout` | `OAuthLogoutObserver` | frontend (customer RP-Initiated Logout) |
| `customer_logout` | `CustomerSetLogoutFlagObserver` | frontend (sets logout flag on session destruction) |
| `customer_delete` | `CustomerDeleteObserver` | frontend (removes `m2oidc_oauth_user_provider` row) |
| `controller_action_predispatch` | `AdminLoginAutoRedirectObserver` | adminhtml (auto-redirects unauthenticated admins to IdP when enabled; suppressed by `oidc_logout_guard` cookie) |
| `admin_user_authenticate_after` or equivalent | `AdminSetLogoutFlagObserver` | adminhtml (sets logout flag on admin session destruction) |
| `admin_user_delete_after` | `AdminUserDeleteObserver` | global (removes `m2oidc_oauth_user_provider` row) |
| Admin logout event | `OAuthLogoutObserver` | adminhtml |

### Custom Events Dispatched

| Event | Where dispatched | Payload keys | Purpose |
|---|---|---|---|
| `oidc_before_user_create` | `UserProvisioningService` | `provider_id`, `user_type`, `user_data` | Fired before JIT user creation; observers can alter or veto |
| `oidc_after_user_create` | `UserProvisioningService` | `provider_id`, `user_type`, `user` | Fired after successful JIT user creation |
| `oidc_admin_user_after_create` | `UserProvisioningService` | `provider_id`, `user` | Admin-specific post-creation hook |
| `oidc_after_attribute_mapping` | `CheckAttributeMappingAction` | `provider_id` (int), `mapped_attrs` (DataObject — writable), `raw_claims` (DataObject — read-only) | Fired after all OIDC claims have been mapped to Magento attributes. Observers may call `mapped_attrs->setData()` to alter values before user creation or profile sync. |

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
│   ├── crontab.xml                           # Cron job registrations (m2oidc_log_rotation daily 03:00; m2oidc_refresh_oidc_discovery every 6h)
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
├── Cron/
│   ├── LogCleanup.php                        # Daily cron (03:00); deletes var/log/M2Oidc.log when >7 days old or logging disabled
│   ├── LogRotation.php                       # @deprecated 3.0.8 — BC wrapper extending LogCleanup; crontab.xml uses LogCleanup
│   └── RefreshOidcDiscovery.php              # NEW: every 6h; re-fetches .well-known for all active providers; dirty-checks before writing to DB
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
│   │   ├── IdpInitiatedLogin.php             # IdP-Initiated SSO entry point (OIDC §4); rate-limited, PKCE, CSRF state
│   │   ├── PostLogoutCallback.php            # Unified post-logout redirect; reads state prefix (admin:|customer:) and redirects accordingly
│   │   └── ShowTestResults.php               # Test Configuration results display; test mode detected from relay state URL (TEST_RELAYSTATE constant) — IS_TEST flag no longer written to core_config_data
│   └── Adminhtml/
│       ├── Actions/
│       │   ├── SendAuthorizationRequest.php  # Step 1: Redirect to IDP (admin); stamps loginType=admin
│       │   └── Oidccallback.php              # Admin login: redeems nonce, calls Auth::login() with OIDC marker
│       ├── OAuthsettings/Index.php           # Admin page: OAuth Settings
│       ├── Attrsettings/Index.php            # Admin page: Attribute Mapping
│       ├── Signinsettings/Index.php          # Admin page: Sign In Settings
│       ├── Provider/Save.php                 # Provider CRUD save; validates required attr fields; runs lockout-prevention guard; auto-discovery fetch
│       └── Sessions/Delete.php               # POST: deletes individual session activity record by ID; requires M2Oidc_OAuth::oidc_sessions
│
├── Model/
│   ├── Auth/
│   │   └── OidcCredentialAdapter.php         # StorageInterface impl for OIDC auth; PHP 8 __serialize()/__unserialize() (eager restoration)
│   ├── Attribute/
│   │   ├── AttributeMapperInterface.php      # Shared mapper interface
│   │   ├── AdminAttributeMapper.php          # Maps OIDC claims → admin user fields
│   │   ├── CustomerAttributeMapper.php       # Maps OIDC claims → customer fields + address
│   │   └── MapperPool.php                    # NEW: DI registry for per-provider mapper overrides ({providerId}_{type} keys)
│   ├── Cache/
│   │   ├── AtomicCacheInterface.php          # NEW: single-method getAndDelete() — atomic read-and-delete
│   │   ├── FileAtomicCache.php               # NEW: default impl (load+remove; single-server safe)
│   │   └── RedisAtomicCache.php              # NEW: Lua GETDEL for true atomicity on Redis
│   ├── Config/
│   │   └── OidcConfigReader.php              # NEW: extracted from OAuthUtility — 80+ config key→DB column mappings
│   ├── Provider/
│   │   ├── MappingRepository.php             # Repository for normalized attribute/role mapping tables
│   │   └── ProviderResolver.php              # NEW: extracted from OAuthUtility — per-request provider context
│   ├── Security/
│   │   ├── OidcRateLimiter.php               # Thin facade delegating to StrategyInterface
│   │   └── RateLimiterStrategy/
│   │       ├── StrategyInterface.php         # NEW: isAllowed(string $ip): bool
│   │       ├── FixedWindowStrategy.php       # NEW: default fixed-window (all backends)
│   │       └── SlidingWindowStrategy.php     # NEW: sliding-window (Redis Lua)
│   ├── Service/
│   │   ├── AdminUserCreator.php              # JIT admin provisioning + group-to-role mapping
│   │   ├── AdminProfileSyncService.php       # Syncs admin profile and role from OIDC claims on login
│   │   ├── AdminTokenRefreshService.php      # Silent access-token refresh for admin AuthSession
│   │   ├── CustomerUserCreator.php           # JIT customer provisioning + address/group creation
│   │   ├── CustomerProfileSyncService.php    # Syncs customer profile and address from OIDC claims on login
│   │   ├── OidcAuthenticationService.php     # Core OIDC response processor: validates, flattens, extracts email/type/groups; Zitadel Base64 + nested role normalization
│   │   ├── TokenRefreshService.php           # Silent access-token refresh for customer session
│   │   └── UserProvisioningService.php       # Orchestrates admin/customer provisioning
│   ├── Resolver/                             # GraphQL resolvers (if GraphQL module present)
│   ├── M2oidcOauthClientApps.php             # Model for oauth_client_apps table
│   └── ResourceModel/
│       ├── OidcProviderRepository.php        # NEW: all DB ops on m2oidc_oauth_client_apps; extracted from Data.php
│       ├── UserProvider.php                  # Tracks provider → Magento user binding
│       └── M2OidcOauthClientApps/
│           ├── Collection.php                # Collection model
│           └── (ResourceModel).php           # Resource model
│
├── Plugin/
│   ├── AdminLoginRestrictionPlugin.php       # Blocks non-OIDC admin login (with safety net)
│   ├── CustomerLoginRestrictionPlugin.php    # Blocks non-OIDC customer login when restriction is enabled
│   ├── Auth/
│   │   ├── OidcCredentialPlugin.php          # Injects OIDC adapter; resets guard flags on every login
│   │   └── OidcLogoutPlugin.php              # Token revocation + RP-Initiated Logout (aroundLogout)
│   ├── Captcha/
│   │   ├── OidcCaptchaBypassPlugin.php       # Skips admin CAPTCHA for OIDC logins
│   │   └── CustomerCaptchaBypassPlugin.php   # Skips customer CAPTCHA for OIDC logins
│   ├── Csp/
│   │   └── OidcCspPolicyCollector.php        # Adds IdP domains to Content Security Policy whitelist dynamically
│   ├── Customer/
│   │   └── Block/
│   │       └── OidcInfoPlugin.php            # Injects OIDC provider info into customer account page
│   └── User/
│       ├── AdminUserDeletePlugin.php           # afterDelete on Magento\User\Model\User; removes m2oidc_oauth_user_provider row; belt-and-suspenders alongside AdminUserDeleteObserver
│       ├── OidcIdentityVerificationPlugin.php  # Bypasses password re-verification
│       ├── OidcPasswordExpirationPlugin.php    # Suppresses password expiry warnings
│       ├── OidcForcePasswordChangePlugin.php   # Suppresses forced password change
│       └── Block/
│           ├── OidcIdentityFieldPlugin.php     # Removes required from password field in forms
│           └── OidcUserInfoPlugin.php          # Injects OIDC info into admin user profile page
│
├── Helper/
│   ├── Data.php                              # Base config; DB ops delegated to OidcProviderRepository
│   ├── OAuthUtility.php                      # Thin facade: delegates logging→OidcLogger, provider→ProviderResolver, config→OidcConfigReader
│   ├── OAuthConstants.php                    # All constants (config keys, defaults, URLs)
│   ├── OAuthMessages.php                     # User-facing message templates
│   ├── OAuthSecurityHelper.php               # Nonce create/validate via cache (admin + customer)
│   ├── SessionHelper.php                     # SameSite=None cookie handling (OIDC routes only)
│   ├── Curl.php                              # HTTP client for token/userinfo requests
│   ├── JwtVerifier.php                       # JWT signature validation using JWKS (pure PHP, cached)
│   ├── TestResults.php                       # Test configuration HTML output
│   └── OAuth/
│       ├── AuthorizationRequest.php          # Builds the authorize URL query string (includes PKCE challenge)
│       ├── AccessTokenRequest.php            # Builds the token exchange POST body; PKCE code_verifier support; omits client_secret for public clients (RFC 6749 §2.1)
│       └── AccessTokenRequestBody.php        # Alternate token body (header auth variant)
│
├── UI/
│   ├── Component/DataProvider.php            # Provider grid data provider
│   ├── Component/DataProvider/SessionDataProvider.php  # Active sessions grid data provider
│   ├── Component/Listing/Column/Actions.php  # Provider grid row actions
│   ├── Component/Listing/Column/ActiveUserCount.php    # "total (active)" user count per provider
│   ├── Component/Listing/Column/OnlineStatus.php       # Shows providers with active sessions
│   ├── Component/Listing/Column/PkceStatus.php         # PKCE configuration status badge
│   ├── Component/Listing/Column/JwksStatus.php         # JWKS endpoint status badge
│   ├── Component/Listing/Column/TestStatusOptions.php  # Test result status badge
│   ├── Component/Listing/Column/ActiveStatus.php       # Colored Active/Inactive badge for provider listing; reads is_active from collection — no extra DB query
│   └── Component/Listing/Column/SessionActions.php     # "Delete" action link per row in the session activity grid; POST URL with confirmation dialog
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
│   ├── CustomerLoginAutoRedirectObserver.php # Auto-redirects unauthenticated customers to IdP; respects oidc_logout_guard
│   ├── CustomerSetLogoutFlagObserver.php     # Sets logout flag on customer session destruction
│   ├── AdminLoginAutoRedirectObserver.php    # Auto-redirects unauthenticated admins to IdP; respects oidc_logout_guard
│   ├── AdminSetLogoutFlagObserver.php        # Sets logout flag on admin session destruction
│   ├── TokenAutoRefreshObserver.php          # Refreshes customer access token on controller_action_predispatch (frontend)
│   ├── AdminTokenAutoRefreshObserver.php     # Refreshes admin access token on controller_action_predispatch (adminhtml)
│   ├── AdminUserDeleteObserver.php           # Removes OIDC user mapping when admin user is deleted
│   └── CustomerDeleteObserver.php            # Removes OIDC user mapping when customer is deleted
│
├── Logger/
│   ├── Logger.php                            # Custom Monolog logger
│   ├── Handler.php                           # Writes to var/log/M2Oidc.log
│   └── OidcLogger.php                        # NEW: structured logging service; JSON Lines mode; sensitive-field masking
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
    -> CustomerLoginAction (creates oidc_customer_nonce cookie via OAuthSecurityHelper, 300s TTL)
    -> CustomerOidcCallback (redeems nonce via OAuthSecurityHelper, validates website context SEC-08,
                             sets customer session, sets oidc_customer_authenticated cookie, redirects to relay state)

ADMIN FLOW:
  Browser -> SendAuthorizationRequest (adminhtml, stamps loginType=admin, generates PKCE verifier in session)
    -> IDP (authorize endpoint)
    -> ReadAuthorizationResponse (callback, same as customer: CSRF validation, rate limiting, JWT verification)
    -> CheckAttributeMappingAction (evaluates access control rules, loginType=admin)
    -> [if user exists] -> creates oidc_admin_nonce cookie via OAuthSecurityHelper (300s TTL) -> redirect to Oidccallback
    -> [if auto-create] -> AdminUserCreator (group→role mapping) -> creates nonce -> redirect to Oidccallback
    -> Oidccallback -> rate-limit check (OidcRateLimiter) -> redeems nonce via OAuthSecurityHelper
                    -> creates ephemeral auth token (OAuthSecurityHelper::createOidcAuthToken(), 300s TTL)
                    -> Auth::login($email, $ephemeralToken)
       |-> OidcCredentialPlugin detects OIDC_ token prefix (after resetting guard flags) -> injects OidcCredentialAdapter
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
| `idp_initiated_enabled` | Smallint (default 0); enables IdP-Initiated SSO for this provider (Login Options tab) |
| `jwks_cache_ttl` | Int, nullable, default 86400. Per-provider JWKS public-key cache lifetime in seconds. Read by `JwtVerifier` via `OAuthConstants::JWKS_CACHE_TTL`; falls back to 86400 when null. Run `bin/magento setup:upgrade` after adding this column via schema migration. |
| `http_timeout` | Smallint, not null, default 30. Per-provider HTTP connect/read timeout in seconds for token endpoint and JWKS fetch calls. Read by `Curl::callAPI()` and `JwtVerifier::fetchJwks()` via `OAuthConstants::HTTP_TIMEOUT`. Configurable in the **OAuth Settings** tab of the provider edit form (min 5, max 300). A single retry on empty response fires after a 500 ms backoff; HTTP 4xx/5xx errors are not retried. |
| `claim_encoding` | Varchar; `'none'` (default) or `'base64'`. Set to `'base64'` for Zitadel providers that Base64-encode their claim values; `OidcAuthenticationService::flattenAttributes()` decodes, UTF-8-validates, and rejects decoded strings containing C0/C1 control characters before use. |
| `public_client` | Smallint 0\|1 (default 0). When enabled, `AccessTokenRequest` omits `client_secret` from the token exchange — required for RFC 6749 §2.1 public clients (e.g., Zitadel PKCE apps without a client secret). |
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

Unique constraint on `(user_type, user_id)` — each Magento user is linked to at most one provider. Used both for login-time IdP binding enforcement and by the logout observer to retrieve the correct `endsession_endpoint` for the logged-in user's provider.

Records are cleaned up in three ways:
1. **`AdminUserDeleteObserver`** — fires on `admin_user_delete_after`; removes the row for the deleted admin
2. **`AdminUserDeletePlugin`** (`Plugin/User/AdminUserDeletePlugin.php`) — `afterDelete` on `Magento\User\Model\User`; belt-and-suspenders redundancy alongside the observer
3. **`Sessions/Delete.php`** admin controller — allows individual record deletion from the Sessions UI (`/admin/m2oidc/sessions/index`) via a POST action; uses `UserProviderResource::deleteById()`

`UserProviderResource` key methods: `saveMapping()` (INSERT … ON DUPLICATE KEY UPDATE), `deleteMapping()`, `getProviderInfo()`, `getBoundProviderId(userType, userId)` (returns the bound `provider_id` or `null` — called by `ProcessUserAction` and `CheckAttributeMappingAction` to enforce per-user IdP binding), `deleteById()`, `countByTypeAndProvider()` (used by lockout-prevention guards in `Provider/Save.php`).

---

## 9. Future Improvements

This section documents remaining technical debt and potential enhancements. Items marked *(implemented)* have been completed and removed.

### Testing & Code Quality

**Extend Unit Test Coverage** *(implemented)*
- **Current state**: Core test coverage in `Test/Unit/` and `Test/Integration/`. Covered (4,000+ test cases):
  - `OidcCredentialAdapterTest` (353), `OidcCredentialPluginTest`, `JwtVerifierTest` (521)
  - `AdminAttributeMapperTest` (157), `CustomerAttributeMapperTest` (252)
  - `AdminUserCreatorRoleMappingTest` (389), `CustomerUserCreatorAddressTest` (517)
  - `IdpInitiatedLoginTest` (5 security regression tests)
  - `BackChannelLogoutTest`, `OidcRateLimiterTest` (both strategy implementations)
  - Observers: `AdminTokenAutoRefreshObserverTest`, `TokenAutoRefreshObserverTest`, `AdminUserDeleteObserverTest`, `CustomerDeleteObserverTest`, `OAuthLogoutObserverTest`
  - Plugin: `OidcLogoutPluginTest`
  - IdP binding: `CheckAttributeMappingActionIdpBindingTest`, `ProcessUserActionIdpBindingTest`
  - Integration: `AdminOidcLoginFlowTest` (175), `CustomerOidcLoginFlowTest` (148), `SecurityPluginsTest` (168), `AccessControlRulesTest`
  - `AdminProfileSyncServiceTest`, `CustomerProfileSyncServiceTest` (per-attribute `shouldSync()` logic, profile/address sync, format helpers)
  - `OAuthLogoutObserverTest` extended with edge cases (token revocation, guard cookie TTL, empty endpoint, ForwardAuth mode, state prefix)

**Extend Static Analysis Compliance** *(implemented)*
- `phpstan.neon`, `phpstan.local.neon`, `phpstan-stubs.stub`, `psalm-stubs.stub` added
- **Current level**: PHPStan Level 6, Psalm Level 3 ✓

### Architecture & Scalability

**Configurable PKCE Storage for Admin** *(implemented)*

Admin PKCE code verifier is now stored exclusively in shared cache (Redis/file) via `storePkceVerifier()` → `oidc_admin_pkce_nonce` cookie. The legacy `pkce_code_verifier` DB column and Path C fallback in `ReadAuthorizationResponse` have been removed. Run `bin/magento setup:upgrade` to drop the column from `m2oidc_oauth_client_apps`.

### Developer Experience

**Better Error Messages** *(implemented)*
- `Helper/OAuthMessages.php` centralizes all user-facing messages
- `CUSTOMER_GROUP_MAPPING_NO_MATCH` — lists the OIDC groups that failed to map, tells admin where to configure
- `MISSING_ATTRIBUTES_DETAIL` — lists received claims and missing attribute names so admins can fix mapping
- `AdminUserCreator` no-role path now uses `ADMIN_ROLE_MAPPING_NO_MATCH` with the actual group list

---

### Remaining Items — not yet implemented

The following are genuine gaps in the current implementation, ordered roughly by impact.

#### Claims Transformation DSL

**Problem**: Attribute mappings are strictly 1:1 (OIDC claim key → Magento field). There is no built-in way to transform values — e.g., concatenate `given_name` + `family_name` into `firstname`, split a single `name` claim, prefix a username, or conditionally map a field based on another claim's value.

**Current workaround**: Configure transformations at the IdP level before claims leave the IdP, or write a Magento observer on `oidc_after_attribute_mapping` that mutates the `mapped_attrs` DataObject.

**Future approach**: A small expression language (or a predefined set of transform functions — `concat`, `split`, `prefix`, `regex_replace`) defined per attribute row in `m2oidc_oauth_attribute_mappings`. The `CustomerAttributeMapper` and `AdminAttributeMapper` would apply transforms after claim extraction and before Magento field assignment.

---

#### OIDC Front-Channel Logout

**Problem**: `BackChannelLogout` handles server-to-server logout (the IdP POSTs a JWT to Magento). Front-channel logout — where the IdP embeds an `<iframe>` pointing to each SP's logout URL in the user's browser — is not implemented. Some IdPs (Microsoft ENTRA, some Keycloak configurations) use front-channel-only logout.

**Scope**: Add a `GET /m2oidc/actions/frontchannellogout?sid=<sid>` endpoint that looks up the PHP session via `OidcSessionRegistry`, destroys it, and returns a transparent `1x1` response. Register it with the IdP as the front-channel logout URI.

**Effort**: Low (single controller, reuses `OidcSessionRegistry` and `BackChannelLogout` session-destruction logic).

---

#### Token Introspection (RFC 7662)

**Problem**: The module trusts the access token's local expiry timestamp (stored in session) and relies on the refresh flow to detect revocation. If the IdP revokes a token between refresh cycles, Magento continues to treat the session as valid until the next refresh attempt (up to 60 seconds before expiry).

**Scope**: Add optional RFC 7662 introspection calls in `TokenRefreshService::refreshIfNeeded()` — if an `introspection_endpoint` is configured on the provider, call it before deciding whether to refresh. If the response is `{"active": false}`, destroy the session immediately.

**Trade-off**: One extra HTTP round-trip per refresh cycle. Gate behind a per-provider `use_token_introspection` toggle.

---

#### Customer Account Self-Service: Link / Unlink IdP

**Problem**: There is no UI for a logged-in customer to view or change which OIDC provider is bound to their account. The only way to re-bind is direct DB manipulation of `m2oidc_oauth_user_provider` or individual record deletion from the Sessions admin UI.

**Scope**: A customer account section (e.g., under **My Account > Login & Security**) that shows the bound provider name and a button to unlink it. Unlinking deletes the row from `m2oidc_oauth_user_provider`, allowing the next OIDC login to claim a new binding.

**Risk**: Any customer who unlinks can re-bind to a different IdP on next login. Gate the unlink action behind a re-authentication prompt or admin-only enablement toggle.

---

#### Per-Provider Log Isolation

**Problem**: All providers write to a single `var/log/M2Oidc.log`. In a multi-provider setup (e.g., one provider for admins, one for B2B customers, one for B2C customers), it is difficult to isolate events for a specific provider without grepping on `provider_id`.

**Scope**: Add an optional per-provider `log_file_suffix` column. When set, `OidcLogger` writes to `var/log/M2Oidc_<suffix>.log` for that provider. `LogCleanup` cron should be extended to rotate all matching files. The single shared log remains the default.

---

#### Config Import / Export — Normalized Table Support

**Problem**: `Console/ExportOidcConfig.php` and `ImportOidcConfig.php` serialize the `m2oidc_oauth_client_apps` table. They do not include rows from the Phase 4 normalized tables (`m2oidc_oauth_attribute_mappings`, `m2oidc_oauth_role_mappings`). Migrating a fully configured provider across environments therefore requires additional manual DB export.

**Scope**: Extend both CLI commands to JOIN and include the normalized mapping rows, keyed by `provider_id`. On import, re-insert them after resolving the new `provider_id` (which may differ between environments). This is a pure data-pipeline change with no auth-flow impact.

---

#### Headless / PWA Token-Based Flow

**Problem**: The entire auth flow is redirect-based. A headless storefront (React, Vue, Next.js) using Magento's GraphQL API cannot follow server-side redirects. The GraphQL resolvers (`OidcLoginUrl`, `OidcProviders`) return the SSO URL, but the redirect and nonce-cookie handoff are browser-navigation steps that don't work cleanly in a PWA context.

**Scope**: A stateless, cookie-free variant of the customer auth flow:
1. Frontend fetches the SSO URL from GraphQL.
2. Opens a popup window (or redirect) to the IdP.
3. After IdP callback, the module issues a short-lived Magento customer token (via `CustomerTokenServiceInterface`) instead of setting a session cookie.
4. Token returned as a query parameter to the popup's `postMessage` or redirect destination.

**Complexity**: High. Requires careful CSRF handling without relying on the PHP session for state storage. Consider scoping to a new controller area (`headless`) with explicit CORS headers and a DI-toggleable flow.

---

#### OIDC Proof-of-Possession / DPoP (RFC 9449)

**Problem**: Standard Bearer tokens (`Authorization: Bearer <token>`) are vulnerable if the token is intercepted. DPoP binds tokens to a client-held private key so a stolen token cannot be replayed from a different client.

**Scope**: Add optional DPoP proof generation in `AccessTokenRequest` and `Curl::callAPI()`. `JwtVerifier` would also need to accept a `dpop_jkt` binding in the access token's confirmation claim. Gate behind a per-provider `dpop_enabled` toggle. This is an advanced hardening measure relevant mainly to deployments handling highly sensitive data.

**Effort**: Medium-high. Requires asymmetric key generation/storage on the Magento side and IdP support (Keycloak 21+, Zitadel support is partial as of 2026).

---

#### Admin UI: Phase 4 Attribute Mapping Rows

**Problem**: The Phase 4 normalized attribute mapping table (`m2oidc_oauth_attribute_mappings`) is read by the PHP layer but the provider edit form may still render legacy single-input fields for attribute mappings rather than dynamic, per-row UI with `sync_on_sso` toggles visible per attribute. Verify whether the current admin templates expose `sync_on_sso` for each attribute row — if they use legacy single inputs, the Phase 4 schema is being written but the per-attribute control isn't surfaced to admins.

**Scope**: Provider edit form → Attribute Mapping tab: replace single `<input>` fields with a dynamic row component (similar to the existing role-mapping dynamic rows) that shows the claim key, the Magento field, and the `sync_on_sso` checkbox per attribute. Store changes to `m2oidc_oauth_attribute_mappings` on save.

---

#### Alerting / Health Monitoring Integration

**Problem**: The `GET /m2oidc/health/check` endpoint tests IdP reachability and returns JSON, but there is no built-in mechanism to push alerts when it fails — e.g., if the IdP goes down between Magento cron runs. The `m2oidc_refresh_oidc_discovery` cron silently skips failed fetches without alerting.

**Scope**: Add a configurable webhook URL (`health_alert_webhook`) per provider. When the health check or discovery refresh fails N consecutive times (configurable), POST a JSON payload to the webhook. This integrates with Slack, PagerDuty, or any HTTP-based alerting system without requiring a separate monitoring agent.

**Effort**: Low. The HTTP client (`Curl`) is already available; the failure-counting logic mirrors the JWKS circuit-breaker pattern.

---

#### SAML 2.0 Support

**Out of scope for this module** — SAML is a fundamentally different protocol and would require a separate module. If SAML support is needed, consider a dedicated `M2Oidc_Saml` module that reuses the JIT provisioning services (`AdminUserCreator`, `CustomerUserCreator`, `UserProvisioningService`) via DI injection but implements its own assertion parsing and session handling.
