# OIDC Module — Implementation Plan & Improvement Roadmap

> **Scope:** Security hardening, functionality gaps, refactoring, test coverage, and full multi-provider support.
> **PHP target:** 8.2 (Rector already configured)
> **Based on:** Full codebase analysis of all Controllers, Models, Plugins, Helpers, Observers, Blocks, and View templates.

---

## Table of Contents

1. [Security Fixes](#1-security-fixes)
2. [Functionality Enhancements](#2-functionality-enhancements)
3. [Multi-Provider Support](#3-multi-provider-support)
4. [Refactoring & Code Quality](#4-refactoring--code-quality)
5. [Tests](#5-tests)
6. [Implementation Sequence](#6-implementation-sequence)

---

## 1. Security Fixes

### P0 — Fix Before Any Production Deployment

---

#### SEC-01 · Missing SSL Verification on All Outbound HTTP Requests

| | |
|---|---|
| **Files** | [Helper/Curl.php](Helper/Curl.php) · [Helper/JwtVerifier.php](Helper/JwtVerifier.php) · [Block/Adminhtml/Debug.php](Block/Adminhtml/Debug.php) |
| **Risk** | Man-in-the-middle attack on token exchange, JWKS fetch, and discovery endpoint calls |

**Problem:** `Curl.php` never sets `CURLOPT_SSL_VERIFYPEER` or `CURLOPT_SSL_VERIFYHOST`. The debug block ([Debug.php:184](Block/Adminhtml/Debug.php)) explicitly sets `CURLOPT_SSL_VERIFYPEER => false` with no comment about it being debug-only.

**Fix:**

```php
// Helper/Curl.php — add to default options
'CURLOPT_SSL_VERIFYPEER' => true,
'CURLOPT_SSL_VERIFYHOST' => 2,
// Debug.php — guard with environment check
'CURLOPT_SSL_VERIFYPEER' => !$this->appState->getMode() === State::MODE_DEVELOPER,
```

Add a configurable "skip SSL verification" toggle in the developer/debug settings section only, never in production config.

---

#### SEC-02 · XSS in Admin Error Block

| | |
|---|---|
| **File** | [Block/Adminhtml/OidcErrorMessage.php](Block/Adminhtml/OidcErrorMessage.php) |
| **Risk** | Stored/reflected XSS in admin panel |

**Problem:** Error messages rendered without `escapeHtml()`. Any OIDC provider returning a crafted `error_description` can inject HTML/JS into the admin.

**Fix:**

```php
// In template: replace
echo $block->getErrorMessage();
// with
echo $block->escapeHtml($block->getErrorMessage());
```

---

#### SEC-03 · XSS in Hyva/Alpine Template

| | |
|---|---|
| **File** | [view/frontend/templates/account/authentication-popup.phtml](view/frontend/templates/account/authentication-popup.phtml) |
| **Risk** | XSS in frontend login popup on Hyva themes |

**Problem:** Alpine.js directive `x-html="message"` renders raw HTML. Must be `x-text="message"` to output text only. `x-html` is equivalent to `innerHTML` and allows script injection.

**Fix:**

```diff
- <div x-html="message"></div>
+ <div x-text="message"></div>
```

---

#### SEC-04 · SSRF via Unvalidated Discovery Endpoint URL

| | |
|---|---|
| **File** | [Controller/Adminhtml/OAuthsettings/Index.php:71-101](Controller/Adminhtml/OAuthsettings/Index.php) |
| **Risk** | Server-Side Request Forgery — admin can trigger requests to internal network endpoints |

**Problem:** The discovery URL is sanitized with the deprecated `FILTER_SANITIZE_URL` (removed in PHP 8.0 behavior) and passed directly to the HTTP client without host validation.

**Fix:**

```php
// Replace
$url = filter_var($url, FILTER_SANITIZE_URL);

// With strict validation
$url = filter_var(trim($url), FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED);
if ($url === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['https'], true)) {
    throw new \InvalidArgumentException('Discovery URL must be a valid HTTPS URL.');
}
// Block private/loopback ranges
$host = parse_url($url, PHP_URL_HOST);
if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '192.168.') || str_starts_with($host, '10.')) {
    throw new \InvalidArgumentException('Discovery URL must not point to an internal host.');
}
```

Also add proper null checks before accessing `$obj->authorization_endpoint` etc. (lines 90-101).

---

#### SEC-05 · Hardcoded Customer Domain in CSP Whitelist

| | |
|---|---|
| **Files** | [etc/csp_whitelist.xml](etc/csp_whitelist.xml) · [etc/adminhtml/csp_whitelist.xml](etc/adminhtml/csp_whitelist.xml) |
| **Risk** | Module ships with a specific customer's domain (`auth.casa-kuhl.de`) whitelisted globally for all installations |

**Fix:** Remove the hardcoded domain and replace with a dynamic CSP plugin that reads configured provider hostnames at request time:

```php
// New: Plugin/Csp/OidcCspPolicyPlugin.php
public function afterGetDefaultPolicy(PolicyInterface $subject, PolicyInterface $result): PolicyInterface
{
    foreach ($this->oauthUtility->getAllActiveProviderHosts() as $host) {
        $result->addByHost($host);
    }
    return $result;
}
```

---

### P1 — Fix Before Go-Live

---

#### SEC-06 · Instance-Level Auth Flag in OidcCredentialPlugin

| | |
|---|---|
| **File** | [Plugin/Auth/OidcCredentialPlugin.php:70-74](Plugin/Auth/OidcCredentialPlugin.php) |
| **Risk** | PHP-FPM process reuse causes `$isOidcAuth = true` from one request to bleed into the next |

**Problem:** `$isOidcAuth` and `$adapterLogged` are instance properties. In PHP-FPM with persistent processes, the same object instance is reused across requests.

**Fix:** Replace with request-scoped storage via `\Magento\Framework\HTTP\PhpEnvironment\Request` or reset the flag explicitly in `aroundStoreTokenData` before returning:

```php
// At the start of aroundStoreTokenData
$this->isOidcAuth = false;
$this->adapterLogged = false;
// then continue with existing logic
```

---

#### SEC-07 · Error Messages Passed as Base64 in URL

| | |
|---|---|
| **File** | [Controller/Actions/CheckAttributeMappingAction.php:313-315](Controller/Actions/CheckAttributeMappingAction.php) |
| **Risk** | Base64 is not encryption — error details (including mapped attribute values) are plaintext-readable via URL or server logs |

**Fix:** Store error in session flash message instead of URL parameter:

```php
// Replace
$encodedError = base64_encode($errorMessage);
$this->_redirect('admin?oidc_error=' . $encodedError);

// With
$this->messageManager->addErrorMessage($errorMessage);
$this->_redirect($this->backendUrl->getUrl('adminhtml/system_config/index'));
```

---

#### SEC-08 · Website ID Mismatch Not Enforced in Customer Callback

| | |
|---|---|
| **File** | [Controller/Actions/CustomerOidcCallback.php:201](Controller/Actions/CustomerOidcCallback.php) |
| **Risk** | Multi-website installations: a customer authenticated on store A can log in to store B |

**Fix:** Promote the log warning to an enforced exception:

```php
if ($customerModel->getWebsiteId() != $websiteId) {
    throw new \Magento\Framework\Exception\AuthenticationException(
        __('This account is not registered on this website.')
    );
}
```

---

#### SEC-09 · Weak Relay State Validation (String Containment)

| | |
|---|---|
| **File** | [Controller/Actions/ProcessUserAction.php:273-277](Controller/Actions/ProcessUserAction.php) |
| **Risk** | Open redirect — `http://evil.com?q=real-store.com` passes the `str_contains` check |

**Fix:** Use proper URL parsing:

```php
// Replace str_contains check with
$parsedRelay  = parse_url((string) $this->attrs['relayState']);
$parsedStore  = parse_url($store_url);
$safeHost     = $parsedStore['host'] ?? null;
$relayHost    = $parsedRelay['host'] ?? null;

if ($relayHost !== $safeHost) {
    $this->attrs['relayState'] = $store_url;
}
```

---

#### SEC-10 · Missing Transaction Rollback in AdminUserCreator

| | |
|---|---|
| **File** | [Model/Service/AdminUserCreator.php:107-119](Model/Service/AdminUserCreator.php) |
| **Risk** | Admin user is created without a role when role assignment fails, leaving a role-less privileged account |

**Fix:** Wrap both saves in a single transaction with explicit rollback:

```php
$connection->beginTransaction();
try {
    $this->userResource->save($user);
    $user->setRoleId($roleId);
    $this->userResource->save($user);
    $connection->commit();
} catch (\Exception $e) {
    $connection->rollBack();
    throw $e; // re-throw so caller knows creation failed
}
```

---

#### SEC-11 · Safety-Net Config Correction Not Persisted

| | |
|---|---|
| **File** | [Controller/Adminhtml/Signinsettings/Index.php:149-150](Controller/Adminhtml/Signinsettings/Index.php) |
| **Risk** | Dangerous config combination is silently corrected in memory but saved as-is; on next page load the dangerous state is back |

**Fix:**

```php
if ($mo_oauth_show_admin_link === 0 && $mo_disable_non_oidc_admin_login === 1) {
    $mo_disable_non_oidc_admin_login = 0;
    $this->messageManager->addWarningMessage(__('Non-OIDC login restriction was disabled because the OIDC button is hidden. Both settings have been saved safely.'));
}
// Then persist $mo_disable_non_oidc_admin_login as corrected
$this->oauthUtility->setStoreConfig(OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN, $mo_disable_non_oidc_admin_login, true);
```

---

### P2 — Harden After Launch

---

#### SEC-12 · Predictable Auto-Generated Password Structure

| | |
|---|---|
| **Files** | [Model/Service/AdminUserCreator.php:94-97](Model/Service/AdminUserCreator.php) · [Model/Service/CustomerUserCreator.php:189-192](Model/Service/CustomerUserCreator.php) |

**Problem:** Generated password is always `[28 alnum][2 special][2 digit]` — the structure is predictable.

**Fix:**

```php
$chars = $this->randomUtility->getRandomString(26)
    . $this->randomUtility->getRandomString(2, '!@#$%^&*')
    . $this->randomUtility->getRandomString(2, '0123456789');
// Shuffle so position of special chars is random
$password = str_shuffle($chars);
```

---

#### SEC-13 · Role ID Validation in Attribute Settings

| | |
|---|---|
| **File** | [Controller/Adminhtml/Attrsettings/Index.php:122-133](Controller/Adminhtml/Attrsettings/Index.php) |

**Fix:** Before storing role mappings, validate that each role ID is numeric and actually exists in `authorization_role`:

```php
foreach ($roleMappings as $mapping) {
    $roleId = (int) ($mapping['role_id'] ?? 0);
    if ($roleId <= 0 || !$this->roleRepository->get($roleId)) {
        throw new \InvalidArgumentException("Invalid role ID: {$roleId}");
    }
}
```

---

#### SEC-14 · Recursion Depth in sanitize() Has No Array-Size Guard

| | |
|---|---|
| **File** | [Helper/Data.php:511-526](Helper/Data.php) |

**Fix:** Add an array size guard before recursing to prevent memory exhaustion on pathological inputs:

```php
private function sanitize(mixed $input, int $depth = 0): mixed
{
    if ($depth > 10) {
        return '';
    }
    if (is_array($input)) {
        if (count($input) > 500) {
            $input = array_slice($input, 0, 500); // truncate oversized arrays
        }
        return array_map(fn($v) => $this->sanitize($v, $depth + 1), $input);
    }
    // ...
}
```

---

## 2. Functionality Enhancements

### FEAT-01 · PKCE Support (RFC 7636)

| | |
|---|---|
| **Files** | [Helper/OAuth/AuthorizationRequest.php](Helper/OAuth/AuthorizationRequest.php) · [Controller/Actions/ReadAuthorizationResponse.php](Controller/Actions/ReadAuthorizationResponse.php) |

The database column `pkce_enabled` already exists in `db_schema.xml` but the UI toggle and code path are missing.

**Implementation:**

1. Add admin toggle "Require PKCE" per app in `OAuthsettings`.
2. In `AuthorizationRequest::buildQuery()`: if PKCE enabled, generate `code_verifier` (43-128 random URL-safe chars), store in session, compute `code_challenge = base64url(sha256(verifier))`, add `code_challenge` and `code_challenge_method=S256` to authorization URL.
3. In `ReadAuthorizationResponse`: retrieve `code_verifier` from session, include as `code_verifier` in token request body.

```php
// AuthorizationRequest.php addition
if ($clientDetails['pkce_enabled']) {
    $verifier = $this->securityHelper->generateCodeVerifier(); // 64 random bytes → base64url
    $this->session->setOidcCodeVerifier($verifier);
    $params['code_challenge']        = $this->securityHelper->computeCodeChallenge($verifier);
    $params['code_challenge_method'] = 'S256';
}
```

---

### FEAT-02 · Back-Channel Logout (OIDC RP-Initiated Logout & Logout Token)

| | |
|---|---|
| **New files** | `Controller/Actions/BackChannelLogout.php` · `Model/Service/OidcSessionRegistry.php` |

**Implementation:**

1. New endpoint `POST /mooauth/actions/backchannel-logout` — validates the IdP-signed logout token (JWT), extracts `sub` and/or `sid`, looks up the matching Magento session, destroys it.
2. `OidcSessionRegistry` maps `(sub, sid) → Magento session ID` — stored in cache with TTL equal to token lifetime.
3. Add `end_session_endpoint` to the provider config table.
4. On Magento logout, redirect to `end_session_endpoint?post_logout_redirect_uri=...&id_token_hint=...` if configured.

---

### FEAT-03 · Token Refresh (Access Token Renewal)

| | |
|---|---|
| **New file** | `Model/Service/TokenRefreshService.php` |
| **Modified** | [Observer/OAuthLogoutObserver.php](Observer/OAuthLogoutObserver.php) |

Store `refresh_token` (encrypted) in customer session or a dedicated table. Implement `TokenRefreshService::refresh()` that:

1. Sends `grant_type=refresh_token` request to token endpoint.
2. Updates stored tokens.
3. Is called proactively when `access_token` TTL falls below a configurable threshold.

---

### FEAT-04 · Claims-Based Access Control

| | |
|---|---|
| **File** | [Controller/Actions/CheckAttributeMappingAction.php](Controller/Actions/CheckAttributeMappingAction.php) |
| **New UI** | Additional row in attribute mapping table |

Allow admins to define access rules based on OIDC claims, e.g.:

- "Deny login if `email_verified` ≠ `true`"
- "Allow only if `groups` contains `magento-users`"

**Implementation:**

1. Add `access_control_rules` JSON column to the provider table.
2. Add a "Login Restrictions" tab in attribute settings with a dynamic rule builder (claim → operator → value).
3. In `CheckAttributeMappingAction::execute()`, evaluate rules against `$flattenedAttrs` before proceeding to user creation/login. Throw `AuthenticationException` with a configurable denial message.

---

### FEAT-05 · Structured / JSON Logging

| | |
|---|---|
| **File** | [Helper/OAuthUtility.php](Helper/OAuthUtility.php) — `customlog()` method |

Replace the plain-text `customlog()` with structured JSON log entries that include:

```json
{
  "timestamp": "2025-01-15T10:23:00Z",
  "level": "info",
  "event": "oidc.token.exchange",
  "provider": "my-okta",
  "user": "user@example.com",
  "store": "1",
  "duration_ms": 142
}
```

This makes log ingestion into ELK/Datadog/Splunk straightforward without custom parsing. Sensitive fields (`client_secret`, `access_token`, `id_token`) must be masked:

```php
private const SENSITIVE_KEYS = ['client_secret', 'access_token', 'id_token', 'refresh_token', 'password'];
```

---

### FEAT-06 · Health Check / Connectivity Probe Endpoint

| | |
|---|---|
| **New** | `Controller/Adminhtml/Actions/HealthCheck.php` |

Admin-only AJAX endpoint at `/admin/oidc/actions/healthcheck?provider_id=1` that:

1. Fetches the discovery document (or pings the token endpoint).
2. Verifies JWKS endpoint reachability.
3. Returns JSON: `{ "status": "ok"|"degraded"|"unreachable", "latency_ms": 45, "jwks_cached": true }`.

Surface this in the admin config page as a "Test Connectivity" button with real-time status indicator.

---

### FEAT-07 · CLI Commands for Config Management

| | |
|---|---|
| **New** | `Console/Command/ExportOidcConfig.php` · `Console/Command/ImportOidcConfig.php` |

```bash
bin/magento oidc:config:export --provider-id=1 --output=oidc-backup.json
bin/magento oidc:config:import --input=oidc-backup.json --dry-run
```

Useful for DevOps pipelines, multi-environment deployments, and provider migration. Sensitive fields are encrypted in the export using Magento's `Encryptor`.

---

### FEAT-08 · GraphQL Support for Headless / Hyva

| | |
|---|---|
| **New** | `etc/schema.graphqls` · `Model/Resolver/OidcLoginUrl.php` |

Add a GraphQL query so headless frontends can retrieve the OIDC authorization URL:

```graphql
type Query {
    oidcLoginUrl(provider_id: Int, store_id: Int): OidcLoginUrlOutput
}
type OidcLoginUrlOutput {
    url: String!
    provider_name: String!
}
```

---

### FEAT-09 · Narrower Observer Scope (Performance)

| | |
|---|---|
| **File** | [Observer/SessionCookieObserver.php](Observer/SessionCookieObserver.php) · [etc/frontend/events.xml](etc/frontend/events.xml) |

The observer fires on `controller_action_predispatch` for **every** frontend request and modifies `SameSite` on all session cookies. This should be scoped to `/mooauth/` routes only:

```php
public function execute(Observer $observer): void
{
    $request = $observer->getEvent()->getRequest();
    if (!str_starts_with($request->getModuleName(), 'mooauth')) {
        return; // bail early — not an OIDC route
    }
    // existing cookie logic
}
```

---

## 3. Multi-Provider Support

### Overview

The most architecturally significant change. Currently the module supports exactly one OIDC provider per installation. The following changes transform it into a true multi-provider system.

---

### MP-01 · Database Schema

**[etc/db_schema.xml](etc/db_schema.xml)**

```xml
<!-- Add columns to miniorange_oauth_client_apps -->
<column name="provider_id"    xsi:type="int"     nullable="false" identity="true" comment="Provider ID (PK)" />
<column name="display_name"   xsi:type="varchar"  length="255"    nullable="false" comment="Human-readable provider label (e.g. Okta, Google)" />
<column name="is_active"      xsi:type="smallint" nullable="false" default="1"    comment="Enable/disable this provider" />
<column name="login_type"     xsi:type="varchar"  length="20"     nullable="false" default="customer" comment="customer | admin | both" />
<column name="sort_order"     xsi:type="smallint" nullable="false" default="0"    comment="Display order for SSO buttons" />
<column name="button_label"   xsi:type="varchar"  length="100"    nullable="true" comment="Override SSO button text" />
<column name="button_color"   xsi:type="varchar"  length="7"      nullable="true" comment="Button hex colour" />
```

All existing `id` references become `provider_id`. Existing single-row installations auto-migrate via `db_schema_whitelist.json` + setup upgrade.

---

### MP-02 · State Parameter

**[Helper/OAuth/AuthorizationRequest.php](Helper/OAuth/AuthorizationRequest.php)**
**[Helper/OAuthSecurityHelper.php](Helper/OAuthSecurityHelper.php)**

Extend the state string from 4 to 5 segments:

```
base64(relayState)|sessionId|base64(appName)|loginType|providerId
```

Segment `providerId` is an integer. `encodeRelayState()` and `decodeRelayState()` both updated; backward-compat: if 4 segments, default to provider 1.

**[Controller/Actions/ReadAuthorizationResponse.php](Controller/Actions/ReadAuthorizationResponse.php)**

Parse `providerId` from state, pass it to `getClientDetails($providerId)` instead of `getFirstItem()`.

---

### MP-03 · Config Lookup

**[Helper/Data.php](Helper/Data.php)** — all methods that currently return a single provider's config:

| Method | Change |
|---|---|
| `getClientDetails()` | Add `?int $providerId = null` param; if null use store-default |
| `getOAuthClientApps()` | Returns collection; callers updated to filter by `provider_id` |
| `setOAuthClientApps()` | Accept `provider_id` for INSERT vs UPDATE routing |
| `getSPInitiatedUrl()` | Embed `provider_id` in generated URL |

Add new helper:

```php
public function getDefaultProviderId(int $storeId, string $loginType = 'customer'): ?int
{
    // Reads from new config table: default_provider_id per store per login_type
}

public function getAllActiveProviders(string $loginType = 'customer'): array
{
    return $this->collection->create()
        ->addFieldToFilter('is_active', 1)
        ->addFieldToFilter('login_type', ['in' => [$loginType, 'both']])
        ->setOrder('sort_order', 'ASC')
        ->getItems();
}
```

---

### MP-04 · Authorization Entry Points

**[Controller/Actions/SendAuthorizationRequest.php](Controller/Actions/SendAuthorizationRequest.php)**
**[Controller/Adminhtml/Actions/SendAuthorizationRequest.php](Controller/Adminhtml/Actions/SendAuthorizationRequest.php)**

Accept `provider_id` GET parameter:

```php
$providerId = (int) $this->getRequest()->getParam('provider_id', $this->oauthUtility->getDefaultProviderId($storeId));
if ($providerId <= 0) {
    $this->messageManager->addErrorMessage(__('No OIDC provider configured.'));
    return $this->resultRedirectFactory->create()->setPath('/');
}
```

Stamp `providerId` into the state string.

---

### MP-05 · Frontend SSO Buttons

**[view/frontend/templates/customerssobutton.phtml](view/frontend/templates/customerssobutton.phtml)**

Replace single-button render with a loop:

```php
foreach ($block->getActiveProviders() as $provider):
    $url = $block->getSPInitiatedUrl($provider->getProviderId());
?>
<a href="<?= $block->escapeUrl($url) ?>"
   class="oidc-sso-btn"
   style="background-color: <?= $block->escapeHtml($provider->getButtonColor() ?: '#0066cc') ?>">
    <?= $block->escapeHtml($provider->getButtonLabel() ?: __('Login with %1', $provider->getDisplayName())) ?>
</a>
<?php endforeach; ?>
```

**[Block/OAuth.php](Block/OAuth.php)** — expose `getActiveProviders()` method.

---

### MP-06 · Admin UI — Provider Management

**New controllers:**
`Controller/Adminhtml/Provider/Index.php` (list)
`Controller/Adminhtml/Provider/Edit.php`
`Controller/Adminhtml/Provider/Save.php`
`Controller/Adminhtml/Provider/Delete.php`

**New grid block / UI component:**
`view/adminhtml/ui_component/oidc_provider_listing.xml`
`view/adminhtml/ui_component/oidc_provider_form.xml`

The form wraps the existing three settings tabs (OAuth Settings, Attribute Mapping, Sign-In Settings) under a provider context. Provider ID is passed as a hidden field and URL parameter throughout.

**[etc/adminhtml/routes.xml](etc/adminhtml/routes.xml)** — register new `oidc/provider/*` route.

---

### MP-07 · Attribute & Role Mapping Per Provider

**[Model/Service/AdminUserCreator.php](Model/Service/AdminUserCreator.php)**
**[Model/Service/CustomerUserCreator.php](Model/Service/CustomerUserCreator.php)**

Both classes accept `$providerId` in their constructors (or as a method argument). Role/group mappings are already stored in the per-row JSON columns — the only change is ensuring the correct row is loaded.

---

### MP-08 · Logout — Provider-Aware Redirect

**[Observer/OAuthLogoutObserver.php](Observer/OAuthLogoutObserver.php)**

At login time, store `oidc_provider_id` in the Magento session. On logout, read it back and use the correct provider's `end_session_endpoint`.

```php
// On login (CustomerOidcCallback.php / Oidccallback.php)
$this->session->setOidcProviderId($providerId);

// On logout (OAuthLogoutObserver)
$providerId = $this->session->getOidcProviderId();
$provider   = $this->oauthUtility->getClientDetails($providerId);
if (!empty($provider['end_session_endpoint'])) {
    // redirect to IdP logout
}
```

---

### MP-09 · Dynamic CSP (replaces SEC-05 for multi-provider)

Read all active provider hostnames and build CSP at request time — see SEC-05 above. With multiple providers, static XML is completely unworkable.

---

### MP-10 · Migration: Zero-Downtime Upgrade for Existing Installations

**[Setup/Patch/Data/MigrateToMultiProvider.php](Setup/Patch/Data/MigrateToMultiProvider.php)**

```php
// Existing single row: set provider_id=1, display_name=app_name, is_active=1
$connection->update($table, [
    'display_name' => new \Zend_Db_Expr('`app_name`'),
    'is_active'    => 1,
    'login_type'   => 'both',
    'sort_order'   => 0,
], 'provider_id IS NULL');
```

Backward-compat: if no `provider_id` is found in state, default to provider 1.

---

## 4. Refactoring & Code Quality

### REF-01 · Remove ObjectManager Anti-Pattern

| | |
|---|---|
| **File** | [Model/Auth/OidcCredentialAdapter.php:103-108](Model/Auth/OidcCredentialAdapter.php) |

`ObjectManager::getInstance()` is called in `__wakeup()` because the object is serialized. Fix by implementing `\Serializable` properly or by using constructor injection with a factory and registering the factory as a dependency that Magento can restore:

```php
// Inject UserFactory via constructor (already done at line 52)
// In __wakeup(), use object manager ONLY as last resort and document why:
private function restoreDependencies(): void
{
    // ObjectManager is acceptable in __wakeup as DI container is unavailable
    // during PHP deserialization. This is a known Magento pattern.
    if ($this->userFactory === null) {
        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->userFactory = $om->get(UserFactory::class);
    }
}
```

Add `@internal` docblock and a comment so future Rector runs don't incorrectly flag it.

---

### REF-02 · Extract Shared Name-Fallback Logic

| | |
|---|---|
| **Files** | [Controller/Actions/CheckAttributeMappingAction.php:439-510](Controller/Actions/CheckAttributeMappingAction.php) · [Controller/Actions/ProcessUserAction.php:321-333](Controller/Actions/ProcessUserAction.php) · [Model/Service/AdminUserCreator.php:140-152](Model/Service/AdminUserCreator.php) · [Model/Service/CustomerUserCreator.php](Model/Service/CustomerUserCreator.php) |

The email-prefix/domain name fallback is duplicated in 4 places. Extract to:

```php
// Helper/OAuthUtility.php or new Helper/NameExtractorHelper.php
public function extractNameFromEmail(string $email): array // ['first' => '...', 'last' => '...']
{
    $local = strstr($email, '@', true) ?: $email;
    $parts = preg_split('/[\s._\-]+/', $local, 2);
    return [
        'first' => ucfirst((string) ($parts[0] ?? $local)),
        'last'  => ucfirst((string) ($parts[1] ?? '')),
    ];
}
```

---

### REF-03 · Reduce Constructor Parameter Count

| | |
|---|---|
| **File** | [Controller/Actions/CheckAttributeMappingAction.php](Controller/Actions/CheckAttributeMappingAction.php) |

Constructor has 13 parameters — difficult to test and maintain. Introduce a `OidcAttributeMappingContext` value object or a dedicated `AttributeMappingConfig` service that bundles related config:

```php
// New: Model/Data/AttributeMappingConfig.php
class AttributeMappingConfig {
    public function __construct(
        public readonly string $emailAttribute,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $usernameAttribute,
        // ...
    ) {}
}
```

Inject the context object instead of 8 individual strings.

---

### REF-04 · Replace var_export() Logging

| | |
|---|---|
| **File** | [Controller/Adminhtml/Actions/SendAuthorizationRequest.php:90](Controller/Adminhtml/Actions/SendAuthorizationRequest.php) |

`var_export($params, true)` logs the full request including any sensitive POST data. Replace with masked JSON:

```php
$this->oauthUtility->customlog('Admin OIDC request params: ' . $this->oauthUtility->safeMaskAndEncode($params));
```

Where `safeMaskAndEncode()` redacts values whose keys match `SENSITIVE_KEYS` (see FEAT-05).

---

### REF-05 · Fix ACL Title

| | |
|---|---|
| **File** | [etc/acl.xml](etc/acl.xml) |

```diff
- <resource id="MiniOrange_OAuth2::..." title="Authelia OIDC" />
+ <resource id="MiniOrange_OAuth2::..." title="MiniOrange OIDC" />
```

---

### REF-06 · Run Rector on Full Codebase

`rector.php` is already configured for PHP 8.2 with `CODE_QUALITY`, `TYPE_DECLARATION`, `DEAD_CODE`, `PHP_81`, `PHP_82` sets. Run and commit the result:

```bash
vendor/bin/rector process --dry-run   # review
vendor/bin/rector process             # apply
```

Key improvements Rector will apply automatically:
- Add missing return types and property types throughout
- Replace deprecated `strpos()` null checks with `str_contains()` / `str_starts_with()`
- Convert `array_key_exists` checks to `isset` where safe
- Apply `match` expressions over long `switch` blocks
- Remove redundant type casts already covered by type declarations

Verify after running that Magento coding standard (PHPCS) still passes.

---

### REF-07 · Convert Inline HTML Template to PHTML

| | |
|---|---|
| **File** | [Controller/Actions/ShowTestResults.php:63-116](Controller/Actions/ShowTestResults.php) |

The 53-line inline HTML string is fragile and untestable. Move it to `view/adminhtml/templates/test_results.phtml` and render via a proper Block class. This also eliminates the `str_replace()` placeholder pattern (SEC risk).

---

### REF-08 · Configurable JWKS Cache TTL

| | |
|---|---|
| **File** | [Helper/JwtVerifier.php](Helper/JwtVerifier.php) |

Hardcoded `86400` seconds. Add to provider config:

```xml
<column name="jwks_cache_ttl" xsi:type="int" nullable="false" default="86400" comment="JWKS key cache TTL in seconds" />
```

---

### REF-09 · Remove/Translate German Comments

| | |
|---|---|
| **Files** | [Helper/Curl.php:81](Helper/Curl.php) · [Observer/SessionCookieObserver.php](Observer/SessionCookieObserver.php) · [rector.php](rector.php) |

Translate all non-English comments to English for consistency and open-source readability.

---

### REF-10 · Resolve the "TODO Security Issue" Comment

| | |
|---|---|
| **File** | [Helper/OAuth/AuthorizationRequest.php:67](Helper/OAuth/AuthorizationRequest.php) |

There is a TODO marking a security concern. Resolve it as part of the SEC-09 relay state fix.

---

## 5. Tests

### TEST-01 · Unit Tests for Model/Service Layer

Create `Test/Unit/Model/Service/` with PHPUnit tests for:

| Class | Priority test cases |
|---|---|
| `AdminUserCreator` | Role assignment, transaction rollback, duplicate user, missing role |
| `CustomerUserCreator` | Email extraction, name fallback, DOB parsing, address mapping, auto-create disabled |
| `OidcAuthenticationService` | Email attribute resolution, recursive search, flattening |
| `TokenRefreshService` *(new)* | Refresh flow, expired token handling |

Use PHPUnit mocks for `UserResource`, `CustomerRepository`, `OauthUtility`.

---

### TEST-02 · Unit Tests for Helper Layer

| Class | Priority test cases |
|---|---|
| `OAuthSecurityHelper` | Nonce creation/validation, state encode/decode, redirect URL validation |
| `JwtVerifier` | Valid JWT, expired JWT, wrong audience, missing key, JWKS cache hit/miss |
| `Data` (Helper) | `getClientDetails()`, encryption detection, `sanitize()` depth limit |
| `Curl` | Request method selection, header construction, error response |

---

### TEST-03 · Unit Tests for Controllers

| Class | Priority test cases |
|---|---|
| `ReadAuthorizationResponse` | State mismatch, missing code, JWKS verify path, user_info path |
| `CustomerOidcCallback` | Nonce valid/invalid, website mismatch, auto-create on/off |
| `ProcessUserAction` | Relay state validation, race condition on auto-create |
| `CheckAttributeMappingAction` | Admin role mapping, missing required attrs, debug data |

---

### TEST-04 · Unit Tests for Plugins

| Class | Priority test cases |
|---|---|
| `OidcCredentialPlugin` | Flag isolation between calls, adapter returned when marker present |
| `AdminLoginRestrictionPlugin` | Block non-OIDC when disabled, allow when OIDC button hidden |
| `OidcCaptchaBypassPlugin` | Bypass only when `oidc_auth === true`, normal flow otherwise |

---

### TEST-05 · Integration Tests

Using Magento's integration test framework:

1. **Full customer login flow:** Mock IdP responses, assert customer session is created.
2. **Full admin login flow:** Assert admin session with correct role.
3. **Multi-provider routing:** Two providers configured, assert state encodes correct provider ID and correct provider is loaded in callback.
4. **Auto-create disabled:** Assert 403 redirect with proper error message.
5. **PKCE flow:** Assert `code_challenge` present in authorization URL, `code_verifier` sent in token request.

---

### TEST-06 · Mock OIDC Provider Setup

Add a `docker-compose.test.yml` with [Dex](https://dexidp.io/) or [oauth2-proxy](https://github.com/oauth2-proxy/oauth2-proxy) as a local OIDC provider for integration and E2E tests. Configure in CI:

```yaml
services:
  dex:
    image: ghcr.io/dexidp/dex:latest
    volumes:
      - ./Test/Fixtures/dex.yaml:/etc/dex/config.yaml
    ports:
      - "5556:5556"
```

---

### TEST-07 · Security Regression Tests

After applying SEC-01 through SEC-14, add regression tests that would fail if the issues re-appeared:

- Verify SSL peer verification is `true` in `Curl` options.
- Assert that relay state with a foreign host is rejected (SEC-09).
- Assert that `x-html` is not present in Hyva templates (static analysis / grep test).
- Assert `FILTER_SANITIZE_URL` is not used anywhere in the codebase.

```bash
# Example: fail CI if FILTER_SANITIZE_URL reappears
grep -r 'FILTER_SANITIZE_URL' --include='*.php' . && exit 1 || exit 0
```

---

## 6. Implementation Sequence

The items below are ordered to minimize merge conflicts and allow incremental deployment.

```
Sprint 1 — Critical Security (no new dependencies)
├── SEC-01  SSL verification in Curl.php + JwtVerifier.php
├── SEC-02  Escape error message in OidcErrorMessage.php
├── SEC-03  x-html → x-text in Hyva template
├── SEC-04  SSRF fix in OAuthsettings (FILTER_VALIDATE_URL + host blocklist)
├── SEC-07  Error message → session flash (remove base64 URL param)
├── SEC-08  Enforce website ID mismatch in CustomerOidcCallback
├── SEC-09  Relay state: str_contains → URL parse
└── SEC-11  Persist safety-net config correction

Sprint 2 — High Security + Refactoring
├── SEC-05  Dynamic CSP plugin (removes hardcoded domain)
├── SEC-06  Instance-level flag fix in OidcCredentialPlugin
├── SEC-10  Transaction rollback in AdminUserCreator
├── REF-01  ObjectManager wakeup comment
├── REF-02  Extract name-fallback to shared helper
├── REF-04  Replace var_export() logging
├── REF-05  Fix ACL title
└── REF-09  Translate German comments

Sprint 3 — Rector + Tests
├── REF-06  Run Rector (PHP 8.2 pass)
├── REF-07  Extract inline HTML to PHTML template
├── TEST-01 Unit tests: Model/Service
├── TEST-02 Unit tests: Helpers
└── TEST-07 Security regression tests

Sprint 4 — Functionality Enhancements
├── FEAT-01 PKCE support
├── FEAT-05 Structured/JSON logging
├── FEAT-06 Health check endpoint
├── FEAT-09 Narrow observer scope
└── TEST-03 Controller unit tests + TEST-04 Plugin unit tests

Sprint 5 — Multi-Provider (Core)
├── MP-01  db_schema.xml + migration patch
├── MP-02  State parameter: add provider_id segment
├── MP-03  Config lookup: add providerId param to getClientDetails()
├── MP-04  SendAuthorizationRequest: accept provider_id
├── MP-10  Migration: zero-downtime upgrade for existing installs
└── TEST-05 Integration test: multi-provider routing

Sprint 6 — Multi-Provider (UI + Full Feature)
├── MP-05  Frontend: SSO button loop
├── MP-06  Admin: Provider management grid + form
├── MP-07  Attribute/role mapping per provider
├── MP-08  Logout: provider-aware redirect
├── MP-09  Dynamic CSP for multi-provider
└── FEAT-07 CLI config export/import

Sprint 7 — Advanced Features + E2E
├── FEAT-02 Back-channel logout
├── FEAT-03 Token refresh
├── FEAT-04 Claims-based access control
├── FEAT-08 GraphQL resolver
├── TEST-06 Mock OIDC provider (Dex) in CI
└── TEST-05 Full E2E integration suite
```

---

*Last updated: 2026-02-20*
*Analysis based on: Controller/, Model/, Plugin/, Helper/, Observer/, Block/, view/ — full codebase scan*
