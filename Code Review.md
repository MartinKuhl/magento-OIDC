# Code Review - Magento OIDC Module

**Date:** 2026-02-09  
**Reviewer:** Senior Developer Review  
**Scope:** Full codebase analysis

---

## Critical Issues

### 1. Incomplete Function Returns `null` Implicitly
**Severity:** Critical  
**File:** `Model/Service/AdminUserCreator.php`  
**Lines:** 165-208 (`getAdminRoleFromGroups`)

**What's wrong:**  
The function `getAdminRoleFromGroups()` has no return statement at the end. After the "Fallback: Find Administrators role" comment (lines 200-208), the function just ends without returning anything. This causes PHP to return `null` implicitly.

```php
// Line 206-208 - Function ends without return!
// However, to maintain some backward compatibility or ensure utility, maybe we log warning?
// The Code Review said: "Issue: Fallback to role ID 1 (Administrators) if mapping fails is dangerous."
} // <-- Function ends here with no return
```

**Impact:** When no role mapping is found and no default role is configured, the function returns `null`, which correctly blocks admin creation. However, the code is incomplete - it appears someone started writing comments about the fallback logic but never finished implementing or explicitly returning.

**How to fix:**
```php
private function getAdminRoleFromGroups($userGroups)
{
    // ... existing code ...
    
    // After the default role check, add explicit return
    $this->oauthUtility->customlog("AdminUserCreator: No role mapping or default role configured. Denying admin creation.");
    return null;
}
```

---

### 2. Direct ObjectManager Usage in Static Methods
**Severity:** Critical  
**File:** `Helper/SessionHelper.php`  
**Lines:** 33-51

**What's wrong:**  
The class uses `ObjectManager::getInstance()` directly in static methods. This violates Magento's DI principles and makes the code untestable.

```php
private static function getCookieManager(): CookieManagerInterface
{
    if (self::$cookieManager === null) {
        self::$cookieManager = ObjectManager::getInstance()->get(CookieManagerInterface::class);
    }
    return self::$cookieManager;
}
```

**Impact:** Violates Magento coding standards, makes unit testing impossible, creates hidden dependencies.

**How to fix:**  
Refactor `SessionHelper` to be a non-static service class with proper DI:
```php
class SessionHelper
{
    private CookieManagerInterface $cookieManager;
    private CookieMetadataFactory $cookieMetadataFactory;
    
    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
    }
    // ... convert static methods to instance methods
}
```

---

### 3. OIDC Admin Token Bypass Vulnerability
**Severity:** Critical  
**File:** `Plugin/AdminLoginRestrictionPlugin.php`  
**Lines:** 44-53

**What's wrong:**  
The plugin checks for an `oidc_admin_token` cookie to determine if OIDC authentication was used, but this cookie could potentially be forged if not properly validated.

```php
$oidcToken = $this->cookieManager->getCookie('oidc_admin_token');

if (!$oidcToken) {
    // ... block login
}
```

**Impact:** If an attacker sets a cookie named `oidc_admin_token` with any value, they could bypass the OIDC-only restriction.

**How to fix:**  
The token must be cryptographically validated:
```php
$oidcToken = $this->cookieManager->getCookie('oidc_admin_token');

if (!$oidcToken || !$this->validateOidcToken($oidcToken)) {
    // block login
}

private function validateOidcToken($token): bool
{
    // Validate against stored session data or HMAC signature
}
```

---

## High Severity Issues

### 4. Empty Return Statement in Controller
**Severity:** High  
**File:** `Controller/Actions/SendAuthorizationRequest.php`  
**Lines:** 103-105

**What's wrong:**  
When `authorize_endpoint` is not set, the function returns nothing, which causes a blank page.

```php
if (!$clientDetails["authorize_endpoint"]) {
    return;  // Returns nothing! User sees blank page
}
```

**How to fix:**
```php
if (!$clientDetails["authorize_endpoint"]) {
    $this->messageManager->addErrorMessage('Authorization endpoint not configured.');
    return $this->resultRedirectFactory->create()->setUrl($this->oauthUtility->getBaseUrl());
}
```

---

### 5. Insufficient Input Validation on Base64 Decoded Error Messages
**Severity:** High  
**File:** `Block/OAuth.php`  
**Lines:** 684-690

**What's wrong:**  
Error messages are decoded from base64 without validation. An attacker could inject malicious content.

```php
public function getOidcErrorMessage()
{
    $encodedMessage = $this->getRequest()->getParam('oidc_error');
    if ($encodedMessage) {
        return base64_decode($encodedMessage);  // No validation!
    }
    return null;
}
```

**Impact:** XSS vulnerability if the decoded message is rendered without escaping in templates.

**How to fix:**
```php
public function getOidcErrorMessage()
{
    $encodedMessage = $this->getRequest()->getParam('oidc_error');
    if ($encodedMessage) {
        $decoded = base64_decode($encodedMessage, true);
        if ($decoded === false) {
            return null;
        }
        return $this->escaper->escapeHtml($decoded);
    }
    return null;
}
```

---

### 6. No Error Handling in cURL Responses
**Severity:** High  
**File:** `Helper/Curl.php`  
**Lines:** 39-63

**What's wrong:**  
The `callAPI` method doesn't check for cURL errors, empty responses, or HTTP error codes.

```php
$content = $curl->read();
$curl->close();
return $content;  // Could be empty, could be error, no validation
```

**How to fix:**
```php
$content = $curl->read();
$httpCode = $curl->getInfo(CURLINFO_HTTP_CODE);
$curl->close();

if (empty($content)) {
    throw new \Exception('Empty response from OAuth server');
}

if ($httpCode >= 400) {
    throw new \Exception('HTTP error ' . $httpCode . ' from OAuth server');
}

return $content;
```

---

### 7. Null User Passed to Login Session
**Severity:** High  
**File:** `Controller/Actions/CustomerLoginAction.php`  
**Lines:** 38-46

**What's wrong:**  
The `$this->user` property is used without null checking. If `setUser()` was never called, this will cause a fatal error.

```php
public function execute()
{
    // ... 
    $this->customerSession->setCustomerAsLoggedIn($this->user);  // $this->user could be null!
    return $this->resultRedirectFactory->create()->setUrl(...);
}
```

**How to fix:**
```php
public function execute()
{
    if (!$this->user || !$this->user->getId()) {
        $this->messageManager->addErrorMessage(__('User not found.'));
        return $this->resultRedirectFactory->create()->setPath('customer/account/login');
    }
    $this->customerSession->setCustomerAsLoggedIn($this->user);
    // ...
}
```

---

## Medium Severity Issues

### 8. Inconsistent Email Finding Logic
**Severity:** Medium  
**File:** `Controller/Actions/ProcessResponseAction.php`  
**Lines:** 97-125

**What's wrong:**  
The `findUserEmail` function searches recursively for any valid email in the OIDC response, rather than looking at the configured email attribute mapping.

```php
foreach ($arr as $value) {
    if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return $value;  // Returns FIRST email found, not necessarily the correct one
    }
    // ...
}
```

**Impact:** If the OIDC token contains multiple email addresses (e.g., a contact email and user email), the wrong one might be selected.

**How to fix:**  
Check the configured email attribute mapping first, fall back to recursive search only if not found.

---

### 9. Hardcoded Timezone
**Severity:** Medium  
**File:** `Helper/OAuthUtility.php`  
**Lines:** 524-528

**What's wrong:**  
The `getCurrentDate()` method hardcodes `Europe/Berlin` timezone.

```php
public function getCurrentDate()
{
    $dateTimeZone = new \DateTimeZone('Europe/Berlin');  // Hardcoded!
    $dateTime = new \DateTime('now', $dateTimeZone);
    return $dateTime->format('n/j/Y, g:i:s a');
}
```

**How to fix:**  
Use Magento's timezone configuration:
```php
public function getCurrentDate()
{
    $timezone = $this->scopeConfig->getValue('general/locale/timezone');
    $dateTimeZone = new \DateTimeZone($timezone ?: 'UTC');
    // ...
}
```

---

### 10. relayState Overwritten After Encoding
**Severity:** Medium  
**File:** `Controller/Actions/SendAuthorizationRequest.php`  
**Lines:** 63 and 119

**What's wrong:**  
The `$relayState` variable is encoded with session info on line 63, but then potentially overwritten on line 119 with the original unencoded value before being used.

```php
// Line 63
$relayState = urlencode($relayState) . '|' . $currentSessionId . '|' . urlencode($app_name) . '|' . OAuthConstants::LOGIN_TYPE_CUSTOMER;

// ... later on line 119
$relayState = isset($params['relayState']) ? $params['relayState'] : '';  // Overwrites!
```

**Impact:** The original unencoded relayState is used in some code paths.

**How to fix:**  
Use different variable names to avoid confusion:
```php
$combinedState = urlencode($relayState) . '|' . $currentSessionId . '|' ...;
// Use $combinedState for authorization request
```

---

### 11. Test Results Stored in Session with Large Data
**Severity:** Medium  
**File:** `Controller/Actions/ReadAuthorizationResponse.php`  
**Lines:** 181-183

**What's wrong:**  
Test results (potentially large OIDC token data) are stored in the customer session, which could bloat session storage.

```php
$testResults = $this->customerSession->getData('mooauth_test_results') ?: [];
$testResults[$testKey] = $userInfoResponseData;  // Could be large
$this->customerSession->setData('mooauth_test_results', $testResults);
```

**How to fix:**  
Use a separate cache storage or limit the data stored, and add TTL-based cleanup.

---

### 12. Debug Data Exposure
**Severity:** Medium  
**File:** `Controller/Actions/CheckAttributeMappingAction.php`  
**Lines:** 477-520

**What's wrong:**  
Debug data is saved to the session even in production. While sensitive keys are filtered, the approach of storing debug data by default is concerning.

```php
$this->customerSession->setData('mo_oauth_debug_response', json_encode($debugData));
```

**How to fix:**  
Only save debug data when debug logging is explicitly enabled:
```php
if ($this->oauthUtility->isLogEnable()) {
    $this->customerSession->setData('mo_oauth_debug_response', json_encode($debugData));
}
```

---

## Low Severity Issues

### 13. Duplicate Method `getIDPApps()`
**Severity:** Low  
**File:** `Helper/Data.php`  
**Lines:** 119-124 and 129-134

**What's wrong:**  
`getOAuthClientApps()` and `getIDPApps()` are identical methods.

**How to fix:**  
Remove one and have it call the other, or consolidate to a single method.

---

### 14. Unused Property `$testAction`
**Severity:** Low  
**File:** `Controller/Actions/CheckAttributeMappingAction.php`  
**Line:** 41

**What's wrong:**  
`$testAction` is declared but never assigned in the constructor. It's used on line 124-127 but will be null.

```php
private $testResults;
private $processUserAction;
// $testAction is referenced on line 124 but never initialized!
```

**How to fix:**  
Remove the reference or properly inject the dependency.

---

### 15. Class Name Case Mismatch
**Severity:** Low  
**File:** `Controller/Actions/SendAuthorizationRequest.php`  
**Line:** 14

**What's wrong:**  
Class name uses camelCase (`sendAuthorizationRequest`) instead of PascalCase.

```php
class sendAuthorizationRequest extends BaseAction  // Should be SendAuthorizationRequest
```

**How to fix:**  
Rename to `SendAuthorizationRequest` (though this may require careful refactoring of routes/DI).

---

### 16. Unused Constructor Parameters
**Severity:** Low  
**File:** `Observer/OAuthObserver.php`  
**Line:** 37

**What's wrong:**  
`$request` is injected twice (both `Http $httpRequest` and `RequestInterface $request`).

---

## Edge Cases & Potential Breaks

### Input Scenarios That Could Break:

1. **Empty email in OIDC response**: `ProcessResponseAction::findUserEmail` returns empty string, but downstream code may not handle this gracefully everywhere.

2. **Pipe character `|` in app name**: The relayState format uses `|` as delimiter. While URL encoding is applied, decoding issues could occur.

3. **Unicode characters in names**: `sanitize()` uses `htmlspecialchars()` which handles UTF-8, but some edge cases with malformed UTF-8 could cause issues.

4. **Very long OIDC responses**: No size limits on stored session data or processed responses.

5. **Concurrent login attempts**: No mutex/locking on admin user creation - race condition could create duplicate users.

6. **Session expiry during OIDC flow**: If the session expires between sending the auth request and receiving the callback, the session restoration logic may fail silently.

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 4 |
| Medium | 5 |
| Low | 4 |

**Priority Fixes:**
1. Fix incomplete `getAdminRoleFromGroups()` function (Critical)
2. Validate `oidc_admin_token` cookie properly (Critical)
3. Refactor `SessionHelper` to use DI (Critical)
4. Add proper error handling in `Curl.php` (High)
5. Add null checks in `CustomerLoginAction` (High)
