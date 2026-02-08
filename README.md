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


- check php compatibility 8.1-8.4

- add option to disable non-OIDC login

- Storage of settings in 2 different tables why -> merging?

- Rename: --> Authelia OIDC (in Code)