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
