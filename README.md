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

- code check: https://medium.com/data-science-collective/youre-using-ai-to-write-code-you-re-not-using-it-to-review-code-728e5ec2576e

Review this code as a senior developer.
Check for:
1. Bugs: Logic errors, off-by-one, null handling, race conditions
2. Security: Injection risks, auth issues, data exposure
3. Performance: N+1 queries, unnecessary loops, memory leaks
4. Maintainability: Naming, complexity, duplication
5. Edge cases: What inputs would break this?
6. Unused sections: unused classes nad functions
7. Code conventions: Are Magento best practise for coding used

For each issue:
- Severity: Critical / High / Medium / Low
- Line number or section
- What's wrong
- How to fix it

Be harsh. I'd rather fix issues now than in production.

Write an dupdate the exisiting review into @github/OIDC/Code-Review.md 

################

Update @github/OIDC/TECHNICAL_DOCUMENTATION.md for this code:

Include:
1. Overview: What this module does and why it exists
2. Structure: How is the plugin structured 
3. Quick Start: How to use it in 3 steps or less
4. Functionalities and Use Case what is the plugin for
5. Gotchas: Edge cases, limitations, and things that will bite you
6. What can be future improvements to opitmize the plugin

Write for a developer who's new to this codebase but not new to coding.

####################################################################################

- Storage of settings in 2 different tables why -> merging?
- whats needs to be adjusted to be future-proof and support multiple OIDC providers?

- Rename: --> Authelia OIDC (in Code)

Nächster Schritt (nach Stabilisierung):
PHPStan: Level 4
Psalm:   Level 4

Langfristig (Ziel für sauberen Code):
PHPStan: Level 6
Psalm:   Level 3


# Magento Coding Standard installieren (bringt phpcs/phpcbf automatisch mit)
composer require --dev magento/magento-coding-standard

# Prüfen, ob phpcs verfügbar ist
vendor/bin/phpcs --version
vendor/bin/phpcbf --version

vendor/bin/phpcs
vendor/bin/phpcbf

# Den Magento2-Standard in phpcs registrieren
vendor/bin/phpcs --config-set installed_paths vendor/magento/magento-coding-standard/,vendor/phpcompatibility/php-compatibility/

vendor/bin/phpcs --config-set installed_paths "$COMPOSER_HOME/vendor/magento/magento-coding-standard,/vendor/magento/php-compatibility-fork"


# Verfügbare Standards anzeigen
vendor/bin/phpcs -i
# Ausgabe sollte enthalten: Magento2, PSR1, PSR2, PSR12, ...

vendor/bin/phpcbf --standard=Magento2 ./github/miniorange-oauth-sso/
vendor/bin/phpcs --standard=Magento2 ./github/miniorange-oauth-sso/

