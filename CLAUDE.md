# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Magento 2 module that provides OAuth/OIDC authentication for both customer (frontend) and admin (backend) users. The module is registered as `M2Oidc_OAuth` and supports automatic admin and customer login after successful OIDC authentication, multi-provider configuration, RP-Initiated Logout, back-channel and front-channel logout, headless/PWA login, claims-based access control, per-attribute claim transformers, CLI config export/import, and Zitadel-specific Base64-encoded claims with nested role normalization.

Deeper protocol-level and historical notes live under `Docs/` (`TECHNICAL_DOCUMENTATION.md`, `Dev-Doc.md`, `Code-Review.md`, `Code-Review-Implementation.md`, `zitadel-m2oidc-setup.md`) — this file stays a map of the codebase; consult those for full protocol detail.

## Magento 2 Development Commands

### Module Management
```bash
# Enable the module
php bin/magento module:enable M2Oidc_OAuth

# Disable the module
php bin/magento module:disable M2Oidc_OAuth

# Run setup upgrade (after code changes)
php bin/magento setup:upgrade

# Compile dependency injection (after adding new classes or changing constructors)
php bin/magento setup:di:compile

# Deploy static content (after changing templates or static files)
php bin/magento setup:static-content:deploy -f

# Clear cache
php bin/magento cache:clean
php bin/magento cache:flush
```

### Database Schema
```bash
# Apply database schema changes (after modifying etc/db_schema.xml)
php bin/magento setup:db-schema:upgrade

# Validate schema declarations
php bin/magento setup:db-declaration:generate-whitelist
```

### Debugging
```bash
# View logs (the module uses custom logging)
tail -f var/log/M2Oidc.log
tail -f var/log/system.log
tail -f var/log/exception.log

# Check module status
php bin/magento module:status M2Oidc_OAuth
```

### Provider Config Export/Import (FEAT-07)
```bash
# Export one or all provider configurations to JSON (client_secret Magento-encrypted)
php bin/magento oidc:config:export [--provider-id=<id>] [--output=<file>]

# Import provider configurations from JSON (skips existing app_name by default)
php bin/magento oidc:config:import --input=<file> [--dry-run] [--overwrite]
```
Implemented in `Console/Command/ExportOidcConfig.php` / `ImportOidcConfig.php`.

Log rotation runs daily via `m2oidc_log_rotation` cron at 03:00. The log is deleted when it exceeds 7 days or when debug logging is disabled.

OIDC discovery documents are refreshed automatically every 6 hours via `m2oidc_refresh_oidc_discovery` (`Cron/RefreshOidcDiscovery.php`).

## Architecture

### Authentication Flow

The module implements a dual authentication flow for admin and customer users:

1. **Customer Flow** (Frontend):
   - Route: `m2oidc` (defined in etc/frontend/routes.xml)
   - Entry: `SendAuthorizationRequest` → Redirects to OIDC provider with PKCE + state
   - Callback: `ReadAuthorizationResponse` → Validates state/nonce, exchanges code for token, verifies JWT, extracts/flattens OIDC attributes via `OidcAuthenticationService`
   - Attribute Mapping: `CheckAttributeMappingAction` → Maps claims, evaluates access control rules
   - User Management: `ProcessUserAction` → Creates or updates Magento customer via `CustomerUserCreator`
   - **Handoff**: `CustomerLoginAction` → Sets a one-time nonce cookie (`oidc_customer_nonce`, or `oidc_headless_nonce` in headless mode) and redirects to the dedicated callback
   - **Dedicated Login Callback**: `CustomerOidcCallback` → Validates ephemeral nonce cookie, calls `CustomerSession::setCustomerAsLoggedIn()` in a clean HTTP context; sets `oidc_customer_authenticated` cookie (1h duration). In headless/PWA mode (`headless_mode` column, FEAT-09) `HeadlessOidcCallback` is used instead — see step 8 below.

2. **Admin Flow** (Backend) - **Uses Native Magento Authentication:**
   - Route: `m2oidc` (defined in etc/adminhtml/routes.xml)
   - Same initial flow as customer through `CheckAttributeMappingAction`
   - **Critical difference**: Admin users are detected by checking if email exists in `admin_user` table
   - Admin users are redirected to `Oidccallback` controller in adminhtml area
   - **Native integration**: `Oidccallback` calls `Auth::login($email, $ephemeralToken)` with a single-use ephemeral token
   - `OidcCredentialPlugin` detects the ephemeral token and injects `OidcCredentialAdapter`
   - `OidcCredentialAdapter` authenticates the user without password verification (already done at IdP)
   - `OidcCaptchaBypassPlugin` skips CAPTCHA validation for OIDC auth
   - All standard Magento security events fire correctly

3. **RP-Initiated Logout (Admin)**:
   - `OidcLogoutPlugin` intercepts `Auth::logout()` via `aroundLogout`
   - Reads `oidc_id_token`, `oidc_access_token`, `oidc_provider_id` from session **before** session is destroyed
   - Calls original `logout()`, then deletes `oidc_authenticated` cookie
   - Sets `oidc_logout_guard` cookie (120 s TTL) to prevent auto-redirect loops
   - Revokes access token via RFC 7009 revocation endpoint (fire-and-forget)
   - Redirects to IdP `end_session_endpoint` with `id_token_hint`, `state=admin:<hex>`, and `post_logout_redirect_uri=https://site.com/m2oidc/actions/postlogout`
   - **Authelia detection**: If endpoint path ends with `/logout` (without `/oauth2/` or `/oidc/`), uses `?rd=<adminBaseUrl>` instead of standard params

4. **RP-Initiated Logout (Customer)**:
   - `OAuthLogoutObserver` is bound to the `controller_action_postdispatch_customer_account_logout` event (**not** `customer_logout` — that event is used only by `CustomerSetLogoutFlagObserver` to flag the session as logged out)
   - Reads id_token + provider_id from customer session
   - Performs token revocation and IdP redirect (same logic as admin)
   - Uses `state=customer:<hex>` and `post_logout_redirect_uri=https://site.com/m2oidc/actions/postlogout`
   - Sets `oidc_logout_guard` cookie (**300 s TTL** — longer than the admin flow's 120 s, to survive the IdP round-trip); `CustomerLoginAutoRedirectObserver` checks this to suppress re-login

5. **Unified Post Logout Callback** (`Postlogout` controller, `Controller/Actions/Postlogout.php`):
   - Route: `GET /m2oidc/actions/postlogout`
   - Reads `state` query param echoed back by IdP after logout
   - `state` starts with `admin:` → redirect to static admin login URL
   - `state` starts with `customer:` → redirect to `customer/account/login`
   - Absent/unknown state → redirect to store home (safe fallback)
   - Register this single URL with OIDC providers that only allow one Post Logout Redirect URI
   - Both logout paths also check a per-provider `post_logout_url` override before falling back to this unified URL — **note**: the code reads `$provider['post_logout_url']`, but no such column currently exists in `etc/db_schema.xml`, so this override is presently non-functional (schema/code mismatch, not just a doc issue)

6. **Back-Channel Logout** (FEAT-02):
   - Route: `POST /m2oidc/actions/backchannellogout`
   - `BackChannelLogout` controller implements `CsrfAwareActionInterface` (opts out of form-key CSRF)
   - IP-based rate limiting via `OidcRateLimiter`; returns HTTP 429 when limit exceeded
   - Decodes logout token without verification to extract `iss`; resolves matching provider
   - Verifies JWT signature via provider's JWKS endpoint
   - Validates `events` claim contains `http://schemas.openid.net/event/backchannel-logout`
   - Parses `aud` claim supporting both string and array formats
   - Resolves PHP session ID from `OidcSessionRegistry` via `sub`/`sid` claims
   - Destroys target session via the shared `SessionDestructionService` by temporarily switching session IDs (C-02)

7. **Front-Channel Logout** (`Controller/Actions/FrontChannelLogout.php`):
   - Route: `GET /m2oidc/actions/frontchannellogout?sid=<sid>`
   - For IdPs (Entra, some Keycloak configs) that perform front-channel logout via a hidden `<iframe>` per SP instead of a back-channel POST
   - Validates/sanitizes `sid`, looks up matching PHP session(s) via `OidcSessionRegistry`, destroys them via the same `SessionDestructionService` used by `BackChannelLogout` (C-02 pattern)
   - Rate-limited via `OidcRateLimiter` (same thresholds as `BackChannelLogout`)
   - Always returns a 1×1 transparent GIF (HTTP 200/400) — required so the IdP's iframe gets a valid image response
   - No client credentials/JWT required from the IdP; register `https://<store>/m2oidc/actions/frontchannellogout` as the Front-Channel Logout URI

8. **Headless / PWA Login** (`Controller/Actions/HeadlessOidcCallback.php`, FEAT-09):
   - Enabled per-provider via the `headless_mode` column
   - Reached from `CustomerLoginAction` when headless mode is active; validates the one-time `oidc_headless_nonce` cookie (atomic cache read-and-delete)
   - Issues a Magento customer token and returns an HTML page that posts it to the opener window via `window.postMessage` (target restricted to the store's base URL origin), then closes the popup
   - No session cookie is created — the token is the sole credential, suited to PWA/headless storefronts

### Key Components

#### Controllers (Controller/Actions/)
- `BaseAction.php` / `BaseAdminAction.php`: Base classes for OAuth actions
- `SendAuthorizationRequest.php`: Initiates OAuth flow; generates PKCE challenge (S256/PLAIN), encodes relay state as `{r, s, a, l, t, p}` JSON+Base64, supports multi-provider via `provider_id` param
- `ReadAuthorizationResponse.php`: Handles OAuth callback; validates state token, consumes PKCE verifier, verifies JWT, applies rate limiting via `OidcRateLimiter`, stores id_token in transport cookie (2-min TTL); validates nonce in `id_token` from the token response even when user data comes from the userinfo endpoint (M-06: prevents replay attacks in hybrid flows); also owns the legacy pipe-delimited relay-state fallback (tries `OAuthSecurityHelper::decodeRelayState()` first, falls back to `explode('|', ...)` parsing); builds an `OidcAttributeMappingContext` DTO once per request and calls `$this->attrMappingAction->handle($context)`; also owns the `resolveErrorLoginUrl(string $loginType, string $encodedError): string` helper that dedupes the login-type-branching error-URL logic
- `CheckAttributeMappingAction.php`: Routes users based on admin/customer detection; evaluates claims-based access control rules (FEAT-04); handles admin/customer auto-creation; enforces per-user IdP binding for admin logins (rejects if bound to a different provider, claims binding on first OIDC login of a pre-existing account); sets ephemeral nonce cookies for secure callback handoff; delegates OIDC response parsing to `OidcAuthenticationService`. **Not routed/dispatched directly** — extends `BaseAction`/`Magento\Framework\App\Action\Action`, so it still carries the zero-arg `execute(): ResultInterface` required by `ActionInterface`, but that override is now a dead stub that unconditionally throws `\LogicException`; the real entry point is `handle(OidcAttributeMappingContext $context): ResultInterface`, called by `ReadAuthorizationResponse`. Builds an `OidcUserProvisioningContext` DTO and calls `$this->processUserAction->handle($context)`.
- `ProcessUserAction.php`: Creates or updates Magento customers via `CustomerUserCreator`; enforces per-user IdP binding for customer logins (rejects if bound to a different provider, claims binding on first OIDC login of a pre-existing account); calls `CustomerProfileSyncService::syncProfile()` and `syncAddress()` on login. A plain DI-injected collaborator class (does **not** extend any Magento action base class or implement `ActionInterface`), so unlike `CheckAttributeMappingAction` it has no `execute()` at all — just `handle(OidcUserProvisioningContext $context): Result\Redirect`, called by `CheckAttributeMappingAction`.
- `CustomerLoginAction.php`: **Actively used on every customer login** — sets the one-time nonce cookie (`oidc_customer_nonce`, or `oidc_headless_nonce` in headless mode) and redirects to `CustomerOidcCallback` or `HeadlessOidcCallback`
- `CustomerOidcCallback.php`: Customer login in clean HTTP context; validates `oidc_customer_nonce` cookie via `OAuthSecurityHelper::redeemCustomerLoginNonce()`; enforces website context (SEC-08); sets `oidc_customer_authenticated` cookie
- `HeadlessOidcCallback.php`: Headless/PWA login callback (FEAT-09); validates `oidc_headless_nonce` cookie, issues a Magento customer token, and posts it to the opener window via `window.postMessage` (origin-restricted) instead of setting a session cookie; used when the provider's `headless_mode` flag is set
- `IdpInitiatedLogin.php`: IdP-Initiated SSO entry point (OIDC Third-Party Initiated Login §4); URL: `https://<store>/m2oidc/actions/idpInitiatedLogin?provider_id=<id>`; optional params: `relay_state`, `login_hint`, `login_type`; enforces `is_active` and `idp_initiated_enabled` checks, rate limiting, CSRF state token, PKCE
- `Postlogout.php` (class `Postlogout`): Unified post-logout redirect handler; URL: `https://<store>/m2oidc/actions/postlogout`; reads `state` param from IdP redirect, routes to admin login or customer login based on `admin:`/`customer:` prefix; use when IdP allows only one Post Logout Redirect URI
- `ShowTestResults.php`: Displays test results for attribute mapping; stores received OIDC claims in `received_oidc_claims` column
- `BackChannelLogout.php`: OIDC Back-Channel Logout (FEAT-02); POST endpoint for IdP server-side logout; IP-based rate limiting via `OidcRateLimiter` (returns HTTP 429 on exceeded limit); supports both string and array formats for the `aud` claim; destroys sessions via the shared `SessionDestructionService`
- `FrontChannelLogout.php`: OIDC Front-Channel Logout; `GET` endpoint for IdP iframe-based logout (Entra, some Keycloak configs); looks up sessions via `OidcSessionRegistry` and destroys them via the shared `SessionDestructionService`; always returns a 1×1 GIF
- `Controller/Health/Check.php`: Unauthenticated health check; checks per-provider config completeness (`clientID`, `access_token_endpoint`) and `is_active` — does not make outbound HTTP calls to the IdP

#### Console Commands (Console/Command/)
- `ExportOidcConfig.php`: `bin/magento oidc:config:export [--provider-id=<id>] [--output=<file>]` (FEAT-07); exports one or all provider rows to JSON; `client_secret` is Magento-encrypted in the output (`--no-encrypt` for testing only); `EXCLUDED_FIELDS = ['received_oidc_claims', 'last_test_status', 'last_test_at']` are stripped from every exported row (M31 — these previously leaked internal claim-key names and test-run metadata)
- `ImportOidcConfig.php`: `bin/magento oidc:config:import --input=<file> [--dry-run] [--overwrite]` (FEAT-07); providers with an existing `app_name` are skipped unless `--overwrite`; plaintext `client_secret` values are encrypted on import, already-encrypted values are stored as-is; imported data is now run through the shared `ProviderDataValidator` before saving (C3 — enum whitelisting, SSRF-safe endpoint checks, lockout-prevention guard), matching what the manual Provider Save form already enforced

#### Setup (Setup/Patch/Data/)
- `EncryptPlaintextClientSecrets.php`: The module's first data patch. Runs on `setup:upgrade`; encrypts any stored `client_secret` value that doesn't match the Magento-encrypted-value pattern `/^\d+:\d+:/` (i.e. legacy plaintext secrets saved before C2 was fixed), and backfills empty `login_type` to `'both'` (matching the read-side semantics — see `OidcProviderRepository::getAllActiveProviders()` below). Idempotent per `DataPatchInterface` contract; logs counts of rows touched.

#### Cron Jobs (Cron/)
- `LogCleanup.php`: Active daily cron job registered as `m2oidc_log_rotation` (runs at 03:00 server time); deletes `var/log/M2Oidc.log` and disables logging when the log file is older than 7 days or when debug logging has been disabled in the admin UI; `crontab.xml` uses this class
- `RefreshOidcDiscovery.php`: Cron job registered as `m2oidc_refresh_oidc_discovery` (runs every 6 hours, `0 */6 * * *`); re-fetches `.well-known/openid-configuration` for every active provider with a `well_known_config_url`; dirty-checks each field before writing so unchanged values do not trigger unnecessary DB writes; discovery URLs are now validated by the shared `Model/Validation/SsrfUrlValidator` before fetch (H9 — previously scheme-only, so an internal/loopback discovery URL written via any path, including import, would be fetched server-side unattended every 6 hours)

#### Admin Controllers (Controller/Adminhtml/)
- All four settings pages below (`Providersettings`, `OAuthsettings`, `Attrsettings`, `Signinsettings`) declare `public const ADMIN_RESOURCE` matching their `etc/acl.xml` entry (`M2Oidc_OAuth::provider_settings` / `::oauth_settings` / `::attr_settings` / `::signin_settings`) instead of hand-building the resource string inside `_isAllowed()` (C1/M30 — the old hand-built concatenation on `Providersettings/Index.php` was malformed and threw for every user on page load; all four are now consistent with the `ADMIN_RESOURCE` pattern already used by `Provider/*`, `Sessions/*`, and `Actions/HealthCheck.php`).
- `Actions/Oidccallback.php`: Admin callback that performs native Magento login via `Auth::login()` with ephemeral token; persists id_token in admin session
- `Actions/SendAuthorizationRequest.php`: Admin-initiated OAuth flow; PKCE verifier is stored in the **atomic cache** (same `AtomicCacheInterface` mechanism as the customer flow), keyed by a nonce carried in the `oidc_admin_pkce_nonce` cookie — **not** in the database (no `pkce_code_verifier` column exists)
- `Attrsettings/Index.php`: Saves attribute mapping configuration including admin role mappings as JSON
- `OAuthsettings/Index.php`: OAuth Settings admin page (client credentials, endpoints, PKCE/claim-encoding flags); `client_secret` is now encrypted via injected `EncryptorInterface` before `setData()` (C2 — this page previously stored the secret in plaintext); endpoint URLs on this page are validated via `SsrfUrlValidator` before persisting
- `Providersettings/Index.php`: Provider Settings admin page; edits provider identity fields — `display_name`, `login_type`, `is_active`, `sort_order`, `button_label`, `button_color`; operates only in provider-context mode (`provider_id` required in the URL)
- `Signinsettings/Index.php`: Sign In Settings admin page (auto-redirect, login restriction, claims access control rules); its config-import path (`handleImportConfig()`) and export path (`handleExportConfig()`) now run through `ProviderDataValidator` (import) and the same `EXPORT_EXCLUDED_FIELDS` exclusion set as the CLI export (export) — see C3/M31 above
- `Provider/Index.php`, `Provider/Edit.php`, `Provider/Save.php`, `Provider/Delete.php`: Multi-provider management grid and CRUD
- `Provider/UnlinkUser.php`: `POST /admin/m2oidc/provider/unlinkuser` (`user_type`, `user_id`); unlinks an OIDC provider binding from a customer or admin user; requires admin resource `M2Oidc_OAuth::oidc_sessions`
- `Sessions/Index.php`: Admin UI listing of active OIDC sessions via `SessionDataProvider`
- `Sessions/Delete.php`: POST handler for deleting individual session activity records; validates `id` param, calls `UserProviderResource::deleteById()`; requires admin resource `M2Oidc_OAuth::oidc_sessions`
- `Adminhtml/Actions/HealthCheck.php`: Admin health check with configuration diagnostics

#### Authentication Integration (Model/Auth/)
- `OidcCredentialAdapter.php`: Implements `StorageInterface` to bridge OIDC with Magento's native auth
  - Validates ephemeral auth token (single-use, 300s TTL from cache) — never checks password
  - Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth` marker
  - **PHP 8 serialization**: uses `__serialize()` / `__unserialize()` (replaces `__sleep()`/`__wakeup()`); `__unserialize()` eagerly calls `restoreDependencies()` and reloads User from DB — no more lazy `ObjectManager::getInstance()`
  - Proxies User model methods via `__call()` magic method

#### Plugins (Plugin/)
- `Auth/OidcCredentialPlugin.php`: Intercepts `Auth::getCredentialStorage()` to inject OIDC adapter; detects ephemeral token format (non-consuming); unconditionally clears OIDC flag in `afterLogin()` (SEC-06: guards against stale state in recycled PHP-FPM workers)
- `Auth/OidcLogoutPlugin.php`: `aroundLogout` on `Magento\Backend\Model\Auth`; orchestrates RP-Initiated Logout; reads session **before** `proceed()`; handles Authelia forward-auth detection; calls RFC 7009 revocation
- `AdminLoginRestrictionPlugin.php`: `beforeLogin` on `Magento\Backend\Model\Auth`; blocks non-OIDC logins when `m2oidc_disable_non_oidc_admin_login` is set; safety net allows normal login if no OIDC button is shown (prevents lockout)
- `CustomerLoginRestrictionPlugin.php`: Blocks non-OIDC customer logins when configured; analogous to admin restriction
- `Captcha/OidcCaptchaBypassPlugin.php`: Bypasses CAPTCHA for OIDC-authenticated admin users
- `Captcha/CustomerCaptchaBypassPlugin.php`: Bypasses CAPTCHA for OIDC-authenticated customer logins
- `User/OidcPasswordExpirationPlugin.php`: Suppresses password expiration warnings for OIDC users
- `User/OidcForcePasswordChangePlugin.php`: Suppresses forced password change redirect for OIDC users
- `User/OidcIdentityVerificationPlugin.php`: Bypasses identity verification prompts for OIDC admin users
- `User/Block/OidcIdentityFieldPlugin.php`: Hides identity verification form field for OIDC users
- `User/Block/OidcUserInfoPlugin.php`: Injects OIDC info block into admin user profile page
- `Customer/Block/OidcInfoPlugin.php`: Injects OIDC info block into customer account page
- `Csp/OidcCspPolicyCollector.php`: Adds IdP domains to Content Security Policy whitelist dynamically
- `User/AdminUserDeletePlugin.php`: `afterDelete` on `Magento\User\Model\User`; removes the matching row from `m2oidc_oauth_user_provider` after admin user deletion; works alongside `AdminUserDeleteObserver` for belt-and-suspenders reliability

#### Helpers (Helper/)
- `OAuthUtility.php`: **Thin facade** — delegates logging to `OidcLogger` (public readonly `$oidcLogger`), provider resolution to `ProviderResolver` (public readonly `$providerResolver`), config reads to `OidcConfigReader` (public readonly `$configReader`), and DB ops to `OidcProviderRepository` (inherited from `Data`, including the three write methods it previously overrode with a diverged raw-SQL copy — H11). All live-caller public methods retained; behavior unchanged for callers. Eight confirmed-dead public methods were removed (H8 dead-code table) — see "Completed Architectural Improvements" below. `isBlank()` no longer treats the literal string `"0"` as blank (L38).
- `OAuthSecurityHelper.php`: Security primitives — PKCE generation/verification (S256/PLAIN), state token create/validate/consume (one-time use), relay state encode/decode (JSON+Base64 — the legacy pipe-delimited fallback lives in `ReadAuthorizationResponse.php`, not here), OIDC nonce store/consume, **ephemeral admin login tokens** (C-01: `createOidcAuthToken()` / `validateAndConsumeOidcAuthToken()` with **300s (5-minute) cache TTL**), **customer login nonces** (`createCustomerLoginNonce()` / `redeemCustomerLoginNonce()`, **300s TTL**), redirect URL validation (same-origin check; also rejects null bytes and backslashes as bypass vectors). **All one-time token storage/consumption now uses `AtomicCacheInterface::save()` / `getAndDelete()`** — eliminates TOCTOU race between separate load+remove calls.
- `JwtVerifier.php`: Fetches JWKS (cached with configurable per-provider TTL via `jwks_cache_ttl` column, default 86400 s, read via `OAuthConstants::JWKS_CACHE_TTL`); verifies JWT signature; validates issuer/audience/nonce; logs WARNING when nonce validation is skipped (null `expectedNonce`); circuit-breaker: opens a 60 s `m2oidc_jwks_fail_*` cache flag after a failed JWKS re-fetch to prevent hammering an unavailable IdP
- `Data.php`: Base config data access; all DB operations on `m2oidc_oauth_client_apps` delegated to `OidcProviderRepository`; ~100 lines of inline DB logic removed
- `SessionHelper.php`: Cross-origin SSO cookie helpers (SameSite=None); `updateSessionCookies()` re-sets cookies for cross-origin flows
- `OAuthConstants.php`: Constants for config paths and defaults
- `OAuthMessages.php`: Centralized user-facing messages
- `Data.php`: Data access layer for configuration
- `Curl.php`: HTTP client wrapper for token endpoint calls
- `TestResults.php`: Test configuration helpers
- `OAuth/AuthorizationRequest.php`, `OAuth/AccessTokenRequest.php`, `OAuth/AccessTokenRequestBody.php`: OAuth protocol request builders; `AccessTokenRequest` supports PKCE via optional `$codeVerifier` parameter and public clients (omits `client_secret` when empty, per RFC 6749 §2.1)
- `Exception/`: Custom exception types (RequiredFields, MissingAttributes, IncorrectUserInfo, etc.)

#### Models & Services

**Atomic Cache (Model/Cache/):**
- `AtomicCacheInterface.php`: `save(string $identifier, string $value, int $ttl): void` / `getAndDelete(string $key): ?string` — atomic write and read-and-delete; default preference is `RedisAtomicCache`
- `FileAtomicCache.php`: Sequential load+remove; safe for single-server deployments
- `RedisAtomicCache.php`: Opens its own dedicated Redis connection via `RedisConnectionFactory` (env.php `cache/frontend/default/backend_options`), independent of Magento's cache backend/frontend class; uses `GETDEL`/Lua for true atomicity, falls back to `FileAtomicCache`-style load+remove when that connection is unavailable
- `RedisConnectionFactory.php`: Builds/memoizes a raw phpredis connection from Magento's cache config; returns `null` on failure

**Config Reader (Model/Config/):**
- `OidcConfigReader.php`: Maps `OAuthConstants` keys to `m2oidc_oauth_client_apps` column names (~50 entries in `CONFIG_TO_COLUMN` — check the array directly for the current count, it grows with each new column); `getStoreConfig(string $key)` reads from active provider row (provider-specific) or `core_config_data` (global)

**Attribute Mapper Pool (Model/Attribute/):**
- `MapperPool.php`: DI registry for per-provider mapper overrides; resolution: `{providerId}_{type}` → `default_{type}`. Third-party modules inject custom mappers via `etc/di.xml`. Both `AdminUserCreator` and `CustomerUserCreator` resolve mappers via `resolveMapper(int $providerId)` before falling back to the default injected mapper.

**Provider Resolver (Model/Provider/):**
- `ProviderResolver.php`: Per-request provider context; `setActiveProviderId()` (guards against caching `0`/negative IDs as "active" — `<= 0` is a no-op) / `getActiveProviderId()` / `resolveActiveProvider()`; single in-memory cache per instance (at most one DB query per request). `resolveActiveProvider()`'s no-explicit-ID fallback now delegates directly to `OidcProviderRepository::getAllActiveProviders()` — the class no longer reimplements provider listing itself (previously a divergent, decryption-skipping copy; H10, fixed).

**Provider Repository (Model/ResourceModel/):**
- `OidcProviderRepository.php`: All DB ops on `m2oidc_oauth_client_apps`; auto-decrypts `client_secret` via a private `decryptSecretWithLogging()` helper that logs a WARNING when a non-empty ciphertext decrypts to an empty string (M22); extracted from `Helper/Data`. `getAllActiveProviders(string $loginType)` treats a stored `login_type=''` as matching any requested type (same as `'both'`) so legacy rows stay visible. Key methods: `getOAuthClientApps()`, `getClientDetailsByAppName()`, `getClientDetailsById()`, `getAllActiveProviders()`, `saveTestStatus()`, `saveTestStatusById()`, `saveReceivedOidcClaims()`

**Validation (Model/Validation/):**
- `SsrfUrlValidator.php`: Shared loopback/RFC-1918 private-host blocking for admin-configured endpoint URLs; used by `Provider/Save.php` discovery, `OAuthsettings/Index.php`, `Cron/RefreshOidcDiscovery.php`, and `ProviderDataValidator` — previously duplicated (and, in the cron, weaker — scheme-only) across three call sites.
- `ProviderDataValidator.php` + `ProviderValidationResult.php`: Shared provider-data validation — whitelists `login_type`/`claim_encoding`/`pkce_flow` enum values, runs `SsrfUrlValidator` against endpoint URLs, and applies the lockout-prevention auto-revert of `m2oidc_disable_non_oidc_*_login` via `UserProvider::countByTypeAndProvider()`. Used by `Provider/Save.php`, `Console/Command/ImportOidcConfig.php`, and `Signinsettings/Index.php`'s import path — closes the gap where CLI/admin-UI config import bypassed all of the manual-save validation (C3).

**Services (Model/Service/):**
- `AdminUserCreator.php`: Creates admin users during OIDC auth; resolves attribute mapper via `MapperPool::getMapper($providerId, 'admin')` first (falls back to injected mapper); `getAdminRoleFromGroups()` delegates to `GroupMappingResolver` for the normalized-table → legacy-JSON → case-insensitive-match → default → deny fallback chain; generates the JIT password via `RandomPasswordGenerator`
- `AdminProfileSyncService.php`: Syncs admin profile attributes and role from OIDC claims on every login; called by `CheckAttributeMappingAction` when `sync_admin_profile_on_sso` / `sync_admin_role_on_sso` flags are set; role resolution also delegates to `GroupMappingResolver`. **Per-attribute `sync_on_sso` guard**: `shouldSync(array $attrMap, string $attributeType): bool` skips individual attributes where the normalized mapping row exists but `sync_on_sso = 0`; legacy mode (no normalized row) always syncs.
- `AdminTokenRefreshService.php`: Manages access-token lifecycle for the admin `AuthSession`; session keys: `oidc_access_token`, `oidc_access_token_expires`, `oidc_refresh_token`; `refreshIfNeeded()` refreshes 60s before expiry; `storeTokens()` called by `Oidccallback` via `ReadAuthorizationResponse` admin cookie transport. Now a thin subclass of `AbstractTokenRefreshService`, providing only the admin session accessor and log context (M23 — the ~90%-duplicated body moved to the abstract base).
- `AbstractTokenRefreshService.php`: Shared token-refresh base class (M23) — holds the actual `refreshIfNeeded()`/`refresh()`/`storeTokens()`/`sendRefreshRequest()` logic; `TokenRefreshService` (customer) and `AdminTokenRefreshService` (admin) extend it, each supplying only its session accessor + log context, keeping their original class names and public constants.
- `CustomerUserCreator.php`: Creates/updates customers; resolves attribute mapper via `MapperPool::getMapper($providerId, 'customer')` first (falls back to injected `CustomerAttributeMapper`); supports DOB, gender, billing address; customer group resolution via `GroupMappingResolver`; can deny creation if group not mapped (`m2oidc_dont_create_customer_if_group_not_mapped`); profile sync on SSO login; attribute-lookup initialization is a loop over a config map (M24); generates the JIT password via `RandomPasswordGenerator` (M25)
- `CustomerProfileSyncService.php`: Syncs customer profile fields (`syncProfile()`) and billing/shipping address fields (`syncAddress()`) from OIDC claims on every login; called by `ProcessUserAction`. **Per-attribute `sync_on_sso` guard** same as `AdminProfileSyncService` — `shouldSync()` respects per-row flags in `m2oidc_oauth_attribute_mappings`. Gender/country resolution now delegates to the shared `Model/Attribute/GenderMapper` and `CountryResolver` (previously a diverged copy of `CustomerAttributeMapper`'s logic — M19, fixed a live bug where German gender claims stopped re-syncing).
- `GroupMappingResolver.php`: Shared group/role mapping fallback-chain logic (normalized `m2oidc_oauth_role_mappings` table → legacy JSON column → case-insensitive match → configured default → deny); used by `AdminUserCreator`, `CustomerUserCreator`, and `AdminProfileSyncService` (M18 — previously ~60 lines duplicated three times).
- `RandomPasswordGenerator.php`: Shared random-password generation (32 chars: 28 alphanumeric + 2 special + 2 digit, shuffled) for JIT-provisioned admin/customer accounts, with named constants replacing the magic numbers previously duplicated in both creators (M25).
- `RpInitiatedLogoutService.php`: Shared RP-Initiated Logout logic — session-agnostic: `isAutheliaForwardAuthLogout()`, `resolvePostLogoutRedirectUri()` (validates the per-provider `post_logout_url` override with `FILTER_VALIDATE_URL` on **both** admin and customer paths — closes a validation gap that previously existed only on the customer side), `buildLogoutUrl()`, `revokeToken()` (RFC 7009, fire-and-forget). Used by both `Plugin/Auth/OidcLogoutPlugin.php` (admin) and `Observer/OAuthLogoutObserver.php` (customer); each caller keeps its own guard-cookie TTL (120s / 300s) and session source (M28/M29 — previously ~90% duplicated across the two classes).
- `UserProvisioningService.php`: Orchestrates admin/customer creation; fires four distinct events — `oidc_admin_user_before_create`, `oidc_admin_user_after_create`, `oidc_customer_before_create`, `oidc_customer_after_create` (not the generic `oidc_before_user_create`/`oidc_after_user_create` names used elsewhere in older docs — those do not exist in code); tracks provider ID
- `SessionDestructionService.php`: Shared session-destruction logic (C-02 pattern) extracted so `BackChannelLogout` and `FrontChannelLogout` can both destroy a target PHP session by temporarily switching session IDs without duplicating the logic
- `OidcAuthenticationService.php`: Core service for OIDC response processing; called by `CheckAttributeMappingAction`
  - `validateUserInfo()`: Validates OAuth provider response for errors or empty data
  - `flattenAttributes()`: Recursively flattens nested OIDC claims into dot-notation keys; supports Base64-decoded values (Zitadel `claim_encoding=base64`); depth limit: `MAX_RECURSION_DEPTH = 5`; also enforces `MAX_FLATTENED_KEYS = 2000` — a login is rejected (`IncorrectUserInfoDataException`) if a pathological/malicious response would flatten past that many keys (M21); the `claim_encoding` config lookup is now hoisted out of the recursive calls
  - `extractEmail()`: Extracts email from flattened attrs with fallback recursive search
  - `extractLoginType()`: Determines admin vs customer login context
  - `normalizeGroups()`: Handles 3 formats — plain string, flat array, Zitadel nested object (`{"role_name": {"orgId": ...}}`)
  - `normalizeZitadelRoleClaimsForDisplay()`: Reconstructs parent role keys from Zitadel's flattened subkeys for UI display
  - `reconstructNestedGroupClaim()`: Synthesizes parent group keys from dot-notation subkeys
  - `findEmailRecursive()`: Recursively searches raw response for any valid email address
- `OidcSessionRegistry.php`: Tracks active OIDC sessions (sub/sid → PHP session ID mapping); used by `BackChannelLogout` and `FrontChannelLogout` for session destruction. `buildKey()` hashes `sub` and `sid` **independently** before combining (`hash('sha256', hash('sha256', $sub) . hash('sha256', $sid))`) to close a theoretical cross-pair collision (M16). Clean-cut change with no legacy-key migration — **sessions registered before this change was deployed will not be found by back-/front-channel logout until the user re-authenticates.**
- `TokenRefreshService.php`: Manages access-token lifecycle for the customer session; now a thin subclass of `AbstractTokenRefreshService` mirroring `AdminTokenRefreshService`.

**Attribute Mapping (Model/Attribute/):**
- `AttributeMapperInterface.php`: Shared interface for attribute mappers
- `AdminAttributeMapper.php`: Maps OIDC claims to admin user attributes
- `CustomerAttributeMapper.php`: Maps OIDC claims to customer attributes including address fields; gender and country resolution now delegate to the shared `GenderMapper`/`CountryResolver` below (previously its own divergent copy vs. `CustomerProfileSyncService`)
- `GenderMapper.php`: Unified gender-value recognizer (male/female/m/f/1/2 plus German `mann`/`männlich`/`frau`/`weiblich`; `null` on unknown) shared by `CustomerAttributeMapper` and `CustomerProfileSyncService` (M19 — fixes a live bug where German-locale gender claims synced on first login but silently stopped re-syncing on subsequent logins).
- `CountryResolver.php`: Unified country-name/code resolution — ISO-2 passthrough → filtered DB query → intl `Locale::getDisplayRegion()` name matching (memoized); shared by `CustomerAttributeMapper` and `CustomerProfileSyncService` (M19/L43 — replaces two diverged implementations, one of which did a full unfiltered collection scan).
- `Transformer.php`: Applies a predefined transform function to a claim value before Magento field assignment, driven by the `transform_function`/`transform_params` columns on `m2oidc_oauth_attribute_mappings`. Supported functions: `concat` (joins multiple claim keys with a delimiter), `split` (splits one claim value, returns one part by index), `prefix` (prepends a static string), `regex_replace` (`preg_replace`, length-capped at `REGEX_VALUE_MAX_LENGTH = 4096` bytes before running — M20 ReDoS mitigation); null/empty function is passthrough. Never throws — logs a WARNING and returns the raw value on error.

**DTOs (Model/Data/):**
- `OidcAttributeMappingContext.php`: Immutable input for `CheckAttributeMappingAction::handle()` — replaces the former `setClientDetails()`/`setUserInfoResponse()`/`setFlattenedUserInfoResponse()`/`setUserEmail()`/`setLoginType()`/`setHeadless()` public setter chain. Built once by `ReadAuthorizationResponse`.
- `OidcUserProvisioningContext.php`: Immutable input for `ProcessUserAction::handle()` — replaces the former `setAttrs()`/`setFlattenedAttrs()`/`setUserEmail()`/`setAutoCreateCustomer()`/`setProviderId()`/`setHeadless()` public setter chain. Built once by `CheckAttributeMappingAction`.

**Security (Model/Security/):**
- `OidcRateLimiter.php`: Thin facade delegating to an injected `StrategyInterface`; applied to `ReadAuthorizationResponse`, `Oidccallback`, `BackChannelLogout`, and `FrontChannelLogout`. The `MAX_ATTEMPTS = 10` / `WINDOW_SECONDS = 60` constants are actually declared in `FixedWindowStrategy.php`/`SlidingWindowStrategy.php`, not in `OidcRateLimiter.php` itself
- `RateLimiterStrategy/StrategyInterface.php`: Single method `isAllowed(string $ip): bool`
- `RateLimiterStrategy/FixedWindowStrategy.php`: Default implementation — fixed-window; stores `{count, start}` JSON in cache; window start never slides; corrupted entries reset safely
- `RateLimiterStrategy/SlidingWindowStrategy.php`: True sliding-window for Redis (Lua-based); inject via `OidcSlidingWindowRateLimiter` DI virtual type

**Provider/Repository (Model/Provider/):**
- `MappingRepository.php`: Repository for accessing normalized attribute/role mappings (Phase 4); reads from `m2oidc_oauth_attribute_mappings` (including `transform_function`/`transform_params` per attribute) and `m2oidc_oauth_role_mappings` tables

**ORM Models:**
- `M2oidcOauthClientApps.php` + ResourceModel: Primary provider configuration model
- `OauthAttributeMapping.php` + ResourceModel: Normalized attribute mappings (Phase 4)
- `OauthRoleMapping.php` + ResourceModel: Normalized role/group mappings (Phase 4)
- `UserProvider.php` + ResourceModel: Tracks which provider created each Magento user (`m2oidc_oauth_user_provider` table); key ResourceModel methods: `saveMapping()` (upsert), `deleteMapping()` (on user deletion), `getProviderInfo()`, `getBoundProviderId()` (returns the bound `provider_id` for a user — used by IdP binding enforcement), `deleteById()` (session record deletion), `countByTypeAndProvider()` (lockout-prevention guard)

**GraphQL (Model/Resolver/):**
- `OidcLoginUrl.php`: Resolver for `oidcLoginUrl(relayState: String)` query
- `OidcProviders.php`: Resolver for listing active OIDC providers

#### ViewModels (ViewModel/)
- `OidcLoginVisibility.php`: Determines whether the OIDC login button should show on admin/customer login pages; iterates active providers from `m2oidc_oauth_client_apps` and evaluates per-provider flags (`show_*_link`, `m2oidc_disable_non_oidc_*`, `m2oidc_auto_create_*`) — no `core_config_data` dependency for visibility decisions

#### Observers (Observer/)
- `OAuthLogoutObserver.php`: Handles customer RP-Initiated Logout; bound to `controller_action_postdispatch_customer_account_logout` (**not** `customer_logout`); reads id_token from session, revokes access token, redirects to IdP
- `CustomerSetLogoutFlagObserver.php`: Sets logout flag on customer session destruction; bound to the `customer_logout` event
- `AdminSetLogoutFlagObserver.php`: Sets logout flag on admin session destruction
- `CustomerLoginAutoRedirectObserver.php`: Auto-redirects unauthenticated customers to IdP; checks `oidc_logout_guard` cookie to suppress redirect after OIDC logout
- `AdminLoginAutoRedirectObserver.php`: Auto-redirects unauthenticated admin users to IdP; respects `oidc_logout_guard` cookie
- `TokenAutoRefreshObserver.php`: Listens to `controller_action_predispatch` (frontend); calls `TokenRefreshService::refreshIfNeeded()` to silently renew the customer access token before expiry
- `AdminTokenAutoRefreshObserver.php`: Listens to `controller_action_predispatch` (adminhtml); calls `AdminTokenRefreshService::refreshIfNeeded()` to silently renew the admin access token before expiry
- `TestConfigRequestObserver.php`: Renamed from `OAuthObserver.php` (L46 — the old name suggested a general OAuth event handler; its actual job is narrowly "detect a test-config request and render the test-results body inline"). Bound to `controller_action_predispatch` in both `etc/frontend/events.xml` and `etc/adminhtml/events.xml`.
- `AdminUserDeleteObserver.php`: Fires on `admin_user_delete_after` (global area); removes the matching row from `m2oidc_oauth_user_provider` so the Sessions activity view stays accurate
- `CustomerDeleteObserver.php`: Fires on `customer_delete`; same cleanup for customer OIDC mappings

#### UI Components (Ui/)
- `Ui/Component/DataProvider.php`: Data provider for provider management grid
- `Ui/Component/DataProvider/SessionDataProvider.php`: Data provider for active sessions admin UI (`/admin/m2oidc/sessions/index`)
- `Ui/Component/Listing/Column/Actions.php`: Provider grid row actions
- `Ui/Component/Listing/Column/OnlineStatus.php`: Shows active OIDC session status in provider listing
- `Ui/Component/Listing/Column/ActiveUserCount.php`: Shows user counts as **"total (active)"** — total includes historical OIDC-linked users; active excludes deleted Magento accounts
- `Ui/Component/Listing/Column/PkceStatus.php`: Shows PKCE configuration status
- `Ui/Component/Listing/Column/JwksStatus.php`: Shows JWKS endpoint status
- `Ui/Component/Listing/Column/TestStatusOptions.php`: Test status badge column
- `Ui/Component/Listing/Column/ActiveStatus.php`: Colored Active/Inactive badge for provider listing; reads `is_active` from collection data — no extra DB query; green bullet for Active, red bullet for Inactive
- `Ui/Component/Listing/Column/SessionActions.php`: Renders "Delete" action link per row in the session activity grid; generates POST URL via `UrlBuilder`; includes confirmation dialog

#### Frontend Assets (view/adminhtml/web/)
- `js/dirtyTracking.js`: Vanilla JS (ES5-compatible) that snapshots provider form values on page load and highlights modified fields with amber border (`m2oidc-field-modified` CSS class) and modified rows (`m2oidc-row-modified`); uses `MutationObserver` to track dynamically added mapping rows
- `css/adminSettings.css`: Styles for dirty-field highlighting (amber borders, row accents)
- `images/m2oidc_logo.png`: Module logo used in admin menu and README

#### Blocks (Block/)
- `OAuth.php`: Template block class for admin/customer configuration pages and SSO buttons. Cut from ~76 public methods down to 26 (H8) — the removed half was a legacy `core_config_data`-based single-global-provider API surface left behind after the multi-provider (per-row) refactor, confirmed dead by a repo-wide grep across `view/`, `Block/`, `Model/`, `Controller/`, `Observer/`, `Plugin/`, `Ui/`, `ViewModel/` (including the block-level `getAdminRoleMappings()` — a different, unused method from the actively-used `MappingRepository::getAdminRoleMappings()`, and `getHelloWorldTxt()`, a literal leftover smoke-test method). Three methods the review's dead-list flagged were confirmed still in use by templates and kept: `isDebugLogEnable()` (`view/adminhtml/templates/misc.phtml`), `getSSOButtonText()` (`view/frontend/templates/authentication_popup_data.phtml`), `getCustomerSession()` (`view/frontend/templates/invalidate.phtml`).
  - `resolveButtonColor(?string $raw, string $fallback): string` / `resolveButtonLabel(?string $rawLabel, string $displayName): string` (L45): shared `#rrggbb`-validation-with-fallback and label-resolution helpers, used by both `adminssobutton.phtml` and `customerssobutton.phtml` (previously each template reimplemented the same two small patterns independently).
- `Adminhtml/OidcErrorMessage.php`: OIDC error display block (unchanged)

#### Logging
- Custom logger: `Logger/Logger.php` and `Logger/Handler.php`
- **Dedicated logging service**: `Logger/OidcLogger.php` — extracted from `OAuthUtility`; all logging calls in the codebase route through this class
- Configured via DI to write to `var/log/M2Oidc.log`
- Use `$oauthUtility->customlog()` for plain logs; `$oauthUtility->customlogContext()` for structured logs with fields (both delegate to `OidcLogger`)
- **JSON Lines mode**: enable `oidc/logging/json_lines` config flag to emit raw newline-delimited JSON (`{"ts":"...","level":"debug","message":"..."}`) instead of the default Monolog envelope format
- Sensitive fields (client_secret, tokens, password) are automatically masked

### Database Schema

**Primary Table: `m2oidc_oauth_client_apps`**
- Core OAuth: `app_name`, `clientID`, `client_secret`, `scope`, `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint`, `jwks_endpoint`, `endsession_endpoint`, `revocation_endpoint`, `well_known_config_url`, `issuer`, `callback_uri`, `grant_type`
- PKCE: `pkce_flow` only — there is **no `pkce_code_verifier` column**; both admin and customer PKCE verifiers are stored in the atomic cache (`AtomicCacheInterface`), keyed by a cookie-carried nonce, not in the DB
- Attribute mappings (legacy): `email_attribute`, `username_attribute`, `firstname_attribute`, `lastname_attribute`, `group_attribute`, `dob_attribute`, `gender_attribute`, `billing_*_attribute` (city, state, country, address, phone, zip)
- Role/group mappings (legacy): `oauth_admin_role_mapping` (JSON), `oauth_customer_group_mapping` (JSON), `default_role`, `default_group`
- Login behavior: `show_admin_link`, `show_customer_link`, `autoredirect_admin`, `autoredirect_customer`, `m2oidc_auto_create_admin`, `m2oidc_auto_create_customer`, `m2oidc_disable_non_oidc_admin_login`, `m2oidc_disable_non_oidc_customer_login`, `headless_mode` (smallint, FEAT-09 — enables the PWA/headless callback flow instead of session cookies)
- Profile sync: `sync_customer_profile_on_sso`, `sync_customer_address_on_sso`, `sync_customer_group_on_sso`, `sync_admin_profile_on_sso`, `sync_admin_role_on_sso`
- Multi-provider: `display_name`, `is_active`, `login_type` ('customer'|'admin'|'both'), `sort_order`, `button_label`, `button_color`, `idp_initiated_enabled` (smallint, default 0)
- Encoding: `claim_encoding` ('none'|'base64') — set to `base64` for Zitadel providers that Base64-encode claim values
- Public client: `public_client` (smallint 0|1) — omits `client_secret` from token requests for RFC 6749 §2.1 public clients
- Testing: `last_test_status`, `last_test_at`, `received_oidc_claims` (JSON array of claim keys from last test)
- Misc: `jwks_cache_ttl` (JWKS cache TTL seconds, default 86400), `http_timeout`, `log_file_suffix`
- **`post_logout_url` is read by `OidcLogoutPlugin`/`OAuthLogoutObserver` as a per-provider logout-redirect override, but this column does not currently exist in `etc/db_schema.xml`** — the override is non-functional until the column is added; treat any code path relying on it as dead until fixed

**Normalized Tables (Phase 4):**

`m2oidc_oauth_attribute_mappings`:
- FK: `provider_id` → `m2oidc_oauth_client_apps.id`
- `attribute_type`: 'email', 'username', 'firstname', 'lastname', 'group', 'dob', 'gender', 'billing_*'
- `attribute_name`: OIDC claim key
- `sync_on_sso`: 1 = re-sync this attribute on every login
- `transform_function` / `transform_params`: optional claim-value transform applied by `Model/Attribute/Transformer.php` before assignment (`concat`, `split`, `prefix`, `regex_replace`; null = passthrough)

`m2oidc_oauth_role_mappings`:
- FK: `provider_id`
- `mapping_type`: 'admin_role' | 'customer_group'
- `oidc_group`: OIDC group claim value
- `magento_role_id`: Magento role or customer group ID
- `sort_order`: evaluation order

`m2oidc_oauth_user_provider`:
- Tracks which OIDC provider created each Magento user; also used as the session activity log
- `user_type`: 'customer' | 'admin'
- `user_id`: customer entity_id or admin user_id
- `provider_id`: FK to provider
- `created_at`: timestamp
- Unique constraint on `user_type + user_id` (one provider per user)
- **IdP binding is enforced at login**: `ProcessUserAction` (customer) and `CheckAttributeMappingAction` (admin) call `getBoundProviderId()` on every login; if the stored `provider_id` differs from the current provider the login is rejected with `OAuthMessages::PROVIDER_MISMATCH`. If no binding exists yet (pre-OIDC account), the first IdP to authenticate the user claims the binding via `saveMapping()`.
- Records can be individually deleted from the Sessions admin UI (`Sessions/Delete.php`)

### Configuration

**Dependency injection (etc/di.xml):**
- `CheckAttributeMappingAction`: Injected with `UserProvisioningService`, admin factories, cookie managers, `OAuthSecurityHelper`
- `AdminUserCreator`/`CustomerUserCreator`: Injected with `MappingRepository` for Phase 4 normalized lookups; also injected with `MapperPool` (nullable)
- `OidcCredentialPlugin`/`OidcCredentialAdapter`: Full DI for auth integration
- `Oidccallback`: Injected with `Auth`, `OAuthSecurityHelper`, `ScopeConfigInterface`
- **MapperPool** registered with `default_admin` / `default_customer` defaults; third-party modules add `{providerId}_{type}` overrides
- **OidcRateLimiter** configured with `FixedWindowStrategy` by default
- **OidcSlidingWindowRateLimiter** virtual type: same type as `OidcRateLimiter` but injected with `SlidingWindowStrategy` — use for Redis deployments
- **AtomicCacheInterface** preference: `RedisAtomicCache` by default (falls back to `FileAtomicCache`-style behavior when its dedicated Redis connection is unavailable)
- Plugins registered on:
  - `Magento\Backend\Model\Auth`: `AdminLoginRestrictionPlugin` (sortOrder 5), `OidcCredentialPlugin` (10), `OidcLogoutPlugin` (20)
  - `Magento\Captcha\Observer\CheckUserLoginBackendObserver`: `OidcCaptchaBypassPlugin` (10)
  - `Magento\Customer\Api\AccountManagementInterface`: `CustomerLoginRestrictionPlugin` (5)
  - `Magento\User\Model\User`: `OidcIdentityVerificationPlugin` (10), `AdminUserDeletePlugin` (20)
  - `Magento\User\Observer\Backend\AuthObserver`: `OidcPasswordExpirationPlugin` (10)
  - `Magento\User\Observer\Backend\ForceAdminPasswordChangeObserver`: `OidcForcePasswordChangePlugin` (10)

**Events (etc/frontend/events.xml, etc/adminhtml/events.xml, etc/events.xml):**
- `customer_logout` (frontend) → `CustomerSetLogoutFlagObserver`
- `controller_action_postdispatch_customer_account_logout` (frontend) → `OAuthLogoutObserver`
- `controller_action_predispatch` → `TokenAutoRefreshObserver` (frontend area)
- `controller_action_predispatch` → `AdminTokenAutoRefreshObserver` (adminhtml area)
- `controller_action_predispatch` → `TestConfigRequestObserver` (both frontend and adminhtml area — renamed from `OAuthObserver`, see Observers section above)
- `oidc_admin_user_before_create`, `oidc_admin_user_after_create`, `oidc_customer_before_create`, `oidc_customer_after_create` (four distinct custom events fired by `UserProvisioningService` — not the generic `oidc_before_user_create`/`oidc_after_user_create` names)
- `oidc_after_attribute_mapping`: fired by `CheckAttributeMappingAction` after all claims are mapped; transport: `provider_id` (int), `mapped_attrs` (DataObject — writable by observers), `raw_claims` (DataObject — read-only snapshot)

**Routes:**
- `etc/frontend/routes.xml`: `m2oidc` frontName (customer area)
- `etc/adminhtml/routes.xml`: `m2oidc` frontName (admin area)

**Admin Panel Path:** `Stores → Configuration → M2Oidc → OAuth/OIDC`

**Admin UI Pages:**
- Provider Management: `/admin/m2oidc/provider/index` (grid), `/admin/m2oidc/provider/edit` (per-provider config)
- Provider Settings: `/admin/m2oidc/providersettings/index` (identity fields: display name, login type, active, sort order, button label/color; requires `provider_id`)
- OAuth Settings: `/admin/m2oidc/oauthsettings/index`
- Attribute Mapping: `/admin/m2oidc/attrsettings/index`
- Sign In Settings: `/admin/m2oidc/signinsettings/index`
- Sessions: `/admin/m2oidc/sessions/index` (active OIDC sessions; unlink a provider binding via `POST /admin/m2oidc/provider/unlinkuser`)
- Health Check: `/admin/m2oidc/actions/healthcheck`

### Security Features

| Feature | Status | Details |
|---------|--------|---------|
| **IdP-Initiated SSO** | Active (OIDC §4) | `IdpInitiatedLogin` controller; enforces `idp_initiated_enabled` + `is_active` gates, rate limiting, CSRF state token, PKCE |
| **CSRF Protection** | Active | State token per request; validated and consumed before token exchange |
| **Replay Protection** | Active | OIDC nonce in id_token; one-time nonce cookies for callback handoff |
| **JWT Verification** | Active | JWKS endpoint required; validates issuer, audience, nonce, signature |
| **PKCE** | Active | S256 (SHA256) preferred, PLAIN fallback; verifier stored in atomic cache keyed by a cookie nonce (admin and customer flows alike) — not in the DB |
| **Ephemeral Auth Tokens** | Active (C-01) | Admin login uses single-use tokens with 300s (5-minute) cache TTL — no static markers |
| **Rate Limiting** | Active | Fixed-window strategy (10 attempts / 60s) via `OidcRateLimiter`; applied to customer callback (`ReadAuthorizationResponse`), admin callback (`Oidccallback`), back-channel logout (`BackChannelLogout`), front-channel logout (`FrontChannelLogout`), IdP-initiated login (`IdpInitiatedLogin`), and the headless callback (`HeadlessOidcCallback`) |
| **Back-Channel Logout** | Active (FEAT-02) | Server-to-server logout via JWT logout token; session destruction by ID |
| **Front-Channel Logout** | Active | IdP-iframe-based logout (Entra/Keycloak); `sid`-based session lookup via `OidcSessionRegistry`; shares `SessionDestructionService` with Back-Channel Logout |
| **Headless / PWA Login** | Active (FEAT-09) | Per-provider `headless_mode` flag; token delivered via `postMessage` instead of a session cookie |
| **RP-Initiated Logout** | Active | Admin + customer; id_token_hint; RFC 7009 token revocation; Authelia compat |
| **Logout Guard** | Active | `oidc_logout_guard` cookie — **120s TTL for admin** (`OidcLogoutPlugin`), **300s TTL for customer** (`OAuthLogoutObserver`); prevents auto-redirect loop after IdP logout |
| **Login Restriction** | Configurable | Block non-OIDC logins per provider; safety net prevents lockout |
| **Claims Access Control** | Active (FEAT-04) | Rules engine with 6 operators (eq, neq, contains, not_contains, exists, not_exists); AND-combined |
| **Cross-Website Guard** | Active (SEC-08) | Customer login rejects cross-website account login attempts |
| **XSS Prevention** | Active | Error messages sanitized; non-printable chars removed |
| **Open Redirect** | Protected | `validateRedirectUrl()` enforces same-origin; rejects login-page relay states |
| **CAPTCHA Bypass** | Controlled | Intentional bypass for OIDC (auth already done at IdP) |
| **Password Bypass** | Protected | `OidcPasswordExpirationPlugin`, `OidcForcePasswordChangePlugin` suppress password flows for OIDC users |
| **Stale Flag Guard** | Active (SEC-06) | `OidcCredentialPlugin` unconditionally clears OIDC flag in `afterLogin()` |
| **Per-User IdP Binding** | Active | `ProcessUserAction` / `CheckAttributeMappingAction` check `m2oidc_oauth_user_provider` on every login; cross-IdP login rejected with `PROVIDER_MISMATCH`; first OIDC login of a pre-existing account claims the binding |
| **Lockout Prevention** | Active | Provider save auto-reverts `disable_non_oidc_*_login` if no OIDC users exist yet for that provider |
| **Required Field Validation** | Active | Email, username, firstname, lastname claims validated as non-empty on provider save |
| **Address Integrity Guard** | Active | Billing address only created when all four fields (street, ZIP, city, country) are mapped |

### Admin Auto-Login Implementation

**Current Implementation (Native Magento Integration):**

1. **Detection** (`Controller/Actions/CheckAttributeMappingAction.php`):
   - Checks if authenticated email exists in `admin_user` table
   - If admin exists, stores user info in session, creates ephemeral nonce cookie, and redirects to admin callback
   - If admin doesn't exist and auto-create is enabled, creates admin user first (see Admin Auto-Creation below)

2. **Native Authentication Flow** (`Controller/Adminhtml/Actions/Oidccallback.php`):
   - **Rate limit check** (first): `OidcRateLimiter::isAllowed($clientIp)` — redirects to admin login with error if exceeded
   - Validates and consumes ephemeral admin nonce (one-time use, **300s TTL**) via `OAuthSecurityHelper`
   - Creates ephemeral OIDC auth token (`OIDC_TOKEN_<random>`) stored in cache with **300s TTL**
   - Calls `Auth::login($email, $ephemeralToken)` — plugin system intercepts
   - All security events fire properly; CAPTCHA is automatically bypassed via plugin
   - Persists id_token in admin session for logout flow

3. **OIDC Adapter** (`Model/Auth/OidcCredentialAdapter.php`):
   - Validates ephemeral auth token (single-use via cache delete-on-read)
   - Loads user from database, checks active status and role assignment
   - Records login and reloads user data

4. **Plugin Orchestration**:
   - `OidcCredentialPlugin` detects ephemeral token format and injects adapter
   - `OidcCaptchaBypassPlugin` skips CAPTCHA for OIDC auth
   - Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth` marker

### Admin Auto-Creation

When "Auto Create Admin users while SSO" is enabled, admin users are automatically created during OIDC authentication:

**Flow** (`Controller/Actions/CheckAttributeMappingAction.php` → `UserProvisioningService` → `AdminUserCreator`):
1. **Attribute Extraction**: Uses `AdminAttributeMapper` with configured attribute mappings for firstName, lastName, userName
2. **Name Fallbacks**: If names are empty, uses `explode("@", $email)` — email prefix for firstName, domain for lastName
3. **Group Extraction**: Reads OIDC groups from configured group attribute claim
4. **Role Assignment**: `AdminUserCreator::getAdminRoleFromGroups()` resolves role
5. **User Creation**: Creates admin user with random secure password (authentication is via OIDC, not password)
6. **Login Redirect**: Redirects to admin callback for standard OIDC login flow

**Role Mapping Fallback Chain**:
1. Normalized `m2oidc_oauth_role_mappings` table (Phase 4, case-insensitive)
2. Legacy JSON `oauth_admin_role_mapping` column (case-insensitive)
3. Default admin role (`defaultRole` config, must be a numeric role ID)
4. **Deny** — `getAdminRoleFromGroups()` returns `null`; user creation is refused

**Configuration UI** (Attribute Mapping page):
- **Group Attribute Name**: OIDC claim containing group/role information (e.g., `groups`, `roles`, `memberOf`)
- **Default Admin Role**: Dropdown to select fallback role when no mapping matches
- **Role Mappings**: Dynamic rows mapping OIDC group names to Magento admin roles

### Customer Auto-Creation

When "Auto Create Customer users while SSO" is enabled:

**Flow** (`ProcessUserAction` → `UserProvisioningService` → `CustomerUserCreator`):
1. `CustomerAttributeMapper` extracts attributes from OIDC claims
2. Customer group resolved via `m2oidc_oauth_role_mappings` (mapping_type='customer_group')
3. Falls back to legacy JSON column, then default group, then Magento "General" group
4. If `m2oidc_dont_create_customer_if_group_not_mapped` is set, creation is denied when no group match
5. Optional address creation (billing/shipping) from mapped claims
6. Customer nonce set, redirect to `CustomerOidcCallback`

### Lockout-Prevention Guards

When saving a provider, `Controller/Adminhtml/Provider/Save.php` enforces a safety rule: **"Disable non-OIDC login" cannot be enabled unless at least one user of that type has already authenticated via OIDC for that provider.**

- If `m2oidc_disable_non_oidc_admin_login = 1` but `m2oidc_oauth_user_provider` has zero admin rows for the provider, the setting is automatically reset to `0` and a warning is shown
- Same logic applies for `m2oidc_disable_non_oidc_customer_login`
- The Login Options tab in the provider edit form shows a conditional warning when the setting would be unsafe to enable
- `Block/Adminhtml/Provider/Edit/Tab/LoginOptions.php` provides `hasOidcAdminUsers()` and `hasOidcCustomerUsers()` checks that power the UI warnings

**Rationale**: Prevents an administrator from locking themselves out before any OIDC login has been verified to work correctly.

### Session Management

- `SessionHelper.php`: `updateSessionCookies()` re-sets existing cookies with SameSite=None for cross-origin OIDC
- `OidcSessionRegistry`: Maps OIDC `sub`/`sid` claims to PHP session IDs for back-channel and front-channel logout
- Admin session stores: `oidc_id_token`, `oidc_access_token`, `oidc_provider_id` for logout flow
- Customer session stores: analogous keys for customer RP-Initiated Logout

### Auto-Discovery

When a `well_known_config_url` is configured, `Controller/Adminhtml/Provider/Save.php` fetches the OIDC discovery document on save and auto-populates:
- `authorize_endpoint`, `access_token_endpoint`, `user_info_endpoint`
- `jwks_endpoint`, `endsession_endpoint`, `revocation_endpoint`, `issuer`

This eliminates manual endpoint configuration for any standards-compliant IdP.

### Attribute Mapping

OIDC claims are mapped to Magento user attributes via configuration:
- Email: `email_attribute` (default: "email")
- Username: `username_attribute` (default: "preferred_username")
- First name: `firstname_attribute` (default: "name" with split)
- Last name: `lastname_attribute` (default: "name" with split)
- Groups: `group_attribute` (for role/group mapping)
- DOB: `dob_attribute`; Gender: `gender_attribute`
- Billing address: `billing_city_attribute`, `billing_state_attribute`, `billing_country_attribute`, `billing_address_attribute`, `billing_phone_attribute`, `billing_zip_attribute`
- **Country name resolution**: `CustomerAttributeMapper` resolves country values to ISO codes via `CountryCollection`. When the PHP `intl` extension is loaded, English country names (e.g., `Germany` sent by Authelia) are matched via `Locale::getDisplayRegion()` against Magento's active country codes. This prevents mismatches when the IdP always sends English names regardless of store locale.

**Phase 4 Normalized Storage** (in `m2oidc_oauth_attribute_mappings`):
- `attribute_type` + `attribute_name` per provider
- `sync_on_sso` flag for profile update on every login
- `transform_function` + `transform_params`: optional claim-value transform (`concat`, `split`, `prefix`, `regex_replace`) applied by `Model/Attribute/Transformer.php` before the value is assigned to the Magento field
- Accessed via `MappingRepository`; falls back to legacy columns

### Multi-Provider Support

The module fully supports multiple OIDC providers per Magento installation:
- Each provider is a row in `m2oidc_oauth_client_apps` with its own `clientID`, endpoints, attribute mappings, role mappings
- `SendAuthorizationRequest` accepts `provider_id` parameter; relay state encodes `p` (provider ID)
- `ReadAuthorizationResponse` reconstructs provider context from relay state
- `OAuthUtility::setActiveProviderId()` sets per-request provider context for config resolution
- `OAuthUtility::getAllActiveProviders($loginType)` returns providers filtered by login type
- Provider management grid at `/admin/m2oidc/provider/index`

## Use Cases for This Module

### When You'll Work on This Module

You'll interact with this module when:

- **Integrating a new OIDC provider** (Okta, Azure AD, Google, Authelia, custom IdP)
  - Create provider row via `/admin/m2oidc/provider/edit`
  - Test with Test Configuration button (stores received claims in `received_oidc_claims`)

- **Adding custom attribute mappings** (e.g., employee ID, department, custom fields)
  - Modify `Model/Attribute/AdminAttributeMapper.php` or `CustomerAttributeMapper.php`
  - Add columns to `etc/db_schema.xml` if new database fields needed

- **Debugging failed logins**
  - Enable debug logging: **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings > Enable debug logging**
  - Check `var/log/M2Oidc.log` for detailed flow logs
  - Key log entries: "State token validation PASSED", "PKCE code_verifier loaded", "Authentication successful for:", "SUCCESS: Auth::login() completed"

- **Extending JIT provisioning logic**
  - Create plugins on `CheckAttributeMappingAction::execute()` or observe `oidc_admin_user_before_create` / `oidc_admin_user_after_create` / `oidc_customer_before_create` / `oidc_customer_after_create`

- **Adding new security bypasses** (e.g., 2FA module integration for OIDC users)
  - Follow pattern from `Plugin/Captcha/OidcCaptchaBypassPlugin.php`
  - Check for `oidc_authenticated` cookie or `oidc_auth` event marker

- **Implementing claims-based access control**
  - Configure rules in Sign In Settings (FEAT-04); operators: `eq`, `neq`, `contains`, `not_contains`, `exists`, `not_exists`
  - Rules are AND-combined; first failing rule blocks login with configured error message

### Common Modification Scenarios

#### Scenario 1: Add New OIDC Claim to Customer Profile

**Goal**: Map a custom OIDC claim (e.g., `employee_id`) to a custom customer attribute.

**Files to modify**:
- [Model/Attribute/CustomerAttributeMapper.php](Model/Attribute/CustomerAttributeMapper.php)
- [etc/db_schema.xml](etc/db_schema.xml) (add column to `m2oidc_oauth_client_apps` or use Phase 4 `m2oidc_oauth_attribute_mappings`)
- [view/adminhtml/templates/attrsettings.phtml](view/adminhtml/templates/attrsettings.phtml) (add UI field)

**Pattern to follow**:
```php
// In CustomerAttributeMapper.php, follow the DOB mapping pattern:
$employeeId = $flattenedAttrs[$this->oauthUtility->getStoreConfig('employee_id_attribute')] ?? '';
if (!empty($employeeId)) {
    $customer->setCustomAttribute('employee_id', $employeeId);
}
```

---

#### Scenario 2: Customize Admin Role Mapping Logic

**Goal**: Add custom logic to admin role assignment (e.g., map based on email domain).

**Files to modify**:
- [Model/Service/AdminUserCreator.php](Model/Service/AdminUserCreator.php) — method `getAdminRoleFromGroups()`

**Pattern to follow**:
```php
// Before the existing group mapping loop, add custom logic:
private function getAdminRoleFromGroups(array $userGroups): ?int
{
    // Custom logic: Check email domain first
    $email = $this->oauthUtility->getAdminSessionData('oidc_user_email');
    if (str_ends_with($email, '@executives.example.com')) {
        return 1; // Administrators role
    }
    // Continue with existing Phase 4 / legacy mapping logic...
}
```

---

#### Scenario 3: Add OIDC Button to Custom Theme

**Goal**: Display "Login with SSO" button on custom login page.

**Code to add**:
```php
<?php
$oauthHelper = $block->getData('oauth_helper');
$providers = $oauthHelper->getAllActiveProviders('customer');
foreach ($providers as $provider) {
    $loginUrl = $oauthHelper->getSPInitiatedUrlForProvider($provider['id']);
    $label = $provider['button_label'] ?: __('Login with SSO');
    echo '<a href="' . $escaper->escapeUrl($loginUrl) . '">' . $escaper->escapeHtml($label) . '</a>';
}
?>
```

**Layout XML injection** (in `Magento_Customer/layout/customer_account_login.xml`):
```xml
<referenceBlock name="customer_form_login">
    <arguments>
        <argument name="oauth_helper" xsi:type="object">M2Oidc\OAuth\Helper\OAuthUtility</argument>
    </arguments>
</referenceBlock>
```

---

#### Scenario 4: Debug Failed Token Exchange

**Goal**: Token exchange fails with "configuration error" or "invalid_grant".

**Debugging steps**:
1. **Enable debug logging**: **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings**
2. **Trigger auth flow** and check `var/log/M2Oidc.log`
3. **Look for these log entries**:
   - "ReadAuthResponse: State token validation PASSED" — confirms CSRF passed
   - "ReadAuthResponse: PKCE code_verifier loaded from session/DB" — confirms verifier found
   - "ReadAuthResponse: id_token stored in transport cookie" — confirms token exchange succeeded
   - Common errors: `invalid_grant` (code expired/reused), `invalid_client` (wrong credentials), `redirect_uri_mismatch`
4. **Verify configuration**: Callback URL must be `https://your-site.com/m2oidc/actions/ReadAuthorizationResponse`
5. **Common fixes**: Re-save OAuth Settings, check `values_in_header` vs `values_in_body`, verify HTTPS

---

#### Scenario 5b: Provider only allows one Post Logout Redirect URI

**Goal**: Register a single Post Logout Redirect URI when the OIDC provider restricts you to one URL.

**Register with your IdP**:
```
https://your-site.com/m2oidc/actions/postlogout
```

No code changes are needed — the module already uses this URL as the default `post_logout_redirect_uri` for both admin and customer logout flows. Context is carried in the OIDC `state` parameter:

| Logout type | state sent to IdP | Landing page after logout |
|---|---|---|
| Admin | `admin:<random-hex>` | Admin login page (`/admin/`) |
| Customer | `customer:<random-hex>` | Customer login page (`/customer/account/login/`) |
| Unknown/absent | — | Store home (`/`) |

**Per-provider override**: the code reads `post_logout_url` on the provider row for a custom landing page, but this column does not currently exist in `etc/db_schema.xml` — add it to the schema before relying on this override.

**Files involved**:
- [Controller/Actions/Postlogout.php](Controller/Actions/Postlogout.php) (class `Postlogout`) — the callback controller
- [Plugin/Auth/OidcLogoutPlugin.php](Plugin/Auth/OidcLogoutPlugin.php) — sets `state=admin:<hex>` and sends callback URL
- [Observer/OAuthLogoutObserver.php](Observer/OAuthLogoutObserver.php) — sets `state=customer:<hex>` and sends callback URL

---

#### Scenario 5: Integrate with Authelia RP-Initiated Logout

**Detection is automatic**: If the `endsession_endpoint` path ends with `/logout` (without `/oauth2/` or `/oidc/`), Authelia's forward-auth mode is assumed.

- `OidcLogoutPlugin` uses `?rd=<adminBaseUrl>` parameter instead of standard OIDC `post_logout_redirect_uri`
- The `post_logout_redirect_uri` is resolved to the static admin base URL (e.g., `https://your-site.com/admin/`) — **never** the dynamic request URL (which contains tokens that cannot be registered as allowed redirect URIs)
- Configure: set `endsession_endpoint` = `https://auth.example.com/logout` in provider settings

---

### Testing Checklist

Before deploying OIDC changes, verify:

- [ ] **Enable debug logging**: **Stores > Configuration > M2Oidc > OAuth/OIDC > Sign In Settings**

- [ ] **Test customer flow**:
  - Navigate to frontend SSO link
  - Redirected to IdP, authenticate successfully
  - Returned to Magento via `CustomerOidcCallback`, customer session established
  - Check `var/log/M2Oidc.log` for "CustomerOidcCallback: Login successful"
  - Verify `oidc_customer_authenticated` cookie set

- [ ] **Test admin flow**:
  - Navigate to admin SSO link
  - Returned to Magento admin dashboard
  - Check `var/log/M2Oidc.log` for "SUCCESS: Auth::login() completed successfully"
  - Verify `oidc_authenticated` cookie set

- [ ] **Test RP-Initiated Logout**:
  - Log in via OIDC, then click "Sign Out"
  - Verify redirected to IdP logout URL
  - Verify Magento session cleared and `oidc_logout_guard` cookie present
  - Verify auto-redirect does NOT trigger immediately after logout
  - Verify IdP redirects back to `/m2oidc/actions/postlogout?state=admin:<hex>` (admin) or `?state=customer:<hex>` (customer)
  - Verify browser ends up on admin login page or customer login page respectively

- [ ] **Test auto-creation** (if enabled):
  - Use a new user email not in Magento database
  - Check `var/log/M2Oidc.log` for "UserProvisioningService: User created successfully"

- [ ] **Test attribute mapping**:
  - Click **Test Configuration** button
  - Verify all expected OIDC claims displayed
  - Check `received_oidc_claims` column populated in database

- [ ] **Test error scenarios**:
  - Admin account not found + auto-create disabled → "Admin account not found"
  - Inactive admin user → "Admin account is inactive"
  - No role assigned → "Admin user has no assigned role"
  - Claims access control rule fails → configured error message shown

- [ ] **Test lockout-prevention guard**:
  - Attempt to enable "Disable non-OIDC admin logins" with no OIDC admin users → setting auto-reverted to disabled with warning
  - After at least one OIDC admin login, enabling restriction should succeed

- [ ] **Test address validation**:
  - Map only some billing address fields (e.g., city + country but no street/ZIP) → no address created
  - Map all four required fields (street, ZIP, city, country) → address created correctly

- [ ] **Test auto-discovery**:
  - Enter well-known config URL, save → all endpoints auto-populated
  - Verify endpoints match provider's discovery document

- [ ] **Test dirty-field tracking**:
  - Open provider edit form, modify a field → amber border appears on changed field
  - Save and reload → no amber borders on unmodified fields

---

## Testing Structure

Note: earlier revisions of this doc listed a specific "N test cases" figure per file; those numbers were generated from file line counts, not actual test enumeration, and were wrong by 10-35x in most cases. Don't trust a hardcoded count here — run `grep -c "function test"` (or count `#[DataProvider]`/`@dataProvider` methods) on the file directly if you need a current number.

**Unit Tests** (`Test/Unit/`):
- `Plugin/OidcCredentialPluginTest.php`: Plugin behavior and flag cleanup
- `Helper/OAuthUtilityExtractNameTest.php`: Email parsing
- `Helper/JwtVerifierTest.php`: JWT validation (JWKS, issuer, audience, nonce)
- `Helper/OAuthSecurityHelperTest.php`: PKCE, state tokens, nonces, atomic cache integration, TOCTOU coverage
- `Helper/OAuthSecurityHelperMultiProviderTest.php`: Multi-provider security scenarios
- `Model/Attribute/AdminAttributeMapperTest.php`: Admin attribute mapping
- `Model/Attribute/CustomerAttributeMapperTest.php`: Customer attribute mapping including address
- `Model/Auth/OidcCredentialAdapterTest.php`: Adapter authentication logic, ephemeral tokens, `__serialize()`/`__unserialize()`
- `Model/Service/AdminUserCreatorRoleMappingTest.php`: Role mapping logic including fallback chain
- `Model/Service/CustomerUserCreatorAddressTest.php`: Address creation from OIDC claims
- `Model/Service/AdminProfileSyncServiceTest.php`, `CustomerProfileSyncServiceTest.php`: Per-attribute `sync_on_sso` guard behavior
- `Model/Service/AdminUserCreatorApplyNameFallbacksTest.php`: Name-fallback logic when firstName/lastName claims are empty
- `Model/Security/OidcRateLimiterTest.php`: Strategy delegation, FixedWindowStrategy and SlidingWindowStrategy
- `Model/Cache/FileAtomicCacheTest.php`, `RedisAtomicCacheTest.php`, `RedisConnectionFactoryTest.php`: Atomic cache backends
- `Controller/IdpInitiatedLoginTest.php`: Security regression tests
- `Controller/ProviderSettingsIndexTest.php`: Provider Settings admin controller
- `Controller/Actions/CheckAttributeMappingActionIdpBindingTest.php`: Admin-side IdP binding enforcement
- `Controller/Actions/ProcessUserActionIdpBindingTest.php`: Customer-side IdP binding enforcement
- `Controller/BackChannelLogoutTest.php`: Back-channel logout JWT validation and session destruction
- `Observer/AdminTokenAutoRefreshObserverTest.php`, `TokenAutoRefreshObserverTest.php`: Token refresh observer coverage
- `Observer/AdminUserDeleteObserverTest.php`, `CustomerDeleteObserverTest.php`: User-delete cleanup coverage
- `Observer/OAuthLogoutObserverTest.php`: Customer logout observer
- `Plugin/Auth/OidcLogoutPluginTest.php`: RP-Initiated Logout plugin
- `Security/SecurityRegressionTest.php`: Cross-cutting security regression coverage
- `Helper/OAuth/AuthorizationRequestTest.php`: H4 — `?` vs `&` query-string separator on the authorize URL
- `Helper/OAuth/AccessTokenRequestBodyTest.php`: H6 — `client_id` present in the token-exchange body iff no Basic-auth header is sent
- `Helper/OAuthUtilityIsBlankTest.php`: L38 — `isBlank()` no longer treats `"0"` as blank
- `Model/Attribute/TransformerTest.php`: Claim-value transformers (`concat`/`split`/`prefix`/`regex_replace`), including the M20 length-cap behavior
- `Model/Attribute/GenderMapperTest.php`: Full gender recognizer including German words (M19)
- `Model/Attribute/CountryResolverTest.php`: ISO passthrough, name→code resolution, unknown→null (M19/L43)
- `Model/ResourceModel/OidcProviderRepositoryTest.php`: Decrypt-failure WARNING logging (M22), `login_type=''` wildcard semantics (H10)
- `Model/Service/GroupMappingResolverTest.php`: Full fallback chain (M18)
- `Model/Service/RandomPasswordGeneratorTest.php`: Password length + character-class coverage (M25)
- `Model/Service/RpInitiatedLogoutServiceTest.php`: URL validation, Authelia detection, state prefix (M28/M29)
- `Model/Service/OidcSessionRegistryKeyTest.php`: `buildKey()` collision-safety — `(sub="a|b", sid="")` ≠ `(sub="a", sid="b|")` (M16)
- `Model/Validation/SsrfUrlValidatorTest.php`: Loopback/RFC-1918/http rejected; public https accepted (H9)
- `Model/Validation/ProviderDataValidatorTest.php`: Enum whitelisting, lockout auto-revert, SSRF endpoint rejection (C3)
- `Setup/Patch/Data/EncryptPlaintextClientSecretsTest.php`: Plaintext encrypted, already-encrypted values untouched (C2)
- `Controller/Actions/ProcessUserActionRelayStateTest.php`: H5 behavioral — relative path and same-host relay states preserved; cross-origin relay states reset

**Integration Tests** (`Test/Integration/`):
- `AdminOidcLoginFlowTest.php`: Full admin login flow
- `CustomerOidcLoginFlowTest.php`: Full customer login flow
- `SecurityPluginsTest.php`: Plugin security behaviors
- `AccessControlRulesTest.php`: Claims-based access control rules engine
- `Service/OidcSessionRegistryTest.php`: Session registry (sub/sid → PHP session ID) — this is an integration test, not a unit test, despite being about a `Model/Service/` class
- `Service/TokenRefreshIntegrationTest.php`: Token refresh service (FEAT-03)
- `Resolver/OidcProvidersResolverTest.php`: GraphQL resolvers (FEAT-08)
- `DexDiscoveryTest.php`: OIDC discovery document handling
- `AbstractOidcIntegrationTest.php`: Shared base class (not a test suite itself)

**Coverage**: All critical flows covered. `OidcAuthenticationService` Zitadel Base64 scenarios covered in `Test/Unit/Model/Service/OidcAuthenticationServiceTest.php`.

---

## Completed Architectural Improvements (reference)

The following items from the previous "future improvements" list have been implemented:
- **God-class split (`OAuthUtility`)**: extracted `OidcLogger`, `ProviderResolver`, `OidcConfigReader` — `OAuthUtility` is now a thin facade. The split is now fully applied: the three raw-SQL `#[\Override]` methods (`saveTestStatus`, `saveTestStatusById`, `saveReceivedOidcClaims`) that had diverged from `Data`'s repository-delegating versions are gone; `OAuthUtility` inherits them (H11). Eight further dead public methods (`decryptSecret`, `removeSignInSettings`, `getHiddenPhone`, `getHiddenEmail`, the bare-array `getClientDetails()`, `isCurlInstalled`, `getFileContents`, `putFileContents`) were also removed.
- **God-class split (`Data.php`)**: DB operations extracted to `OidcProviderRepository`
- **God-class split (`Block/OAuth.php`)**: same treatment applied one release later (H8) — see the Blocks section above; cut from ~76 to 26 public methods.
- **Provider-resolution consolidation (H10)**: `ProviderResolver` no longer reimplements provider listing; its fallback delegates to `OidcProviderRepository::getAllActiveProviders()`, which decrypts `client_secret` for every row and treats `login_type=''` as matching any requested type.
- **DTO refactor for `CheckAttributeMappingAction`/`ProcessUserAction`**: the former public setter-chain-then-`execute()` pattern is replaced by `handle(DtoType $context): ResultInterface` taking an immutable `Model/Data/OidcAttributeMappingContext` or `OidcUserProvisioningContext`. See the DTOs subsection under Models & Services above.
- **Shared validation layer (`Model/Validation/`)**: `SsrfUrlValidator` and `ProviderDataValidator` close the gap where CLI/admin-UI config import bypassed the manual Provider Save form's whitelisting, SSRF checks, and lockout-prevention guard.
- **First data patch (`Setup/Patch/Data/EncryptPlaintextClientSecrets`)**: encrypts any legacy plaintext `client_secret` and backfills empty `login_type` on upgrade.
- **Atomic token operations (F3)**: `AtomicCacheInterface` / `FileAtomicCache` / `RedisAtomicCache` eliminate TOCTOU on nonce/state-token consumption
- **Sliding-window rate limiter (F1)**: `SlidingWindowStrategy` + `OidcSlidingWindowRateLimiter` virtual type
- **OIDC discovery auto-refresh (F4)**: `Cron/RefreshOidcDiscovery` every 6h
- **Granular sync flags (F5)**: per-attribute `sync_on_sso` in `m2oidc_oauth_attribute_mappings` respected by profile sync services
- **JSON Lines logging (F6)**: `OidcLogger` with `oidc/logging/json_lines` config flag
- **MapperPool DI extension point**: third-party per-provider mapper overrides
- **`oidc_after_attribute_mapping` event**: attribute-mapping hook for third-party modules
- **`OidcCredentialAdapter` serialization**: `__serialize()`/`__unserialize()` (PHP 8, eager restoration)
- **Front-Channel Logout**: `FrontChannelLogout.php` + shared `SessionDestructionService` (extracted from `BackChannelLogout`)
- **Headless / PWA Login (FEAT-09)**: `HeadlessOidcCallback.php` + `headless_mode` column
- **Provider Config Export/Import (FEAT-07)**: `Console/Command/ExportOidcConfig.php` / `ImportOidcConfig.php`
- **Per-attribute claim transformers**: `Model/Attribute/Transformer.php` + `transform_function`/`transform_params` columns
- **Provider unlink + Provider Settings admin page**: `Provider/UnlinkUser.php`, `Providersettings/Index.php`

## Future Improvements to Consider

### If Asked About Multi-Provider Support

Multi-provider is fully implemented. The `provider_id` parameter flows through the entire auth pipeline. Admin UI at `/admin/m2oidc/provider/index` manages providers. Phase 4 normalization (`m2oidc_oauth_attribute_mappings`, `m2oidc_oauth_role_mappings`) supports per-provider attribute and role mappings independently.

### If Asked About Security Improvements

**CSRF Token Validation**: Already implemented via state token in `OAuthSecurityHelper`. Encodes session ID, app name, login type, state token, and provider ID in relay state.

**Rate Limiting**: Already implemented via `OidcRateLimiter` using a fixed-window strategy (10 attempts / 60s) on the customer callback (`ReadAuthorizationResponse`), admin callback (`Oidccallback`), back-channel logout (`BackChannelLogout`), front-channel logout (`FrontChannelLogout`), IdP-initiated login (`IdpInitiatedLogin`), and the headless callback (`HeadlessOidcCallback`) endpoints.

**`SessionCookieObserver` has been removed** (it was never wired to any event in this module — confirmed dead code per the code review, deleted rather than re-wired). `Helper/SessionHelper.php::updateSessionCookies()` still exists and does the actual SameSite=None cookie rewrite, but its only caller, `configureSSOSession()`, is itself not invoked anywhere (see the "intentionally unused" gotcha in `TECHNICAL_DOCUMENTATION.md` — unrelated to this session's changes). There is no module-provided, wired-up global cookie rewrite today. If you need one, write a new observer bound to an explicit event rather than assuming the removed class's shape.

### If Asked About GraphQL Support

**Already implemented** in `Model/Resolver/`:
- `OidcLoginUrl.php`: Resolves `oidcLoginUrl(relayState: String)` query
- `OidcProviders.php`: Resolves list of active OIDC providers

Schema defined in `etc/schema.graphqls`.

### If Asked About Performance Optimization

1. **JWKS Caching**: `JwtVerifier` caches JWKS responses with a configurable per-provider TTL (column `jwks_cache_ttl`, default 86400 s). A circuit-breaker (`m2oidc_jwks_fail_*`, 60 s) prevents hammering an unavailable JWKS endpoint after a re-fetch failure.

2. **Global Cookie Rewrite**: `SessionCookieObserver` (which scoped the rewrite to OIDC paths) has been removed as confirmed dead code — see the Security Improvements section above. If cross-origin cookie rewriting is needed, wire a new observer to an explicit event using `SessionHelper::updateSessionCookies()`.

3. **Attribute Mapper Phase 4**: `MappingRepository` uses normalized tables which are more query-efficient than JSON column parsing.

### If Asked About Single / Unified Post Logout Redirect URI

**Already implemented** (`Controller/Actions/Postlogout.php`, class `Postlogout`):
- GET endpoint: `https://your-site.com/m2oidc/actions/postlogout`
- Reads `state` query param echoed by IdP; parses `admin:` or `customer:` prefix
- Redirects to admin login page, customer login page, or store home (fallback)
- Both `OidcLogoutPlugin` (admin) and `OAuthLogoutObserver` (customer) read a `post_logout_url` provider override before falling back to this unified URL — **but that column doesn't exist in `etc/db_schema.xml` yet**, so today the unified URL is always used regardless of provider
- Admin logout state format: `admin:<16-byte-hex>`; customer: `customer:<16-byte-hex>`
- **Authelia unaffected**: Authelia mode uses `?rd=` directly and does not call this endpoint

### If Asked About Back-Channel Logout

**Already implemented** (`Controller/Actions/BackChannelLogout.php`):
- POST endpoint: `/m2oidc/actions/backchannellogout`
- IP-based rate limiting via `OidcRateLimiter` (fixed-window: 10 attempts / 60s); returns HTTP 429 on exceeded limit
- Validates logout token JWT via JWKS
- Parses `aud` claim supporting both string and array formats
- Resolves PHP session via `OidcSessionRegistry` (sub/sid → session ID)
- Destroys target session via `SessionDestructionService` by switching PHP session IDs (C-02 pattern)
- Returns HTTP 200 (success), 400 (invalid token), 429 (rate limited), 501 (unknown provider)

### If Asked About Front-Channel Logout

**Already implemented** (`Controller/Actions/FrontChannelLogout.php`):
- GET endpoint: `/m2oidc/actions/frontchannellogout?sid=<sid>`
- For IdPs that perform logout via an `<iframe>` per SP (Entra, some Keycloak configs) instead of a server-to-server POST
- Rate-limited the same as `BackChannelLogout`; validates/sanitizes `sid`; resolves and destroys the session via the same `OidcSessionRegistry` + `SessionDestructionService` used by `BackChannelLogout`
- Always returns a 1×1 transparent GIF so the IdP's iframe receives a valid image response

### If Asked About Headless / PWA Login

**Already implemented** (`Controller/Actions/HeadlessOidcCallback.php`, FEAT-09):
- Enabled per-provider via the `headless_mode` column
- `CustomerLoginAction` redirects here instead of `CustomerOidcCallback` when headless mode is active
- Validates the one-time `oidc_headless_nonce` cookie, issues a Magento customer token, and returns a page that `postMessage`s the token to the opener window (origin-restricted) instead of setting a session cookie — suited to PWA/headless storefronts

### If Asked About Provider Config Export/Import

**Already implemented** (`Console/Command/ExportOidcConfig.php` / `ImportOidcConfig.php`, FEAT-07):
- `bin/magento oidc:config:export [--provider-id=<id>] [--output=<file>]` — exports one or all providers to JSON; `client_secret` is Magento-encrypted
- `bin/magento oidc:config:import --input=<file> [--dry-run] [--overwrite]` — skips existing `app_name` unless `--overwrite`; encrypts plaintext `client_secret` on import

### If Asked About Per-Attribute Claim Transformers

**Already implemented** (`Model/Attribute/Transformer.php`):
- Driven by `transform_function`/`transform_params` columns on `m2oidc_oauth_attribute_mappings`
- Supported functions: `concat`, `split`, `prefix`, `regex_replace`; null/empty is passthrough; never throws (logs WARNING and returns the raw value on error)

**For detailed technical specifications, refer to** [Docs/TECHNICAL_DOCUMENTATION.md](Docs/TECHNICAL_DOCUMENTATION.md).
