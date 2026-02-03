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

## Recent Changes (Last Merge: 5ea0025)

The last merge implemented **native Magento login integration** for admin users, replacing the previous session manipulation approach with Magento's standard authentication flow:

### Native Login Implementation (PR #4 - task-magento-nativ-login)

**Key improvements:**
- Admin login now uses `Auth::login()` instead of directly manipulating session
- OIDC authentication integrates seamlessly with Magento's security events
- CAPTCHA is automatically bypassed for OIDC-authenticated users
- All standard authentication checks and events fire properly

**New Components:**
1. **OidcCredentialAdapter** (`Model/Auth/OidcCredentialAdapter.php`) - Implements Magento's `StorageInterface` to bridge OIDC authentication with native Magento auth system
2. **OidcCredentialPlugin** (`Plugin/Auth/OidcCredentialPlugin.php`) - Intercepts `Auth::getCredentialStorage()` to inject OIDC adapter when OIDC token marker is detected
3. **OidcCaptchaBypassPlugin** (`Plugin/Captcha/OidcCaptchaBypassPlugin.php`) - Bypasses CAPTCHA validation for OIDC users (authentication already happened at IdP)

**Modified Components:**
- `Controller/Adminhtml/Actions/Oidccallback.php` - Now calls `Auth::login($email, 'OIDC_VERIFIED_USER')` instead of direct session manipulation
- `etc/di.xml` - Added DI configuration for new adapter and plugins

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
- `CheckAttributeMappingAction.php`: Routes users based on admin/customer detection and maps OIDC attributes
- `ProcessUserAction.php`: Creates or updates Magento users based on OIDC data
- `ShowTestResults.php`: Displays test results for attribute mapping
- `Adminhtml/Actions/Oidccallback.php`: Admin callback that performs native Magento login via `Auth::login()`

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
- `AdminAuthHelper.php`: Handles admin authentication, generates standalone login URLs (deprecated by native login)
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
  - Constructor arguments for `CheckAttributeMappingAction` with admin-related dependencies
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

**Technical Benefits:**
- ✅ All standard Magento authentication events fire correctly
- ✅ No need for external PHP scripts or bootstrap bypassing
- ✅ Works seamlessly with Magento's security layer (CAPTCHA, rate limiting, etc.)
- ✅ Proper ACL initialization and session management
- ✅ Maintains compatibility with other authentication plugins
- ✅ Clean separation of concerns via adapter pattern

**Legacy Components (Deprecated):**
- `Helper/AdminAuthHelper.php`: Previously generated standalone login URLs (no longer needed)
- `direct-admin-login.php`: External script approach (replaced by native integration)

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

The TODO in README.md mentions fixing attribute mapping, particularly:
- Splitting name field (Part 0/1 for first/last name)
- Handling additional scopes (phone & address)
- Extending user creation fields
- Auto-creating admins only for users with "admin" in group attribute

## Current Known Issues (from README.md)

1. **Attribute Mapping**: Name splitting and extended fields need work
2. **User Creation**: Need to implement auto-admin creation based on group membership
3. **Login/Logout Options**: Configuration needs fixing
4. **Greeting**: Should use preferred_username instead of email
5. **Additional Scopes**: Phone & address attributes not fully implemented
6. **Naming**: Consider renaming to "Authelia OIDC" in code

## Files Modified/Added in Last Merge (5ea0025)

**New Files:**
- `Model/Auth/OidcCredentialAdapter.php` (340 lines) - Native Magento auth adapter
- `Plugin/Auth/OidcCredentialPlugin.php` (148 lines) - Auth interception plugin
- `Plugin/Captcha/OidcCaptchaBypassPlugin.php` (70 lines) - CAPTCHA bypass plugin

**Modified Files:**
- `Controller/Adminhtml/Actions/Oidccallback.php` - Switched from session manipulation to `Auth::login()`
- `etc/di.xml` - Added DI configuration for new adapter and plugins
- `CLAUDE.md` - Documentation updates
- `README.md` - Updated with new implementation details

## Development Workflow

When making changes:

1. If modifying database schema: Update `etc/db_schema.xml` → Run `setup:db-schema:upgrade`
2. If adding new classes or constructor changes: Run `setup:di:compile`
3. If modifying templates: Run `setup:static-content:deploy -f`
4. Always clear cache: `cache:flush`
5. Check logs in `var/log/mo_oauth.log` for debugging

The module configuration is accessed via: **Stores → Configuration → MiniOrange → OAuth/OIDC**
