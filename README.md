# Magento 2 OIDC / OAuth SSO Plugin

## Features

- ✅ OIDC/OAuth authentication for Magento 2
- ✅ **Automatic admin login** after successful OIDC authentication
- ✅ Customer SSO support
- ✅ Attribute mapping
- ✅ Compatible with Authelia and other OIDC providers

## Installation

1. xxx
2. Run setup commands:

```bash
    composer require xxx
```
## Admin Auto-Login

After successful OIDC authentication, admin users are automatically logged in without requiring additional credentials. The system:

1. Detects if the authenticated email belongs to an admin user
2. Redirects to a secure admin callback endpoint
3. Creates an admin session
4. Redirects to the admin dashboard

## Configuration

Navigate to: **Stores → Configuration → MiniOrange → OAuth/OIDC**

## ToDo

- Fix Attribute Mapping
    email: m...k..@gmx.net
    groups: admins
    name: Martin Kuhl
        Split (Part0/1)
    preferred_username: martin

Username:   preferred_username
E-Mail:     email
Firstname   Split 0 - name
Last Name   Split 1 - name
    Split Option


- fix hello to prefered User Name

Create User : extended fields
Create admin : only for users with group (include admin lowerstring instring)

- update attributes options

- Fix LOGIN / LOGOUT OPTIONS

- handling additional scopes (phone & address)

- Rename: --> Authelia OIDC (in Code)

2. **Helper** (`Helper/AdminAuthHelper.php`):
   - `getStandaloneLoginUrl()`: Generates URL to `direct-admin-login.php` script
   - Script must be placed at Magento root to bypass bootstrap restrictions
   --> can this be improves to be more aligned with the Magento standard approach?