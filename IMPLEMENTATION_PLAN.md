# Implementation Plan: OIDC Module Security & Quality Remediation

## Context

Full remediation of 30 issues (4 Critical, 6 High, 9 Medium, 8 Low, 3 Architectural) found during code review of the `MiniOrange_OAuth` Magento 2 module at `/var/www/html/github/OIDC/`. The module provides OAuth 2.0 / OIDC SSO for customer and admin login. The most urgent issue is an unauthenticated admin account takeover (C1).

**Constraints:** Magento 2.4.7-2.4.8, PHP 8.1-8.4, no new composer dependencies, backward compatibility required.

---

## Phase 1: Critical Security Fixes (C1 + H3 + H6 + C2)

These 4 issues form an attack chain and share a solution: a cryptographic nonce + state token system.

### Step 1.1: Create `Helper/OAuthSecurityHelper.php` (NEW FILE)

Central security helper providing:
- **`createAdminLoginNonce(string $email): string`** — Stores email in Magento cache keyed by random 32-char hex nonce (TTL: 120s). Fixes C1+H3.
- **`redeemAdminLoginNonce(string $nonce): ?string`** — Returns email and deletes cache entry (one-time use). Returns null if expired/invalid.
- **`createStateToken(string $sessionId): string`** — Generates CSRF state token stored in cache keyed by `hash('sha256', $sessionId . $token)` (TTL: 600s). Fixes H6.
- **`validateStateToken(string $sessionId, string $stateToken): bool`** — Validates + deletes (one-time). Returns false if missing/expired.
- **`validateRedirectUrl(string $url, string $fallback = '/'): string`** — Allows relative paths or same-host URLs. Blocks external domains. Fixes C2.

Uses: `Magento\Framework\App\CacheInterface`, `Magento\Framework\Serialize\SerializerInterface`, `Magento\Framework\Math\Random`.

### Step 1.2: Wire into `CheckAttributeMappingAction.php`

Inject `OAuthSecurityHelper` in constructor.

**Lines 149-151 and 190-191** — Replace `'email' => $userEmail` with nonce:
```php
$nonce = $this->securityHelper->createAdminLoginNonce($userEmail);
$adminCallbackUrl = $this->backendUrl->getUrl('mooauth/actions/oidccallback', ['nonce' => $nonce]);
```

### Step 1.3: Rewrite `Oidccallback.php` email retrieval

Inject `OAuthSecurityHelper` in constructor.

**Lines 73-83** — Replace `$email = $this->request->getParam('email')` with:
```php
$nonce = $this->request->getParam('nonce');
if (empty($nonce)) { return $this->redirectToLoginWithError(__('...')); }
$email = $this->securityHelper->redeemAdminLoginNonce($nonce);
if ($email === null) { return $this->redirectToLoginWithError(__('...')); }
```

### Step 1.4: Add state token to OAuth flow

**`Controller/Actions/SendAuthorizationRequest.php` line 72** — Add 5th segment:
```php
$stateToken = $this->securityHelper->createStateToken($currentSessionId);
$relayState = urlencode($relayState) . '|' . $currentSessionId . '|' . urlencode($app_name) . '|' . OAuthConstants::LOGIN_TYPE_CUSTOMER . '|' . $stateToken;
```

**`Controller/Adminhtml/Actions/SendAuthorizationRequest.php` line 96** — Same, with `LOGIN_TYPE_ADMIN` + add missing `urlencode()` (also fixes M9).

**`Controller/Actions/ReadAuthorizationResponse.php` lines 94-101** — Parse 5th part and validate:
```php
$stateToken = isset($parts[4]) ? $parts[4] : '';
if (empty($stateToken) || !$this->securityHelper->validateStateToken($originalSessionId, $stateToken)) {
    // Return error redirect based on loginType
}
```

### Step 1.5: Validate relayState at every redirect point (C2)

Apply `$this->securityHelper->validateRedirectUrl()` at:
- `SendAuthorizationRequest.php` line 59 (customer) + error paths at lines 84-90, 103-110
- `Adminhtml/Actions/SendAuthorizationRequest.php` line 59, 73
- `CustomerLoginAction.php` line 54
- `ReadAuthorizationResponse.php` line 199 (test redirect)

Inject `OAuthSecurityHelper` into `CustomerLoginAction` constructor.

### Step 1.6: Register all DI in `etc/di.xml`

Add `<type>` blocks for `OAuthSecurityHelper` and inject it into `CheckAttributeMappingAction`, `Oidccallback`, both `SendAuthorizationRequest` controllers, `ReadAuthorizationResponse`, `CustomerLoginAction`.

---

## Phase 2: JWT Signature Verification (C4)

### Step 2.1: Create `Helper/JwtVerifier.php` (NEW FILE)

Pure PHP JWT verification using `openssl_verify()` — no composer dependencies.

- **`verifyAndDecode(string $idToken, string $jwksUrl, ?string $issuer, ?string $audience): ?array`** — Fetches JWKS, finds matching key (by `kid`/`alg`), verifies RSA signature (RS256/RS384/RS512), validates `exp`/`iss`/`aud`. Returns decoded payload or null.
- **`decodeWithoutVerification(string $idToken): ?array`** — Fallback for providers without JWKS configured.
- Private helpers: `fetchJwks()`, `findPublicKey()`, `jwkToPem()` (converts JWK modulus/exponent to PEM via ASN.1 DER encoding), `base64UrlDecode()`.

Uses: `Helper/Curl` (instance, from Phase 3), `Helper/OAuthUtility`.

### Step 2.2: Modify `ReadAuthorizationResponse.php` lines 156-162

Inject `JwtVerifier` in constructor.

Replace raw `base64_decode` with:
```php
$jwksEndpoint = $clientDetails['jwks_endpoint'] ?? '';
if (!empty($jwksEndpoint)) {
    $userInfoResponseData = $this->jwtVerifier->verifyAndDecode($idToken, $jwksEndpoint, null, $clientID);
    if ($userInfoResponseData === null) { /* return error */ }
} else {
    // Log WARNING: no JWKS configured, using insecure fallback
    $userInfoResponseData = $this->jwtVerifier->decodeWithoutVerification($idToken);
}
```

Backward-compatible: existing installs without `jwks_endpoint` get a log warning but continue working.

---

## Phase 3: XSS Fixes (C3 + H1)

### Step 3.1: Escape output in `ShowTestResults.php` (C3)

**`getTableContent()` lines 209-213** — Escape both keys and values:
```php
$escapedKey = htmlspecialchars((string)($key ?? ''), ENT_QUOTES, 'UTF-8');
$escapedValues = array_map(fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'), $value);
$tableContent .= str_replace("{{key}}", $escapedKey, str_replace("{{value}}", implode("<br/>", $escapedValues), $this->tableContent));
```

**`processTemplateContent()` line 194** — Escape greeting name with `htmlspecialchars()`.

### Step 3.2: Fix logout observer XSS (`OAuthLogoutObserver.php` line 72) (H1)

Replace raw `<script>` injection with URL-validated, JSON-escaped output:
```php
$parsed = parse_url($logoutUrl);
if ($parsed === false || !in_array($parsed['scheme'] ?? '', ['https', 'http'], true)) { return; }
$temp = '<script>window.location = ' . json_encode($logoutUrl, JSON_UNESCAPED_SLASHES) . ';</script>';
```

---

## Phase 4: Validation & Observer Fixes (H5 + H2)

### Step 4.1: Fix `validateUserInfoData` in `ProcessResponseAction.php` lines 176-183 (H5)

Check both array and object formats:
```php
if (is_object($userInfo) && isset($userInfo->error)) { throw new IncorrectUserInfoDataException(); }
if (is_array($userInfo) && isset($userInfo['error'])) { throw new IncorrectUserInfoDataException(); }
if (empty($userInfo)) { throw new IncorrectUserInfoDataException(); }
```

### Step 4.2: Scope `SessionCookieObserver` to SSO-only (H2)

**Remove** `SessionCookieObserver` from these event bindings:
- `etc/events.xml` — Remove entirely (global scope)
- `etc/frontend/events.xml` — Remove from `controller_action_predispatch` (line 8) and `controller_front_send_response_before` (lines 16-18)
- `etc/adminhtml/events.xml` — Remove from `controller_action_predispatch` (line 8) and `controller_front_send_response_before` (lines 12-13)

Keep the existing targeted calls in `SendAuthorizationRequest::execute()` and `ReadAuthorizationResponse::execute()` which already call `$this->sessionHelper->configureSSOSession()`.

**Simplify `SessionHelper::forceSameSiteNone()`** — Remove the dangerous `header_remove('Set-Cookie')` + re-add-all loop. Only update the PHP session cookie:
```php
if (session_status() !== PHP_SESSION_ACTIVE) { return; }
$sessionName = session_name(); $sessionId = session_id();
if (empty($sessionId) || !isset($_COOKIE[$sessionName])) { return; }
// Set only the session cookie with SameSite=None via CookieManager
```

---

## Phase 5: Curl Refactoring (H4)

### Step 5.1: Convert `Helper/Curl.php` to instance methods

Inject `Magento\Framework\HTTP\Client\CurlFactory` and `OAuthUtility`. Add:
- `sendAccessTokenRequest(...)` — instance method, timeout 30s
- `sendUserInfoRequest(...)` — instance method
- `fetchJwks(...)` — new method needed by `JwtVerifier`
- **Keep** static `mo_send_access_token_request()` and `mo_send_user_info_request()` as `@deprecated` backward-compat wrappers using `ObjectManager::getInstance()`.

Set `CURLOPT_TIMEOUT => 30` (also fixes M8).

### Step 5.2: Update `ReadAuthorizationResponse.php` callers

Replace `Curl::mo_send_access_token_request(...)` with `$this->curl->sendAccessTokenRequest(...)`.
Replace `Curl::mo_send_user_info_request(...)` with `$this->curl->sendUserInfoRequest(...)`.

Register `Curl` in `di.xml`.

---

## Phase 6: Medium Code Quality Fixes

### Step 6.1: Add `getClientDetailsByAppName()` to `Helper/Data.php` (M4)

```php
public function getClientDetailsByAppName($appName)
{
    $collection = $this->getOAuthClientApps();
    $collection->addFieldToFilter('app_name', $appName);
    return $collection->getSize() > 0 ? $collection->getFirstItem()->getData() : null;
}
```

Replace the duplicated foreach-lookup pattern in 5 files:
- `ReadAuthorizationResponse.php` lines 106-127
- `SendAuthorizationRequest.php` (customer) lines 95-101
- `SendAuthorizationRequest.php` (admin) lines 65-71
- `OAuthUtility.php` `getClientDetails()` lines 491-497
- `Signinsettings/Index.php` lines 108-114

### Step 6.2: Fix `processGroupName` type overwrite (M3)

**`CheckAttributeMappingAction.php` line 408** — Change `$this->groupName = []` to `$attrs[$this->groupName] = []`.

### Step 6.3: Replace German messages with English `__()` (M6)

**`Oidccallback.php`** — 5 locations (lines 81, 97, 112, 145, 167):
- `'Authentifizierung fehlgeschlagen...'` → `__('Authentication failed: No email address received from the OIDC provider.')`
- `'Admin-Zugang verweigert...'` → `__('Admin access denied: No administrator account found for email "%1"...', $email)`
- `'Willkommen zurück...'` → `__('Welcome back, %1!', ...)`
- `'Die Anmeldung über Authelia...'` → `__('OIDC authentication failed. Please try again or contact your administrator.')`

**`ReadAuthorizationResponse.php` line 125** — `'Ungültige OAuth-App-Konfiguration...'` → proper redirect with `__('Invalid OAuth app configuration...')`.

### Step 6.4: Simplify `isLogEnable()` (M7)

**`OAuthUtility.php` lines 448-461** — Replace with single line:
```php
public function isLogEnable() { return (bool) $this->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG); }
```
The second `getStoreConfig('miniorange/oauth/debug_log')` call was double-prefixing (yielding `miniorange/oauth/miniorange/oauth/debug_log`) and always returning null.

### Step 6.5: Fix hardcoded admin path in `SessionHelper.php` (M2)

Inject `Magento\Backend\Model\UrlInterface` in constructor. Replace `strpos($name, 'admin')` with `strpos($name, $this->backendUrl->getAreaFrontName())`.

---

## Phase 7: Low Priority Cleanup

### Step 7.1: Remove dead code
- `ProcessResponseAction.php` line 20: Remove `private $testAction;` (L1)
- `ProcessUserAction.php` lines 21, 62, 82: Remove `$checkIfMatchBy` field + both assignments (M1)
- `ProcessUserAction.php` lines 171-177: Delete `generateEmail()` method (M5)
- `OAuthUtility.php` lines 184-191: Delete `validatePhoneNumber()` referencing non-existent `MoIDPConstants` (L3)
- `CheckAttributeMappingAction.php` lines 233-264: Delete "moved to service" comment stubs (L2)

### Step 7.2: Remove unused imports (L7)
- `OAuthsettings/Index.php` line 10: Remove `use ...SAML2Utilities`
- `Signinsettings/Index.php` line 9: Remove `use ...SAML2Utilities` (if present)
- `ProcessResponseAction.php` line 5: Remove `use ...UserEmailNotFoundException`
- `OAuthLogoutObserver.php` line 7: Remove `use ...ReadAuthorizationResponse`

### Step 7.3: Fix SAML references (L8)
- `ProcessResponseAction.php` docblock lines 12-16: Replace "SAML" with "OAuth/OIDC"
- `Signinsettings/Index.php` line 179: Rename `$mo_saml_enable_login_redirect` to `$mo_oauth_enable_login_redirect`

### Step 7.4: Fix `log_debug` bug (L4)
**`OAuthUtility.php` line 470**: Change `print_r($obj, true)` to `print_r($msg, true)`.

### Step 7.5: Replace `setBody()` errors with proper redirects (L5)
**`ReadAuthorizationResponse.php` lines 164, 168**: Replace `$this->getResponse()->setBody("Invalid response...")` with redirect to login page with `oidc_error` query param (matching existing error pattern).

### Step 7.6: Simplify `checkIfRequiredFieldsEmpty` (L6)
**`BaseAction.php` lines 37-48**: Add backward-compatible two-parameter overload accepting `(array $requiredKeys, array $params)`.

### Step 7.7: Document architecture tech debt (A2 + A3)
Add TODO comments in `ReadAuthorizationResponse.php` (controller chaining) and `ProcessUserAction.php` (eager config reads).

---

## Files Changed Summary

| File | Type | Issues Fixed |
|------|------|-------------|
| `Helper/OAuthSecurityHelper.php` | **NEW** | C1, H3, H6, C2 |
| `Helper/JwtVerifier.php` | **NEW** | C4 |
| `Helper/Curl.php` | Rewrite | H4, M8 |
| `Helper/Data.php` | Add method | M4 |
| `Helper/OAuthUtility.php` | Edit | M7, L3, L4 |
| `Helper/SessionHelper.php` | Edit | H2, M2 |
| `Controller/Actions/ReadAuthorizationResponse.php` | Edit | C4, H6, C2, M4, M6, L5 |
| `Controller/Actions/SendAuthorizationRequest.php` | Edit | H6, C2 |
| `Controller/Actions/CheckAttributeMappingAction.php` | Edit | C1, H3, M3, L2 |
| `Controller/Actions/ProcessResponseAction.php` | Edit | H5, L1, L7, L8 |
| `Controller/Actions/ProcessUserAction.php` | Edit | M1, M5 |
| `Controller/Actions/CustomerLoginAction.php` | Edit | C2 |
| `Controller/Actions/ShowTestResults.php` | Edit | C3 |
| `Controller/Actions/BaseAction.php` | Edit | L6 |
| `Controller/Adminhtml/Actions/Oidccallback.php` | Edit | C1, M6 |
| `Controller/Adminhtml/Actions/SendAuthorizationRequest.php` | Edit | H6, C2, M9, M4 |
| `Controller/Adminhtml/OAuthsettings/Index.php` | Edit | L7 |
| `Controller/Adminhtml/Signinsettings/Index.php` | Edit | L7, L8, M4 |
| `Observer/OAuthLogoutObserver.php` | Edit | H1, L7 |
| `Observer/SessionCookieObserver.php` | Untouched | (bindings removed) |
| `etc/di.xml` | Edit | DI for new classes |
| `etc/events.xml` | Edit | H2 |
| `etc/frontend/events.xml` | Edit | H2 |
| `etc/adminhtml/events.xml` | Edit | H2 |

---

## Verification Plan

After implementation, run:
```bash
php bin/magento setup:di:compile   # Verify DI
php bin/magento cache:flush
```

Then test:
1. **C1**: Call `/admin/mooauth/actions/oidccallback?email=admin@example.com` directly → expect rejection
2. **C2**: SSO flow with `relayState=https://evil.com` → expect redirect to `/`
3. **C3**: IDP sending `<script>alert(1)</script>` as claim value → expect escaped text in test results
4. **C4**: SSO with id_token flow → check `mo_oauth.log` for "Signature verification PASSED"
5. **H2**: Load any non-OIDC page → confirm `SameSite=None` NOT applied globally to all cookies
6. **H6**: Tamper with `state` parameter in OAuth callback → expect "Security validation error"
7. **Full flow**: Complete customer SSO login + admin SSO login end-to-end
