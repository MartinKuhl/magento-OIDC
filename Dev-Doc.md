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

# Magento Coding Standard installieren (bringt phpcs/phpcbf automatisch mit)
composer require --dev magento/magento-coding-standard vimeo/psalm bitexpert/phpstan-magento rector/rector

# Prüfen, ob phpcs verfügbar ist
vendor/bin/phpcs --version
vendor/bin/phpcbf --version

vendor/bin/phpcs
vendor/bin/phpcbf

# Den Magento2-Standard in phpcs registrieren
#vendor/bin/phpcs --config-set installed_paths vendor/magento/magento-coding-standard/,vendor/phpcompatibility/php-compatibility/
#vendor/bin/phpcs --config-set installed_paths "$COMPOSER_HOME/vendor/magento/magento-coding-standard,/vendor/magento/php-compatibility-fork"


# Verfügbare Standards anzeigen
/var/www/html/vendor/bin/phpcs -i
# Ausgabe sollte enthalten: Magento2, PSR1, PSR2, PSR12, ...


# PHPSTan
/var/www/html/vendor/bin/phpstan clear-result-cache
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=phpstan.local.neon
cd /var/www/html/github/miniorange-oauth-sso && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=phpstan.local.neon

# Psalm
/var/www/html/vendor/bin/psalm --no-cache --config=github/miniorange-oauth-sso/psalm.xml
/var/www/html/vendor/bin/psalm --no-cache --config=github/miniorange-oauth-sso/psalm.xml --alter --issues=MissingReturnType,MissingParamType

/var/www/html/vendor/bin/psalm --no-cache --config=github/OIDC/psalm.xml
/var/www/html/vendor/bin/psalm --no-cache --config=github/OIDC/psalm.xml --alter --issues=MissingReturnType,MissingParamType

## Important: Working Directory for Static Analysis

**PHPStan and Psalm require different working directories due to their configuration differences.**

### PHPStan
PHPStan's `phpstan.neon` uses `paths: - .` which analyzes the **current working directory**.
For local development, use `phpstan.local.neon` which also specifies `magento.magentoRoot: ../..` to locate Magento type definitions.

- ✅ Correct: `cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=phpstan.local.neon`
- ❌ Wrong: `/var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=github/OIDC/phpstan.neon`

The wrong command analyzes the entire Magento installation (`/var/www/html/.`) instead of just the OIDC module,
and may have incorrect Magento root detection.

### Psalm
Psalm's `psalm.xml` has `resolveFromConfigFile="true"` which resolves paths relative to the **config file location**.
However, Psalm needs to find the Composer autoloader, so it must run from the Magento root.

- ✅ Correct: `/var/www/html/vendor/bin/psalm --no-cache --config=github/OIDC/psalm.xml`
- ❌ Wrong: `cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/psalm --no-cache` (can't find autoloader)

# PHPCS Variante mit Berücksichtigung der phpcs.xml (wie auch im CI-Workflow)
/var/www/html/vendor/bin/phpcs  --extensions=php,phtml /var/www/html/github/OIDC/
/var/www/html/vendor/bin/phpcbf  --extensions=php,phtml /var/www/html/github/OIDC/

/var/www/html/vendor/bin/phpcs  --extensions=php,phtml /var/www/html/github/miniorange-oauth-sso/
/var/www/html/vendor/bin/phpcbf  --extensions=php,phtml /var/www/html/github/miniorange-oauth-sso/

/var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=phpcs.xml .

/var/www/html/vendor/bin/phpcbf --standard=phpcs.xml  --extensions=php,phtml view/frontend/templates/customerssobutton.phtml
/var/www/html/vendor/bin/phpcbf --standard=phpcs.xml  --extensions=php,phtml view/adminhtml/templates/attrsettings.phtml

/var/www/html/vendor/bin/phpcs  --extensions=php,phtml /var/www/html/github/OIDC/Model/Auth/OidcCredentialAdapter.php
/var/www/html/vendor/bin/phpcbf  --extensions=php,phtml /var/www/html/github/OIDC/Model/Auth/OidcCredentialAdapter.php

## Rector

# Dry-Run (nur anzeigen):
/var/www/html/vendor/bin/rector process --dry-run

# Tatsächlich fixen:
/var/www/html/vendor/bin/rector process

## AI command
please look at @github/OIDC/Code-Review.md here you find phpcs issues mentioned for different files. Fix them all. Use sub-agents where applicable. DO not create new issue for PHPStan or PHPCS scans.


# root level

## Run PHPStan: 
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=phpstan.local.neon 

## Run Psalm:
cd /var/www/html/ && /var/www/html/vendor/bin/psalm --no-cache --config=/var/www/html/github/OIDC/psalm.xml --root=/var/www/html 

## Run PHPCS:
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=phpcs.xml . 

## Run Rector:
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/rector process --dry-run

## Run PHPUnit:
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpunit --configuration phpunit.xml --testsuite "MiniOrange OIDC Unit Tests"


####### check all ######
Please run PHPStan, Psalm, PHPCS, PHPUnit and Rector via:
## Run PHPStan: 
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=phpstan.local.neon 

## Run Psalm:
cd /var/www/html/ && /var/www/html/vendor/bin/psalm --no-cache --config=/var/www/html/github/OIDC/psalm.xml --root=/var/www/html 

## Run PHPCS:
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=phpcs.xml . 

## Run Rector:
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/rector process --dry-run

## Run PHPUnit:
cd /var/www/html/github/OIDC && /var/www/html/vendor/bin/phpunit --configuration phpunit.xml --testsuite "MiniOrange OIDC Unit Tests"


Fix all the mentioned issue and warnings. Use sub-agents where applicable. DO not create new issue for PHPStan, PHPUnit, Psalm, PHPCS and Rector.

##############################################


feat: add PHPUnit test suite, new services, security fixes, and CI test integration

Test infrastructure:
- Add full PHPUnit suite (Test/Unit/ + Test/Integration/) with 12 tests
- Add docker-compose.test.yml with Dex OIDC provider for integration tests
- Add phpunit.xml with unit and integration test suites
- Integrate unit tests (PHP 8.2/8.3/8.4 matrix) and integration tests into CI

New features:
- Add OidcSessionRegistry and TokenRefreshService for token lifecycle management
- Add BackChannelLogout controller (OIDC back-channel logout support)
- Add HealthCheck admin controller
- Add GraphQL schema (schema.graphqls) and Model/Resolver/
- Add Plugin/Csp/ for dynamic CSP header management
- Add Console/ commands and Setup/ scripts
- Add OidcCredentialAdapter for Magento auth integration
- Add admin menu entries and DB schema columns (etc/adminhtml/menu.xml, etc/db_schema.xml)

Security fixes:
- SEC-01: Enforce SSL verification in Curl.php and JwtVerifier.php
- SEC-03: Replace x-html Alpine.js binding in authentication-popup.phtml
- SEC-04: Remove FILTER_SANITIZE_URL from OAuthsettings/Index.php
- SEC-05: Clear hardcoded provider hostnames from CSP whitelists
- SEC-06: Unconditionally reset isOidcAuth flag in OidcCredentialPlugin
- SEC-07: Replace base64_encode with urlencode for OIDC error messages
- SEC-09: Use parse_url host comparison for relay state validation

Code quality:
- PHPCS compliance across all modified files
- OAuthUtility: add shared extractNameFromEmail() helper (REF-02)
- ACL title corrected to "MiniOrange OIDC" (REF-05)
- Remove phpcd-report.txt

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>


### FIX ###:
- No provider selected. Please open this page via Manage Providers → Edit. for all sections! --> align banner
- Display Name: <> Display Name <> OAuth Provider Name
- Editing provider: Authelia banner postion and size
- End Session (Logout) Endpoint style
- sign in settings to last position
- User Auto Redirect Settings:
- im und export?

- please check if Integration and/or unit tests needs to be adjusted

- NOT Magento UI Grid
- side bar ordering (Left and top)

### LATER - complex ###:
- Rename: --> Authelia OIDC (in Code)
- scope handling

Nächster Schritt (nach Stabilisierung):
PHPStan: Level 4
Psalm:   Level 4

Langfristig (Ziel für sauberen Code):
PHPStan: Level 6
Psalm:   Level 3


please check the screenshot. Here is the menu of creating a new customer and the customer overview where the Magento UI Grid is used. Please adjust the OIDC plugin so that for the overview of all providers the Magento UI Grid is used (Screenshot 2). The process of creating a new provider should look like in screenshot one. Here the provider settings should be added first. Than as a sub menu on the left site OAuth settings and Attribute Mapping. This should also apply for the editing of an existing provider like in screenshot 3. THe Sign in Settings should be accessable from provider overview site. Please use the standard magento components like in the screenshots for creating the UI. Please adjust the top navigation bar and the left magento menu accordingly. 

first Plan: Connect "Edit" button to provider context-mode tabs + navbar indicator

Second write implementation plan