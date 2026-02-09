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

- add option to disable non-OIDC login

- check php compatibility 8.1-8.4

- check Magento compatibility 2.4.7 - 2.4.8-p3

- check unused classes

- check source code and analyize it regarding best practise code cenventions

- code check: https://medium.com/data-science-collective/youre-using-ai-to-write-code-you-re-not-using-it-to-review-code-728e5ec2576e


- Storage of settings in 2 different tables why -> merging?
- whats needs to be adjusted to be future-proof and support multiple OIDC providers?

- Rename: --> Authelia OIDC (in Code)