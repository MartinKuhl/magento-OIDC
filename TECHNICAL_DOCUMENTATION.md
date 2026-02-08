# MiniOrange OAuth/OIDC SSO Module Documentation

## 1. Overview
This module (`miniorange_inc/miniorange-oauth-sso`) enables **OAuth 2.0 and OpenID Connect (OIDC)** authentication for Magento 2. It allows both **Customers** and **Administrators** to log in using an external Identity Provider (IDP) such as Authelia, Keycloak, or Auth0.

### Key Features
*   **Unified SSO**: Supports both Customer and Admin login flows.
*   **Attribute Mapping**: Maps IDP attributes (Email, Name, Groups) to Magento user fields.
*   **Just-In-Time (JIT) Provisioning**: Automatically creates Customers and Admins if they don't exist (configurable).
*   **Admin Auto-Login**: Dedicated flow to securely log in admins without a password prompt if the session is valid.
*   **Group Mapping**: Maps IDP groups to Magento Admin Roles.

---

## 2. Quick Start

### Prerequisites
*   Magento 2 installed.
*   An OIDC Provider (e.g., Authelia) configured.

### Installation & Configuration
1.  **Install the Module**:
    ```bash
    composer require miniorange_inc/miniorange-oauth-sso
    bin/magento module:enable MiniOrange_OAuth
    bin/magento setup:upgrade
    ```

2.  **Configure the Provider**:
    *   Navigate to **Stores > Configuration > MiniOrange > OAuth/OIDC**.
    *   Enter your **Client ID**, **Client Secret**, and **Discovery URL** (or manual endpoints).
    *   Set the **Callback URL** in your IDP to: `https://your-site.com/mooauth/actions/processresponse`.

3.  **Test the Configuration**:
    *   Use the "Test Configuration" button in the admin panel to verify connectivity and attribute reception.

---

## 3. API Reference

The core functionality is exposed via `\MiniOrange\OAuth\Helper\Data` and `\MiniOrange\OAuth\Helper\OAuthUtility`.

### `\MiniOrange\OAuth\Helper\Data`

| Method | Parameters | Returns | Description |
| :--- | :--- | :--- | :--- |
| `getSPInitiatedUrl` | `$relayState` (string\|null), `$userId` (string\|null) | `string` | Returns the URL to trigger the OIDC login flow for Customers. |
| `getAdminSPInitiatedUrl` | `$relayState` (string\|null) | `string` | Returns the URL to trigger the OIDC login flow for Admins. |
| `getCallBackUrl` | None | `string` | Returns the module's callback URL (`.../mooauth/actions/processresponse`). |
| `getCurrentUser` | None | `Customer` | Returns the currently logged-in Customer model. |
| `getCurrentAdminUser` | None | `User` | Returns the currently logged-in Admin User model. |
| `isUserLoggedIn` | None | `bool` | Checks if *any* user (Customer or Admin) is currently logged in via the module. |

### `\MiniOrange\OAuth\Helper\OAuthUtility`

| Method | Parameters | Returns | Description |
| :--- | :--- | :--- | :--- |
| `isOAuthConfigured` | None | `bool` | Checks if the plugin is properly configured with an IDP. |
| `log_debug` | `$msg` (string), `$obj` (mixed) | `void` | Writes to `var/log/mo_oauth.log` if debug logging is enabled. |
| `flushCache` | None | `void` | Clears Magento `db_ddl` and frontend caches. |

---

## 4. Common Patterns

### Triggering a Login Link
To add a "Login with SSO" button in your theme:

```php
<?php
// Inject \MiniOrange\OAuth\Helper\Data as $oauthHelper
$loginUrl = $oauthHelper->getSPInitiatedUrl();
?>
<a href="<?= $loginUrl ?>" class="btn-sso">Login with Authelia</a>
```

### Checking for Admin Privileges via SSO
To check if the current user is an admin logged in via SSO:

```php
if ($oauthHelper->isUserLoggedIn() && $oauthHelper->getCurrentAdminUser()) {
    // User is an admin
}
```

### Customizing Attribute Mapping
The module handles standard mapping via config, but you can extend `CheckAttributeMappingAction` or observe the process.
*   **Key Logic**: `Controller/Actions/CheckAttributeMappingAction.php`
*   **Admin Creation**: `Controller/Actions/CheckAttributeMappingAction::createAdminUser`

---

## 5. Gotchas

### 1. Admin Login Flow
Admin login is **distinct** from Customer login.
*   Admins are redirected to `mooauth/actions/oidccallback`.
*   **Requirement**: The IDP email **MUST** match the Magento Admin email.
*   **Troubleshooting**: If admin login fails, check `var/log/mo_oauth.log`. Ensure the user is active in Magento.

### 2. Mixed Content / Protocol Issues
*   The module constructs callback URLs using `storeManager->getBaseUrl()`.
*   **Gotcha**: If your load balancer handles SSL but Magento sees HTTP, the callback URL might be generated with `http://`, causing IDP rejection.
*   **Fix**: Ensure Magento is configured for HTTPS (`web/secure/base_url`) and properly handles `X-Forwarded-Proto`.

### 3. Attribute Mapping Keys
*   Attributes are often case-sensitive.
*   **Authelia**: Usually sends `preferred_username`, `email`, `name`, `groups`.
*   **Gotcha**: If `FirstName` / `LastName` are missing, the module falls back to splitting the email address or using the username.

### 4. Admin Role Mapping
*   To automaticlly assign roles, you must configure the JSON mapping in "Admin Role Mapping".
*   If no mapping matches, it falls back to the **Default Role** configured, or "Administrators" if that fails.

---

## 6. Related Modules & Files

*   **Controllers**:
    *   `Controller/Actions/SendAuthorizationRequest.php`: The starting point.
    *   `Controller/Actions/ProcessResponseAction.php`: The handling point.
    *   `Controller/Adminhtml/Actions/Oidccallback.php`: The admin finalization point.
*   **Magento Core**:
    *   `Magento_Customer`: Used for `CustomerFactory`, `Session`.
    *   `Magento_User`: Used for `UserFactory` (Admin users).
    *   `Magento_Backend`: Used for Admin logic.
