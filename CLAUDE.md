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

2. **Admin Flow** (Backend):
   - Route: `mooauth` (defined in etc/adminhtml/routes.xml)
   - Same initial flow as customer
   - **Critical difference**: In `CheckAttributeMappingAction:101-130`, admin users are detected by checking if email exists in `admin_user` table
   - Admin users are redirected to a separate admin callback endpoint in the adminhtml area
   - Admin session is created using `AdminAuthHelper` which provides a standalone login URL

### Key Components

#### Controllers (Controller/Actions/)
- `BaseAction.php` / `BaseAdminAction.php`: Base classes for OAuth actions
- `SendAuthorizationRequest.php`: Initiates OAuth flow
- `ReadAuthorizationResponse.php`: Handles OAuth callback
- `ProcessResponseAction.php`: Exchanges authorization code for access token
- `CheckAttributeMappingAction.php`: Routes users based on admin/customer detection and maps OIDC attributes
- `ProcessUserAction.php`: Creates or updates Magento users based on OIDC data
- `ShowTestResults.php`: Displays test results for attribute mapping

#### Helpers (Helper/)
- `OAuthUtility.php`: Core utility class extending Data class, provides common functions
- `AdminAuthHelper.php`: Handles admin authentication, generates standalone login URLs
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
- Dependency injection: `etc/di.xml` defines constructor arguments for `CheckAttributeMappingAction` with admin-related dependencies
- Events: `etc/events.xml` and `etc/adminhtml/events.xml`
- Routes: `etc/frontend/routes.xml` and `etc/adminhtml/routes.xml` both use `mooauth` as frontName
- ACL: `etc/acl.xml`
- CSP: `etc/csp_whitelist.xml` and `etc/adminhtml/csp_whitelist.xml`

#### Logging
- Custom logger: `Logger/Logger.php` and `Logger/Handler.php`
- Configured via DI to write to `var/log/mo_oauth.log`
- Use `$oauthUtility->customlog()` for logging throughout the module

### Admin Auto-Login Implementation

The admin auto-login feature is split across multiple files:

1. **Detection** (`Controller/Actions/CheckAttributeMappingAction.php:101-130`):
   - Checks if authenticated email exists in `admin_user` table
   - If admin exists, stores user info in session and redirects to admin callback

2. **Helper** (`Helper/AdminAuthHelper.php`):
   - `getStandaloneLoginUrl()`: Generates URL to `direct-admin-login.php` script
   - Script must be placed at Magento root to bypass bootstrap restrictions

3. **Session Management** (`Helper/SessionHelper.php`):
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

## Development Workflow

When making changes:

1. If modifying database schema: Update `etc/db_schema.xml` → Run `setup:db-schema:upgrade`
2. If adding new classes or constructor changes: Run `setup:di:compile`
3. If modifying templates: Run `setup:static-content:deploy -f`
4. Always clear cache: `cache:flush`
5. Check logs in `var/log/mo_oauth.log` for debugging

The module configuration is accessed via: **Stores → Configuration → MiniOrange → OAuth/OIDC**
