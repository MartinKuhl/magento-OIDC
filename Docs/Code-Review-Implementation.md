# Code Review — Implementation Plan

**Based on**: [Code-Review.md](Code-Review.md) (2026-04-07)
**Scope**: Sections 1–6 (Critical through Convention Violations). Section 7 (Future Improvements) is addressed separately.
**Total issues**: 66 (excluding 6 future-improvement items)

---

## Prioritization Strategy

Issues are grouped into 4 implementation phases:

| Phase | Focus | Issues | Risk if Deferred |
|-------|-------|--------|------------------|
| **Phase 1** | Security-critical fixes | C-01 to C-05, H-01 to H-12 | Active exploitability, data loss, runtime crashes |
| **Phase 2** | Logic correctness & data integrity | M-01 to M-25 | Subtle bugs, race conditions, data corruption |
| **Phase 3** | Low-severity hardening | L-01 to L-18 | Defense-in-depth, log hygiene, edge cases |
| **Phase 4** | Performance & conventions | P-01 to P-06, CV-01 to CV-06 | Performance at scale, maintainability |

---

## Phase 1 — Security-Critical Fixes

### 1.1 JWT Verification Hardening

**Issues**: C-01, C-02, L-02
**File**: `Helper/JwtVerifier.php`

**C-01 — Strict kid matching (line 314-317)**

Replace:
```php
if ($kid !== null && isset($key['kid']) && $key['kid'] !== $kid) {
    continue;
}
```
With:
```php
if ($kid !== null) {
    if (!isset($key['kid']) || $key['kid'] !== $kid) {
        continue;
    }
}
```

**C-02 — JWKS fetch HTTP status validation (after line 278)**

After the curl `read()` call and before the empty-response check, add:
```php
$httpStatus = (int) $curl->getInfo(CURLINFO_HTTP_CODE);
if ($httpStatus !== 200) {
    $this->oauthUtility->customlog(
        "JwtVerifier: JWKS fetch returned HTTP " . $httpStatus . " from: " . $jwksUrl
    );
    return null;
}
```

**L-02 — Explicit weak algorithm rejection (line 86-90)**

Before the `!isset($algMap[$alg])` check, add:
```php
$weakAlgorithms = ['NONE', 'HS256', 'HS384', 'HS512'];
if (in_array($alg, $weakAlgorithms, true)) {
    $this->oauthUtility->customlog("JwtVerifier: REJECTED weak/forbidden algorithm: " . $alg);
    return null;
}
```

**Tests**: Update `Test/Unit/Helper/JwtVerifierTest.php`:
- Add test: JWT with `kid` in header but no matching `kid` in JWKS should be rejected
- Add test: JWT with `kid` in header should NOT fall back to JWKS keys without `kid`
- Add test: JWKS endpoint returning HTTP 403/500 returns null
- Add test: `alg: none`, `alg: HS256` explicitly rejected with log message

---

### 1.2 Missing Method & Duplicate Key

**Issues**: C-03, C-05
**File**: `Helper/OAuthUtility.php`

**C-03 — Add `getClientDetailsById()` method**

Add delegation method (after existing `getClientDetails()`):
```php
/**
 * Return provider configuration by ID.
 *
 * @param  int $providerId
 * @return array<string, mixed>|null
 */
public function getClientDetailsById(int $providerId): ?array
{
    return $this->providerRepository->getClientDetailsById($providerId);
}
```

Verify `OidcProviderRepository` already has this method. If not, implement it there first.

**C-05 — Fix duplicate `email_attribute` key (line 648-650)**

Line 650 is a duplicate of line 648. Audit the calling code to determine which key was intended. Most likely fix — line 650 should be:
```php
$provider['username_attribute'] ?? OAuthConstants::DEFAULT_MAP_USERN,
```

**Tests**: Verify `OAuthLogoutObserver` no longer crashes on customer logout. Add unit test for `getClientDetailsById()`.

---

### 1.3 Session ID Validation for Redis

**Issue**: C-04
**File**: `Model/Service/SessionDestructionService.php`

**Replace line 52:**
```php
// Before:
if (!preg_match('/^[a-zA-Z0-9,-]+$/', $phpSessionId)) {
// After:
if (!preg_match('/^[a-zA-Z0-9_.,:-]+$/', $phpSessionId)) {
```

The broader pattern allows underscores, dots, and colons which Redis and Memcached session handlers use.

**Tests**: Add test cases with Redis-style session IDs (e.g., `sess_abc123`, `a1b2c3.d4e5f6`). Verify they pass validation. Verify path-traversal attempts (`../etc/passwd`) are still rejected.

---

### 1.4 Atomic Cache Resilience

**Issues**: H-01, H-02
**Files**: `Model/Cache/FileAtomicCache.php`, `Model/Cache/RedisAtomicCache.php`

**H-01 — FileAtomicCache: Add class-level warning**

Add PHPDoc warning to the class:
```php
/**
 * ...existing docblock...
 *
 * WARNING: This implementation is NOT truly atomic — load() and remove()
 * are separate operations. Use RedisAtomicCache for production deployments
 * where TOCTOU protection is required.
 */
```

**H-02 — RedisAtomicCache: Log critical warning on fallback (line 86)**

Replace silent fallback with logged warning:
```php
// Non-atomic fallback — log critical warning
$this->logger->critical(
    'M2Oidc: RedisAtomicCache falling back to non-atomic getAndDelete. '
    . 'Token replay protection is degraded. Check Redis connectivity.'
);
$value = $this->cache->load($identifier);
// ... rest of fallback
```

Inject `\Psr\Log\LoggerInterface` if not already available.

---

### 1.5 HeadlessOidcCallback Rate Limiting

**Issue**: H-03
**File**: `Controller/Actions/HeadlessOidcCallback.php`

Add rate limiter injection to constructor and check at start of `execute()`:

```php
// At the start of execute(), before nonce check:
$clientIp = ($request instanceof \Magento\Framework\App\Request\Http)
    ? (string) $request->getClientIp()
    : '';
if (!$this->rateLimiter->isAllowed($clientIp)) {
    $this->oauthUtility->customlog("HeadlessOidcCallback: Rate limit exceeded for IP: " . $clientIp);
    return $this->buildErrorPage('Too many requests. Please try again later.');
}
```

Update `etc/di.xml` to inject `OidcRateLimiter` into the constructor.

**Tests**: Add test verifying rate limiter is called and returns error page when exceeded.

---

### 1.6 Open Redirect on Error Path

**Issue**: H-04
**File**: `Controller/Actions/ReadAuthorizationResponse.php` ~line 243-258

The `$relayState` from decoded state is used for login URL construction without validation. The current code already redirects to `admin` or `customer/account/login` with a query parameter, which is safe (Magento URL builder). However, verify that `$relayState` is never used as a raw redirect target in the error path. If it is, wrap with:
```php
$safeRelayState = $this->securityHelper->validateRedirectUrl(
    $relayState,
    $this->oauthUtility->getCustomerLoginUrl()
);
```

Audit all `$this->_redirect()` calls in the file to ensure none use unvalidated `$relayState`.

---

### 1.7 Admin Nonce Cookie Order

**Issue**: H-05
**File**: `Controller/Adminhtml/Actions/Oidccallback.php` ~line 155-179

Move cookie deletion to after successful nonce redemption:

```php
$nonce = $this->cookieManager->getCookie('oidc_admin_nonce');

if (empty($nonce)) {
    // ... error handling ...
}

$email = $this->securityHelper->redeemAdminLoginNonce($nonce);
if ($email === null) {
    // ... error handling ...
}

// Delete cookie AFTER successful redemption
$adminPath = '/' . $this->backendUrl->getAreaFrontName();
$cookieMeta = $this->cookieMetadataFactory->createCookieMetadata()->setPath($adminPath);
$this->cookieManager->deleteCookie('oidc_admin_nonce', $cookieMeta);
```

---

### 1.8 Email Format Validation

**Issue**: H-06
**Files**: `Controller/Actions/ReadAuthorizationResponse.php`, `Model/Service/OidcAuthenticationService.php`

After email extraction in `OidcAuthenticationService::extractEmail()`, add:
```php
if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $this->oauthUtility->customlog(
        "OidcAuthenticationService: Invalid email format from OIDC response: " . $email
    );
    return null;
}
```

This prevents invalid emails from propagating to customer/admin creation.

---

### 1.9 Rate Limiter IP Source

**Issue**: H-07
**File**: `Controller/Actions/BackChannelLogout.php` ~line 94-97

Document the trust assumption. Add comment and consider using `REMOTE_ADDR`:
```php
// Use REMOTE_ADDR directly for rate limiting to prevent X-Forwarded-For spoofing.
// If behind a trusted reverse proxy, configure trusted_proxies in Magento.
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
```

Apply the same pattern in `ReadAuthorizationResponse` and `Oidccallback` if they also use `getClientIp()`.

---

### 1.10 Back-Channel Logout Audience Validation

**Issue**: H-08
**File**: `Controller/Actions/BackChannelLogout.php` ~line 119-120

Replace:
```php
$audience = is_array($audRaw) ? ($audRaw[0] ?? '') : (string) $audRaw;
```
With proper multi-audience support used later in validation:
```php
$audiences = is_array($audRaw) ? array_map('strval', $audRaw) : [(string) $audRaw];
```
Then where `$audience` is compared to `$clientId`:
```php
if (!in_array($clientId, $audiences, true)) {
    return $this->jsonError('Audience mismatch.', 400);
}
```

**Tests**: Add test with `aud=["other-client", "our-client"]` — should succeed.

---

### 1.11 OAuthLogoutObserver exit(0)

**Issue**: H-09, CV-01
**File**: `Observer/OAuthLogoutObserver.php` ~line 211

Replace `exit(0)` with Magento-compatible redirect. Since this is an observer (not a controller), use the response object:
```php
// Before:
// phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
exit(0);

// After:
$this->response->sendResponse();
return;
```

Verify `\Magento\Framework\App\ResponseInterface` is injected. If the redirect was set on the response earlier in the method, `sendResponse()` will flush it.

---

### 1.12 Auto-Discovery HTTPS Validation

**Issue**: H-10
**File**: `Controller/Adminhtml/Provider/Save.php`

After fetching and parsing the discovery document, validate each endpoint:
```php
$endpointKeys = [
    'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint',
    'jwks_uri', 'end_session_endpoint', 'revocation_endpoint',
];
foreach ($endpointKeys as $key) {
    if (isset($discovered[$key]) && is_string($discovered[$key])) {
        $scheme = parse_url($discovered[$key], PHP_URL_SCHEME);
        if ($scheme !== 'https') {
            $this->messageManager->addWarningMessage(
                (string) __('Discovered %1 is not HTTPS and was skipped.', $key)
            );
            unset($discovered[$key]);
        }
    }
}
```

---

### 1.13 JWKS Cache Invalidation on Provider Save

**Issue**: H-11
**File**: `Controller/Adminhtml/Provider/Save.php`

After saving the provider, if `jwks_endpoint` changed, invalidate the cache:
```php
if ($oldJwksEndpoint !== $newJwksEndpoint) {
    $cacheKey = 'oidc_jwks_' . md5($oldJwksEndpoint);
    $this->cache->remove($cacheKey);
    $this->oauthUtility->customlog(
        "Provider Save: JWKS cache invalidated for provider " . $providerId
    );
}
```

This requires reading the old value before save and comparing after.

---

### 1.14 Login Restriction Safety Net Logging

**Issue**: H-12
**Files**: `Plugin/AdminLoginRestrictionPlugin.php` ~line 82-90, `Plugin/CustomerLoginRestrictionPlugin.php` ~line 72-80

The safety net already logs a warning (line 84). Verify the log level is `warning` (it is). No code change needed — the review finding was based on an earlier version. Mark as **already addressed**.

---

## Phase 2 — Logic Correctness & Data Integrity

### 2.1 Timing Attack Mitigations

**Issues**: M-01, M-02

**M-01** — `Helper/OAuthSecurityHelper.php` ~line 539:
```php
// Before:
return !in_array($stored, [null, '', '0'], true) && $stored === $email;
// After:
return !in_array($stored, [null, '', '0'], true) && hash_equals($stored, $email);
```

**M-02** — `Plugin/Auth/OidcCredentialPlugin.php` ~line 85-89:
```php
public function isOidcAuthToken(string $password): bool
{
    if (strlen($password) !== 69) { // 'OIDC_' (5) + 64 hex chars
        return false;
    }
    return str_starts_with($password, 'OIDC_')
        && ctype_xdigit(substr($password, 5));
}
```

---

### 2.2 Logout & Redirect Fixes

**Issues**: M-03, M-04, M-16

**M-03** — `Plugin/Auth/OidcLogoutPlugin.php` ~line 112-115: Document the race as acceptable (session lock prevents concurrent access). Add comment.

**M-04** — Same file ~line 238-254: Add URL validation:
```php
if (!empty($postLogoutUri) && filter_var($postLogoutUri, FILTER_VALIDATE_URL) === false) {
    $postLogoutUri = '';
}
```

**M-16** — `Observer/OAuthLogoutObserver.php` ~line 279: Remove forced trailing slash:
```php
// Before:
$url = rtrim((string) $provider['post_logout_url'], '/') . '/';
// After:
$url = (string) $provider['post_logout_url'];
```

---

### 2.3 Admin User Creator Transaction Fix

**Issue**: M-05
**File**: `Model/Service/AdminUserCreator.php` ~line 200-223

Move role assignment before the first `save()`:
```php
$user->setRoleId($roleId);    // Set role BEFORE save
$this->userResource->save($user);
// Role is now persisted atomically with user
```

If `setRoleId()` requires the user to have an ID first (common in Magento), wrap the role assignment in the same transaction and add explicit rollback on failure:
```php
try {
    $connection->beginTransaction();
    $this->userResource->save($user);
    $user->setRoleId($roleId);
    $user->save(); // saves role association
    $connection->commit();
} catch (\Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

---

### 2.4 Customer Group Contract Clarification

**Issue**: M-06
**File**: `Model/Service/CustomerUserCreator.php` ~line 265-268 vs 366

Add PHPDoc to `getCustomerGroupFromOidcGroups()`:
```php
/**
 * ...
 * @return int|null  Magento customer group ID, or null when no mapping matches
 *                   (caller decides whether to throw or use default).
 */
```

No functional change needed — the contract is correct (null = no match, caller throws). Document it.

---

### 2.5 Zitadel Off-By-One

**Issue**: M-07
**File**: `Model/Service/OidcAuthenticationService.php` ~line 290-295

```php
// Before:
ctype_digit($orgPart) || strlen($orgPart) < 15
// After:
ctype_digit($orgPart) || strlen($orgPart) <= 15
```

---

### 2.6 Username TOCTOU Race

**Issue**: M-08
**File**: `Model/Service/AdminProfileSyncService.php` ~line 103-119

Replace check-then-save with save-and-catch:
```php
try {
    $this->userResource->save($user);
} catch (\Magento\Framework\Exception\AlreadyExistsException $e) {
    $this->oauthUtility->customlog(
        'AdminProfileSyncService: Username conflict during sync — ' . $e->getMessage()
    );
    // Don't update username; keep existing value
}
```

---

### 2.7 CustomerProfileSyncService Fixes

**Issues**: M-09, M-10, M-11

**M-09** — Postcode null-check (line 208-210):
```php
// Before:
$existingAddress->getPostcode() !== ($zip ?? '')
// After:
(string) ($existingAddress->getPostcode() ?? '') !== (string) ($zip ?? '')
```

**M-10** — Default telephone (line 243):
```php
// Before:
->setTelephone($phone ?? '0000')
// After:
->setTelephone($phone ?? '')
```

**M-11** — Region unset (line 220-226): Add else-branch:
```php
if ($state !== null) {
    $existingAddress->setRegion(...);
    $changed = true;
} else {
    $existingAddress->setRegion(null);
    $existingAddress->setRegionId(null);
    $changed = true;
}
```

---

### 2.8 Logger Fixes

**Issues**: M-12, M-13

**M-12** — `Logger/OidcLogger.php` ~line 286: Add max cache size:
```php
private const MAX_SUFFIX_LOGGERS = 20;

// In resolveLogger(), before creating new logger:
if (count($this->suffixLoggers) >= self::MAX_SUFFIX_LOGGERS) {
    array_shift($this->suffixLoggers); // evict oldest
}
```

**M-13** — Standardize config path: Change `oidc/logging/json_lines` to `m2oidc/logging/json_lines`. Update `etc/adminhtml/system.xml` if the config field references the old path.

---

### 2.9 Nonce Provider Context Validation

**Issues**: M-14, M-17

**M-14** — `Controller/Adminhtml/Actions/Oidccallback.php`:
Modify `OAuthSecurityHelper::createAdminLoginNonce()` to store `email|providerId`:
```php
$payload = $email . '|' . $providerId;
$this->atomicCache->save($payload, $cacheKey, $ttl);
```
On redemption, split and validate both parts.

**M-17** — `Controller/Actions/CustomerOidcCallback.php`: Same pattern — store `provider_id` in customer nonce and cross-check on redemption.

---

### 2.10 Website Context Check Order

**Issue**: M-15
**File**: `Controller/Actions/CustomerOidcCallback.php` ~line 177 vs 212

Move the website context check before the customer load:
```php
// 1. Get website context first
$websiteId = $this->storeManager->getStore()->getWebsiteId();

// 2. Load customer with website filter
try {
    $customerData = $this->customerRepository->get($email, $websiteId);
} catch (NoSuchEntityException $e) {
    // Customer not found — return generic error (no existence leak)
    return $this->buildErrorPage('Authentication failed.');
}
```

---

### 2.11 Headless & Relay State Fixes

**Issues**: M-18, M-20, M-24, M-25

**M-18** — `HeadlessOidcCallback.php` ~line 182: Validate scheme:
```php
if (($parsedOrigin['scheme'] ?? '') !== 'https') {
    $this->oauthUtility->customlog("HeadlessOidcCallback: Store base URL is not HTTPS");
}
```

**M-20** — `OAuthSecurityHelper.php` ~line 298: Add length guard:
```php
if (strlen($relayState) > 2048) {
    $this->oauthUtility->customlog("Relay state exceeds 2048 chars, truncating");
    $relayState = substr($relayState, 0, 2048);
}
```

**M-24** — `ReadAuthorizationResponse.php` ~line 196-197: Re-validate headless from provider:
```php
$headless = $headless && !empty($clientDetails['headless_mode']);
```

**M-25** — Same file ~line 498: Fix regex:
```php
// Before:
'/key\/([a-f0-9]{32,})/'
// After:
'/key\/([a-f0-9]{32})/'
```

---

### 2.12 Remaining Medium Issues

**M-19** — `OidcLogoutPlugin.php`: Audit `revokeToken()` to ensure client_secret is never logged. Add `// @codeCoverageIgnore` comment near the secret parameter if logging is safe.

**M-21** — `OidcAuthenticationService.php` ~line 81: Apply Base64 decode at all nesting levels when `claim_encoding=base64`. Change the condition from `$keyPrefix === ''` to always decode when the flag is set.

**M-22** — `OidcSessionRegistry.php` ~line 80-92: Document the race as acceptable for the current use case. Add comment noting that Redis-based locking could be added if multi-session concurrent registration becomes a problem.

**M-23** — `CustomerUserCreator.php` ~line 489: Handle array street values:
```php
$streetValue = $mapped['billing_street'];
$street = is_array($streetValue) ? $streetValue : [$streetValue];
$address->setStreet($street);
```

---

## Phase 3 — Low-Severity Hardening

All items below are single-line or small changes. Group by file for efficiency.

| Issue | File | Change |
|-------|------|--------|
| L-01 | OAuthSecurityHelper | Remove `'0'` from `in_array()` checks (multiple locations) |
| L-03 | JwtVerifier:425 | Add `if (!preg_match('/^[A-Za-z0-9_=-]*$/', $input)) return '';` before `base64UrlDecode` |
| L-04 | OidcCredentialAdapter:233 | Replace `assert()` with explicit `if (!$this->user instanceof User) throw ...` |
| L-05 | OidcCredentialAdapter:147+ | Mask email in log: `substr($email, 0, 2) . '***' . substr($email, -4)` |
| L-06 | OidcCredentialAdapter:362 | Add `if (!$this->user->getId()) { throw ... }` after load |
| L-07 | OidcLogoutPlugin:337 | Add comment documenting heuristic limitations |
| L-08 | BackChannelLogout:147 | Add `$sub = trim($sub); $sid = trim($sid);` |
| L-09 | BackChannelLogout:171 | Reorder: call `revoke()` before `destroySession()` loop |
| L-10 | OidcSessionRegistry:186 | Add type validation: `&& is_string($decoded['php_session_id'])` |
| L-11 | OidcSessionRegistry:74 | Make TTL configurable via `OAuthConstants::SESSION_REGISTRY_TTL` |
| L-12 | OAuthConstants | Add `DEFAULT_CUSTOMER_RELAY_STATE = '/'` and `DEFAULT_ADMIN_RELAY_STATE = '/'` |
| L-13 | AdminUserCreator:263 | Add `if (!is_array($decoded)) { $this->log('Invalid JSON...'); $decoded = []; }` |
| L-14 | AdminUserCreator:351 | Replace `getSize()` with direct SQL `fetchOne('SELECT COUNT(*)')` |
| L-15 | OAuthUtility:545 | Remove unused `$from` parameter |
| L-16 | AdminLoginAutoRedirect:60 | Change to `=== '1'` to match customer counterpart |
| L-17 | Multiple | Audit all OIDC cookies; ensure consistent `SameSite` and `Secure` attributes |
| L-18 | Multiple | Mask sensitive data in `customlog()` calls (emails, token lengths) |

---

## Phase 4 — Performance & Conventions

### Performance Fixes

| Issue | File | Change |
|-------|------|--------|
| P-01 | AdminUserCreator:351 | Replace collection with `$connection->fetchOne()` |
| P-02 | CustomerProfileSyncService:345 | Cache country collection in class property |
| P-03 | CustomerProfileSyncService:379 | Build country-name-to-code lookup map once, reuse |
| P-04 | OidcLogger:283 | Already addressed by M-12 (LRU eviction) |
| P-05 | CustomerUserCreator:160 | Cache config values in class properties after first `initializeAttributeMapping()` call |
| P-06 | OidcAuthenticationService:81 | Add early return for scalar values; document O(n) bound |

### Convention Fixes

| Issue | File | Change |
|-------|------|--------|
| CV-01 | OAuthLogoutObserver | Already addressed by H-09 |
| CV-02 | OidcCredentialAdapter | Add `@SuppressWarnings(PHPMD.StaticAccess)` + comment explaining serialization constraint |
| CV-03 | RedisAtomicCache | Add comment documenting reflection fragility + version check |
| CV-04 | Multiple | Document error transport strategy in `Docs/Dev-Doc.md` |
| CV-05 | OidcConfigReader | Add comment: mapping is intentionally explicit for auditability |
| CV-06 | AdminUserCreator | Cast role ID to `(int)` at assignment |

---

## Verification Checklist

After all changes, run:

```bash
# Static analysis
cd /var/www/html/github/magento2-oidc-sso
/var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=Test/phpstan.local.neon
/var/www/html/vendor/bin/psalm --no-cache --config=Test/psalm.xml --root=/var/www/html --force-jit
/var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=Test/phpcs.xml .
/var/www/html/vendor/bin/rector process --dry-run --config=Test/rector.php

# Unit tests
/var/www/html/vendor/bin/phpunit --configuration Test/phpunit.xml --testsuite "M2Oidc OIDC Unit Tests"

# Manual verification
# - Test customer logout with provider_id > 0 (C-03)
# - Test back-channel logout with Redis session store (C-04)
# - Test JWT with manipulated kid header (C-01)
# - Test OIDC login flow end-to-end (no regressions)
# - Test headless callback under rate limiting (H-03)
```

---

## Issue-to-Phase Mapping

| Phase | Issues |
|-------|--------|
| **Phase 1** | C-01, C-02, C-03, C-04, C-05, H-01, H-02, H-03, H-04, H-05, H-06, H-07, H-08, H-09, H-10, H-11, H-12, L-02 |
| **Phase 2** | M-01 through M-25 |
| **Phase 3** | L-01, L-03 through L-18 |
| **Phase 4** | P-01 through P-06, CV-01 through CV-06 |
