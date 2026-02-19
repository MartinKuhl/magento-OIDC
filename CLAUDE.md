# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Magento 2 module that provides OAuth/OIDC authentication for both customer (frontend) and admin (backend) users. The module is registered as `MiniOrange_OAuth` and supports automatic admin login after successful OIDC authentication.

## Magento 2 Development Commands

### Module Management
```bash
# Enable the module
php bin/magento module:enable MiniOrange_OAuth

# Disable the module
php bin/magento module:disable MiniOrange_OAuth

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
tail -f var/log/mo_oauth.log
tail -f var/log/system.log
tail -f var/log/exception.log

# Check module status
php bin/magento module:status MiniOrange_OAuth
```

## Architecture

### Authentication Flow

The module implements a dual authentication flow for admin and customer users:

1. **Customer Flow** (Frontend):
   - Route: `mooauth` (defined in etc/frontend/routes.xml)
   - Entry: `SendAuthorizationRequest` → Redirects to OIDC provider
   - Callback: `ReadAuthorizationResponse` → Receives auth code
   - Processing: `ProcessResponseAction` → Exchanges code for token
   - Attribute Mapping: `CheckAttributeMappingAction` → Maps OIDC claims
   - Login: `CustomerLoginAction` → Creates Magento customer session

2. **Admin Flow** (Backend) - **Uses Native Magento Authentication:**
   - Route: `mooauth` (defined in etc/adminhtml/routes.xml)
   - Same initial flow as customer
   - **Critical difference**: In `CheckAttributeMappingAction:101-130`, admin users are detected by checking if email exists in `admin_user` table
   - Admin users are redirected to `Oidccallback` controller in adminhtml area
   - **Native integration**: `Oidccallback` calls `Auth::login($email, 'OIDC_VERIFIED_USER')`
   - `OidcCredentialPlugin` detects the special token marker and injects `OidcCredentialAdapter`
   - `OidcCredentialAdapter` authenticates the user without password verification (already done at IdP)
   - `OidcCaptchaBypassPlugin` skips CAPTCHA validation for OIDC auth
   - All standard Magento security events fire correctly

### Key Components

#### Controllers (Controller/Actions/)
- `BaseAction.php` / `BaseAdminAction.php`: Base classes for OAuth actions
- `SendAuthorizationRequest.php`: Initiates OAuth flow
- `ReadAuthorizationResponse.php`: Handles OAuth callback
- `ProcessResponseAction.php`: Exchanges authorization code for access token
- `CheckAttributeMappingAction.php`: Routes users based on admin/customer detection, maps OIDC attributes, and handles admin auto-creation with group-to-role mapping
- `ProcessUserAction.php`: Creates or updates Magento users based on OIDC data
- `ShowTestResults.php`: Displays test results for attribute mapping
- `Adminhtml/Actions/Oidccallback.php`: Admin callback that performs native Magento login via `Auth::login()`

#### Blocks (Block/)
- `OAuth.php`: Template block class for admin configuration pages
  - `getAdminRoleMappings()`: Returns OIDC group to Magento admin role mappings from configuration

#### Admin Controllers (Controller/Adminhtml/)
- `Attrsettings/Index.php`: Saves attribute mapping configuration including admin role mappings as JSON

#### Authentication Integration (Model/Auth/)
- `OidcCredentialAdapter.php`: Implements `StorageInterface` to bridge OIDC with Magento's native auth
  - Validates OIDC token marker instead of password verification
  - Fires all standard authentication events
  - Handles serialization for session storage
  - Proxies User model methods via `__call()` magic method

#### Plugins (Plugin/)
- `Auth/OidcCredentialPlugin.php`: Intercepts `Auth::getCredentialStorage()` to inject OIDC adapter
  - `beforeLogin()`: Detects OIDC token marker and sets flag
  - `aroundGetCredentialStorage()`: Returns OIDC adapter when flag is set
  - `afterLogin()`: Cleans up flag after login completes
- `Captcha/OidcCaptchaBypassPlugin.php`: Bypasses CAPTCHA for OIDC-authenticated users
  - Intercepts `CheckUserLoginBackendObserver::execute()`
  - Skips CAPTCHA validation when `oidc_auth` marker is present in event data

#### Helpers (Helper/)
- `OAuthUtility.php`: Core utility class extending Data class, provides common functions
- `SessionHelper.php`: Manages session cookies with SameSite=None for cross-origin SSO
- `OAuthConstants.php`: Constants for config paths and defaults
- `OAuthMessages.php`: User-facing messages
- `Data.php`: Data access layer for configuration
- `OAuth/`: Contains OAuth protocol implementation classes

#### Models & Database
- Table: `miniorange_oauth_client_apps` (defined in etc/db_schema.xml)
- Stores OIDC provider configuration: endpoints, client credentials, scopes, attribute mappings, role/group mappings
- Model: `Model/MiniorangeOauthClientApps.php`

#### Observers (Observer/)
- `SessionCookieObserver.php`: Forces SameSite=None on cookies (event: `controller_front_send_response_before`)
- `OAuthObserver.php`: Handles OAuth-specific events
- `OAuthLogoutObserver.php`: Manages logout flow with OIDC provider

#### Configuration
- Dependency injection: `etc/di.xml` defines:
  - Constructor arguments for `CheckAttributeMappingAction` with admin-related dependencies:
    - `userFactory`, `backendUrl` for admin user operations
    - `roleCollection` for querying available admin roles
    - `randomUtility` for secure password generation during admin auto-creation
  - DI configuration for `OidcCredentialAdapter`, `OidcCredentialPlugin`, and `Oidccallback` controller
  - Plugin configuration:
    - `oidc_credential_interceptor` plugin on `Magento\Backend\Model\Auth` (sortOrder: 10)
    - `oidc_captcha_bypass` plugin on `Magento\Captcha\Observer\CheckUserLoginBackendObserver` (sortOrder: 10)
- Events: `etc/events.xml` and `etc/adminhtml/events.xml`
- Routes: `etc/frontend/routes.xml` and `etc/adminhtml/routes.xml` both use `mooauth` as frontName
- ACL: `etc/acl.xml`
- CSP: `etc/csp_whitelist.xml` and `etc/adminhtml/csp_whitelist.xml`

#### Logging
- Custom logger: `Logger/Logger.php` and `Logger/Handler.php`
- Configured via DI to write to `var/log/mo_oauth.log`
- Use `$oauthUtility->customlog()` for logging throughout the module

### Admin Auto-Login Implementation

**Current Implementation (Native Magento Integration):**

The admin auto-login now uses Magento's native authentication system:

1. **Detection** (`Controller/Actions/CheckAttributeMappingAction.php:101-130`):
   - Checks if authenticated email exists in `admin_user` table
   - If admin exists, stores user info in session and redirects to admin callback
   - If admin doesn't exist and auto-create is enabled, creates admin user first (see Admin Auto-Creation below)

2. **Native Authentication Flow** (`Controller/Adminhtml/Actions/Oidccallback.php`):
   - Calls `Auth::login($email, 'OIDC_VERIFIED_USER')` with special token marker
   - Plugin system intercepts and injects OIDC credential adapter
   - All security events fire properly (pre/post authentication, ACL refresh, etc.)
   - CAPTCHA is automatically bypassed via plugin

3. **OIDC Adapter** (`Model/Auth/OidcCredentialAdapter.php`):
   - Implements `StorageInterface` required by Magento's Auth class
   - Validates OIDC token marker instead of password
   - Loads user from database, checks active status and role assignment
   - Records login and reloads user data
   - Handles session serialization via `__sleep()` and `__wakeup()`

4. **Plugin Orchestration**:
   - `OidcCredentialPlugin` detects token marker and injects adapter
   - `OidcCaptchaBypassPlugin` skips CAPTCHA for OIDC auth
   - Fires `admin_user_authenticate_before` and `admin_user_authenticate_after` events with `oidc_auth` marker

### Admin Auto-Creation

When "Auto Create Admin users while SSO" is enabled in Sign In Settings, admin users are automatically created during OIDC authentication:

**Flow** (`Controller/Actions/CheckAttributeMappingAction.php:152-215`):
1. **Attribute Extraction**: Uses configured attribute mappings for firstName, lastName, userName
2. **Name Fallbacks**: If names are empty, uses `explode("@", $email)` - email prefix for firstName, domain for lastName
3. **Group Extraction**: Reads OIDC groups from configured group attribute claim
4. **Role Assignment**: Maps OIDC groups to Magento admin roles using configured mappings
5. **User Creation**: Creates admin user with random secure password (authentication is via OIDC, not password)
6. **Login Redirect**: Redirects to admin callback for standard OIDC login flow

**Role Mapping Fallback Chain**:
1. Configured group-to-role mapping (case-insensitive group matching)
2. Default admin role (if configured)
3. "Administrators" role (searched by name)
4. Role ID 1 (ultimate fallback)

**Configuration UI** (Attribute Mapping page - `view/adminhtml/templates/attrsettings.phtml`):
- **Group Attribute Name**: OIDC claim containing group/role information (e.g., `groups`, `roles`, `memberOf`)
- **Default Admin Role**: Dropdown to select fallback role when no mapping matches
- **Role Mappings**: Dynamic rows mapping OIDC group names to Magento admin roles

**Technical Benefits:**
- ✅ All standard Magento authentication events fire correctly
- ✅ No need for external PHP scripts or bootstrap bypassing
- ✅ Works seamlessly with Magento's security layer (CAPTCHA, rate limiting, etc.)
- ✅ Proper ACL initialization and session management
- ✅ Maintains compatibility with other authentication plugins
- ✅ Clean separation of concerns via adapter pattern

**Session Management** (`Helper/SessionHelper.php`):
- `configureSSOSession()`: Sets SameSite=None on session cookies
- `updateSessionCookies()`: Updates existing cookies for cross-origin compatibility
- `forceSameSiteNone()`: Response observer hook to enforce cookie settings

### Attribute Mapping

OIDC claims are mapped to Magento user attributes via configuration stored in the database:
- Email: `email_attribute` (default: "email")
- Username: `username_attribute` (default: "preferred_username")
- First name: `firstname_attribute` (default: "name" with split)
- Last name: `lastname_attribute` (default: "name" with split)
- Groups: `group_attribute` (for role/group mapping)

**Admin Role Mapping** (for auto-created admin users):
- Group Attribute Name: OIDC claim containing groups (e.g., `groups`, `roles`)
- Default Admin Role: Fallback role when no group mapping matches
- Role Mappings: JSON-stored array of `{group: "OIDC_GROUP", role: "MAGENTO_ROLE_ID"}` pairs
- Configuration saved via `Attrsettings/Index.php` controller as `adminRoleMapping` config key

The module configuration is accessed via: **Stores → Configuration → MiniOrange → OAuth/OIDC**

## Use Cases for This Module

### When You'll Work on This Module

You'll interact with this module when:

- **Integrating a new OIDC provider** (Okta, Azure AD, Google, custom IdP)
  - Configure endpoints in `miniorange_oauth_client_apps` table
  - Test with `ShowTestResults.php` controller

- **Adding custom attribute mappings** (e.g., employee ID, department, custom fields)
  - Modify `Model/Service/CustomerUserCreator.php` or `AdminUserCreator.php`
  - Add columns to `etc/db_schema.xml` if new database fields needed

- **Debugging failed logins**
  - Enable debug logging: **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings > Enable debug logging**
  - Check `var/log/mo_oauth.log` for detailed flow logs
  - Common issues logged: email mismatch, attribute mapping failures, role mapping failures

- **Extending JIT provisioning logic** (custom default values, conditional logic)
  - Create plugins on `CheckAttributeMappingAction::execute()` method
  - Or observe authentication events: `admin_user_authenticate_before`, `admin_user_authenticate_after`

- **Adding new security bypasses** (e.g., 2FA module integration for OIDC users)
  - Follow pattern from `Plugin/Captcha/OidcCaptchaBypassPlugin.php`
  - Check for `oidc_authenticated` cookie or `oidc_auth` event marker

### Common Modification Scenarios

#### Scenario 1: Add New OIDC Claim to Customer Profile

**Goal**: Map a custom OIDC claim (e.g., `employee_id`) to a custom customer attribute.

**Files to modify**:
- [Model/Service/CustomerUserCreator.php](Model/Service/CustomerUserCreator.php) (lines 162-250)
- [etc/db_schema.xml](etc/db_schema.xml) (add column to `customer_entity` or create EAV attribute)
- [view/adminhtml/templates/attrsettings.phtml](view/adminhtml/templates/attrsettings.phtml) (add UI field for mapping)

**Pattern to follow**:
```php
// In CustomerUserCreator.php, follow the DOB mapping pattern:
$employeeId = $flattenedAttrs[$this->oauthUtility->getStoreConfig('employee_id_attribute')] ?? '';
if (!empty($employeeId)) {
    $customer->setCustomAttribute('employee_id', $employeeId);
}
```

**Steps**:
1. Add `employee_id_attribute` column to `miniorange_oauth_client_apps` table in `etc/db_schema.xml`
2. Add mapping logic in `CustomerUserCreator::createCustomer()` method
3. Add UI field in attribute mapping admin page
4. Test with **Test Configuration** to verify claim name from IdP
5. Run `bin/magento setup:upgrade && bin/magento setup:di:compile`

---

#### Scenario 2: Customize Admin Role Mapping Logic

**Goal**: Add custom logic to admin role assignment (e.g., map based on email domain, not just groups).

**Files to modify**:
- [Model/Service/AdminUserCreator.php](Model/Service/AdminUserCreator.php) (lines 142-185)
- Method: `getAdminRoleFromGroups(array $userGroups): ?int`

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

    // Continue with existing group mapping logic...
    $roleMappingsJson = $this->oauthUtility->getStoreConfig('adminRoleMapping');
    // ... rest of method
}
```

**Testing**:
1. Enable debug logging
2. Test login with users from different groups/domains
3. Check `var/log/mo_oauth.log` for "AdminUserCreator: Role ID assigned: X" messages
4. Verify user created with correct role in **System > Permissions > All Users**

---

#### Scenario 3: Add OIDC Button to Custom Theme

**Goal**: Display "Login with SSO" button on custom login page.

**Files to modify**:
- Your theme's `.phtml` template (e.g., `app/design/frontend/YourVendor/YourTheme/Magento_Customer/templates/form/login.phtml`)

**Code to add**:
```php
<?php
// Inject OAuthUtility helper via layout XML or get from ObjectManager
$oauthHelper = $block->getData('oauth_helper'); // Configure via layout XML

// For customer login (frontend):
$customerLoginUrl = $oauthHelper->getSPInitiatedUrl();

// For admin login (backend):
$adminLoginUrl = $oauthHelper->getAdminSPInitiatedUrl();
?>

<!-- Customer SSO Button -->
<div class="sso-login-button">
    <a href="<?= $escaper->escapeUrl($customerLoginUrl) ?>"
       class="action primary"
       title="<?= $escaper->escapeHtml(__('Login with SSO')) ?>">
        <span><?= $escaper->escapeHtml(__('Login with SSO')) ?></span>
    </a>
</div>
```

**Layout XML injection** (in your theme's `Magento_Customer/layout/customer_account_login.xml`):
```xml
<referenceBlock name="customer_form_login">
    <arguments>
        <argument name="oauth_helper" xsi:type="object">MiniOrange\OAuth\Helper\OAuthUtility</argument>
    </arguments>
</referenceBlock>
```

---

#### Scenario 4: Debug Failed Token Exchange

**Goal**: Token exchange fails with "configuration error" or "invalid_grant".

**Files to check**:
- [Controller/Actions/ProcessResponseAction.php](Controller/Actions/ProcessResponseAction.php) (lines 60-120)
- [Helper/Curl.php](Helper/Curl.php) — HTTP client for token endpoint

**Debugging steps**:
1. **Enable debug logging**: **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings > Enable debug logging**

2. **Trigger auth flow** and check `var/log/mo_oauth.log`:
   ```bash
   tail -f var/log/mo_oauth.log
   ```

3. **Look for these log entries**:
   - "Authorization code received: [code]" — confirms callback received code
   - "Token endpoint: [url]" — verify correct endpoint
   - "Token exchange response: [json]" — check for error messages
   - Common errors:
     - `invalid_grant`: Authorization code expired or already used
     - `invalid_client`: Client ID/Secret mismatch
     - `redirect_uri_mismatch`: Callback URL doesn't match IdP configuration

4. **Check IdP logs** for corresponding errors

5. **Verify configuration**:
   - Client ID and Secret match IdP
   - Callback URL matches: `https://your-site.com/mooauth/actions/ReadAuthorizationResponse`
   - Token endpoint URL correct (check for trailing slashes)

6. **Common fixes**:
   - Re-save OAuth Settings in admin panel (re-encrypts client secret)
   - Check `values_in_header` vs `values_in_body` setting (some IdPs require credentials in header)
   - Verify HTTPS configured correctly (HTTP will fail in production)

---

### Testing Checklist

Before deploying OIDC changes, verify:

- [ ] **Enable debug logging**: **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings > Enable debug logging**

- [ ] **Test customer flow**:
  - Navigate to frontend SSO link (or add SSO button to login page)
  - Redirected to IdP, authenticate successfully
  - Returned to Magento, customer session established
  - Check `var/log/mo_oauth.log` for "Customer login successful for: [email]"
  - Verify customer created in **Customers > All Customers** (if auto-create enabled)

- [ ] **Test admin flow**:
  - Navigate to admin SSO link (`/admin/mooauth/actions/SendAuthorizationRequest`)
  - Redirected to IdP, authenticate successfully
  - Returned to Magento admin dashboard
  - Check `var/log/mo_oauth.log` for "Admin login successful for: [email]"
  - Verify admin created in **System > Permissions > All Users** (if auto-create enabled)

- [ ] **Test auto-creation** (if enabled):
  - Use a new user email not in Magento database
  - Complete OIDC login flow
  - Verify user created with correct role/group
  - Check `var/log/mo_oauth.log` for "AdminUserCreator: User created successfully" or "CustomerUserCreator: Customer created"

- [ ] **Test attribute mapping**:
  - Click **Test Configuration** button in **Stores > Configuration > MiniOrange > OAuth/OIDC > OAuth Settings**
  - Verify all expected OIDC claims displayed correctly
  - Check claim names match your attribute mapping configuration (case-sensitive)
  - Update mappings if claim names differ from expected

- [ ] **Test logout**:
  - Log in via OIDC, then log out
  - Verify redirected to IdP logout URL (if `post_logout_url` configured)
  - Verify Magento session cleared (cannot access protected pages)
  - Check `oidc_authenticated` cookie deleted (for admin users)

- [ ] **Test error scenarios**:
  - Try login with user not in `admin_user` table (auto-create disabled) → should show "Admin account not found"
  - Try login with inactive admin user → should show "Admin account is inactive"
  - Try login with admin user with no role assigned → should show "Admin user has no assigned role"

---

## Future Improvements to Consider

When asked to enhance this module, consider these common scenarios and recommended approaches:

### If Asked to Add Tests

**Recommendation**:
- Use PHPUnit for Model and Helper classes
- Use Magento's integration testing framework for Controllers
- Create mock OIDC provider (Docker-based) for local testing

**Key test scenarios**:
```php
// Unit test example for AdminUserCreator
public function testCreateAdminUserWithGroupMapping()
{
    $userGroups = ['Engineering', 'Developers'];
    $email = 'test@example.com';

    // Mock role mapping: Engineering -> Role ID 2
    $this->configureRoleMapping(['Engineering' => 2]);

    $user = $this->adminUserCreator->createAdminUser($email, 'testuser', 'Test', 'User', $userGroups);

    $this->assertNotNull($user);
    $this->assertEquals(2, $user->getRoleId());
}
```

**Integration test example**:
```php
// Test full auth flow with mock IdP
public function testAdminOidcLoginFlow()
{
    $this->mockIdpResponse(['email' => 'admin@example.com', 'groups' => ['Administrators']]);

    $response = $this->dispatch('/admin/mooauth/actions/SendAuthorizationRequest');
    $this->assertRedirect(); // Redirected to IdP

    // Simulate callback
    $response = $this->dispatchCallback('/mooauth/actions/ReadAuthorizationResponse?code=mock_code&state=...');
    $this->assertRedirect('/admin'); // Redirected to admin dashboard

    $this->assertTrue($this->backendAuthSession->isLoggedIn());
}
```

---

### If Asked About Multi-Provider Support

**Current limitation**: Single provider per store (one row in `miniorange_oauth_client_apps` table).

**Refactoring needed**:
1. **Database schema change**:
   - Rename table to `miniorange_oauth_providers` (plural)
   - Add `provider_id` column as primary key
   - Add `provider_name` column for UI display
   - Migrate existing configuration to new schema with `provider_id = 1`

2. **Controller changes**:
   - Modify `SendAuthorizationRequest` to accept `provider_id` parameter
   - Store `provider_id` in OAuth state parameter
   - Retrieve correct provider config in `ReadAuthorizationResponse` based on state

3. **Admin UI changes**:
   - Add provider management grid: **Stores > Configuration > MiniOrange > OAuth/OIDC > Manage Providers**
   - Each provider has separate configuration page
   - Add provider selection dropdown on SSO buttons

**Example API**:
```php
// Generate SSO URL for specific provider
$loginUrl = $oauthHelper->getSPInitiatedUrl($relayState, $providerId);
```

---

### If Asked About Security Improvements

**CSRF Token Validation**:
- Add explicit CSRF token to `ReadAuthorizationResponse` controller
- Generate token in `SendAuthorizationRequest`, store in session
- Validate token in callback before processing authorization code
```php
// In SendAuthorizationRequest
$csrfToken = bin2hex(random_bytes(16));
$this->session->setCsrfToken($csrfToken);
$state = "$relayState|$sessionId|$appName|$loginType|$csrfToken";

// In ReadAuthorizationResponse
$stateParts = explode('|', $state);
$csrfToken = $stateParts[4] ?? '';
if ($csrfToken !== $this->session->getCsrfToken()) {
    throw new SecurityException('CSRF token mismatch');
}
```

**Scope Cookie Observer to OIDC Paths Only**:
- Modify `SessionCookieObserver::forceSameSiteNone()` to check request path
```php
public function forceSameSiteNone()
{
    $requestPath = $this->request->getRequestUri();

    // Only apply to OIDC routes
    if (strpos($requestPath, '/mooauth/') === false) {
        return; // Skip for non-OIDC requests
    }

    // Continue with cookie rewrite...
}
```

**Rate Limiting**:
- Use Magento's built-in rate limiting or integrate with Cloudflare
- Add rate limiter to `ReadAuthorizationResponse` controller
```php
// In ReadAuthorizationResponse::execute()
if (!$this->rateLimiter->isAllowed($this->request->getClientIp(), 'oidc_callback')) {
    throw new TooManyRequestsException('Rate limit exceeded');
}
```

---

### If Asked About GraphQL Support

**Goal**: Headless commerce needs SSO URL generation via GraphQL.

**Implementation**:

1. **Add schema** in `etc/schema.graphqls`:
```graphql
type Query {
    oidcLoginUrl(relayState: String): String @resolver(class: "MiniOrange\\OAuth\\Model\\Resolver\\OidcLoginUrl")
}
```

2. **Create resolver** in `Model/Resolver/OidcLoginUrl.php`:
```php
namespace MiniOrange\OAuth\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcLoginUrl implements ResolverInterface
{
    public function __construct(private OAuthUtility $oauthUtility) {}

    public function resolve($field, $context, $info, $value = null, $args = null)
    {
        $relayState = $args['relayState'] ?? null;
        return $this->oauthUtility->getSPInitiatedUrl($relayState);
    }
}
```

3. **Usage in frontend**:
```graphql
query {
  oidcLoginUrl(relayState: "/checkout")
}

# Returns: "https://your-site.com/mooauth/actions/SendAuthorizationRequest?relayState=%2Fcheckout"
```

---

### If Asked About Performance Optimization

**Common bottlenecks**:

1. **JWKS Fetching**: Currently fetches on every login
   - Add configurable cache TTL for JWKS responses
   - Cache in Redis or Magento cache instead of HTTP cache only

2. **Global Cookie Rewrite**: Rewrites all cookies on every response
   - Scope to OIDC paths only (see security improvements above)
   - Or apply only to session cookies, not all cookies

3. **Email Lookup Fallback**: Recursively searches entire OIDC response if email not in mapped attribute
   - Fail fast if email not in standard location
   - Require explicit configuration instead of fallback search

**Example optimization**:
```php
// In JwtVerifier.php, add Redis caching:
public function getJwks(string $jwksUrl): array
{
    $cacheKey = 'oidc_jwks_' . md5($jwksUrl);

    // Check Redis cache first
    if ($cachedJwks = $this->cache->load($cacheKey)) {
        return json_decode($cachedJwks, true);
    }

    // Fetch from IdP
    $jwks = $this->fetchJwksFromIdp($jwksUrl);

    // Cache for 24 hours
    $this->cache->save(json_encode($jwks), $cacheKey, [], 86400);

    return $jwks;
}
```

---

**For detailed technical specifications, refer to** [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md).
