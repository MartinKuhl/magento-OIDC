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

Write an update the exisiting review into @github/magento2-oidc-sso/Docs/Code-Review.md 

################

Update @github/magento2-oidc-sso/Docs/TECHNICAL_DOCUMENTATION.md for this code:

Include:
1. Overview: What this module does and why it exists
2. Structure: How is the plugin structured 
3. Quick Start: How to use it in 3 steps or less
4. Functionalities and Use Case what is the plugin for
5. Gotchas: Edge cases, limitations, and things that will bite you
6. What can be future improvements to opitmize the plugin

Write for a developer who's new to this codebase but not new to coding.

####################################################################################

# Prüfen, ob phpcs verfügbar ist
vendor/bin/phpcs --version
vendor/bin/phpcbf --version

# Verfügbare Standards anzeigen
/var/www/html/vendor/bin/phpcs -i
# Ausgabe sollte enthalten: Magento2, PSR1, PSR2, PSR12, ...

/var/www/html/vendor/bin/phpcs  --extensions=php,phtml /var/www/html/github/magento2-oidc-sso/Model/Auth/OidcCredentialAdapter.php
/var/www/html/vendor/bin/phpcbf  --extensions=php,phtml /var/www/html/github/magento2-oidc-sso/Model/Auth/OidcCredentialAdapter.php

## Rector

# Dry-Run (nur anzeigen):
/var/www/html/vendor/bin/rector process --dry-run --config=Test/rector.php

# Tatsächlich fixen:
/var/www/html/vendor/bin/rector process --config=Test/rector.php

## AI command
please look at @github/magento2-oidc-sso/Docs/Code-Review.md here you find phpcs issues mentioned for different files. Fix them all. Use sub-agents where applicable. DO not create new issue for PHPStan or PHPCS scans.


# root level

####### check all ######
Please run PHPStan, Psalm, PHPCS, PHPUnit and Rector via:
## Run PHPStan:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=Test/phpstan.local.neon

## Run Psalm:
cd /var/www/html/ && /var/www/html/vendor/bin/psalm --no-cache --config=/var/www/html/github/magento2-oidc-sso/Test/psalm.xml --root=/var/www/html --force-jit

## Run PHPCS:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=Test/phpcs.xml .

## Run Rector:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/rector process --dry-run --config=Test/rector.php

## Run PHPUnit:
cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpunit --configuration Test/phpunit.xml --testsuite "M2Oidc OIDC Unit Tests"


Fix all the mentioned issue and warnings. Use sub-agents where applicable. DO not create new issue for PHPStan, PHPUnit, Psalm, PHPCS and Rector.

##############################################

### ToDo ###

- how to fetch urn:zitadel:iam:org:project:roles

urn:zitadel:iam:org:project:365367478861168644:roles.admin.365347902450630660	    casa-kuhl.zitadel.casa-kuhl.duckdns.org
urn:zitadel:iam:org:project:365367478861168644:roles.non-Admin.365347902450630660	casa-kuhl.zitadel.casa-kuhl.duckdns.org
urn:zitadel:iam:org:project:roles.admin.365347902450630660	                        casa-kuhl.zitadel.casa-kuhl.duckdns.org
urn:zitadel:iam:org:project:roles.non-Admin.365347902450630660	                    casa-kuhl.zitadel.casa-kuhl.duckdns.org

urn:zitadel:iam:user:metadata.groups --> groups

- customer logout url error

- customer is successfully removed from OIDC Session Activity after removel but admin is not removed successfully. Also in OIDC Provider management table to admin user counter is not adjusted after removal of OIDC admin.


#new provider setup
- new provider: section provoder settings: Acitve -> checkbox is missing
- Client secret is mandatory
- what is Grant type
- what is Send Credential option

### FIX ###
-

### TESTING ###
- 

### LATER - more complex ###:
-

Nächster Schritt (nach Stabilisierung):
PHPStan: Level 4
Psalm:   Level 4

Langfristig (Ziel für sauberen Code):
PHPStan: Level 6
Psalm:   Level 3


# install testing dependencies

# Magento Coding Standard installieren (bringt phpcs/phpcbf automatisch mit)

#composer config minimum-stability dev
#composer config minimum-stability stable

composer require magento/magento-coding-standard bitexpert/phpstan-magento vimeo/psalm phpunit/phpunit:^10.5 rector/rector --no-update #phpstan/phpstan
composer update --no-dev



https://m2-local.casa-kuhl.de/m2oidc/actions/idpInitiatedLogin?provider_id=1

&login_hint=martin_kuhl@gmx.net

Zitadel:
ClientID: 365367495487455236

[2026-03-23T14:34:58.433962+00:00] main.CRITICAL: Exception: Warning: Array to string conversion in /var/www/html/github/magento2-oidc-sso/view/frontend/templates/test_results.phtml on line 71 in /var/www/html/vendor/magento/framework/App/ErrorHandler.php:61
Stack trace:
#0 /var/www/html/github/magento2-oidc-sso/view/frontend/templates/test_results.phtml(71): Magento\Framework\App\ErrorHandler->handler(2, 'Array to string...', '/var/www/html/g...', 71)
#1 [internal function]: M2Oidc\OAuth\Controller\Actions\ShowTestResults::{closure}(Array)
#2 /var/www/html/github/magento2-oidc-sso/view/frontend/templates/test_results.phtml(70): array_map(Object(Closure), Array)
#3 /var/www/html/github/magento2-oidc-sso/Controller/Actions/ShowTestResults.php(233): include('/var/www/html/g...')
#4 /var/www/html/github/magento2-oidc-sso/Controller/Actions/ShowTestResults.php(145): M2Oidc\OAuth\Controller\Actions\ShowTestResults->renderTemplate(Array)
#5 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(58): M2Oidc\OAuth\Controller\Actions\ShowTestResults->execute()
#6 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(138): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->___callParent('execute', Array)
#7 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(153): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->Magento\Framework\Interception\{closure}()
#8 /var/www/html/generated/code/M2Oidc/OAuth/Controller/Actions/ShowTestResults/Interceptor.php(23): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->___callPlugins('execute', Array, Array)
#9 /var/www/html/vendor/magento/framework/App/Action/Action.php(111): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->execute()
#10 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(58): Magento\Framework\App\Action\Action->dispatch(Object(Magento\Framework\App\Request\Http))
#11 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(138): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->___callParent('dispatch', Array)
#12 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(153): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->Magento\Framework\Interception\{closure}(Object(Magento\Framework\App\Request\Http))
#13 /var/www/html/generated/code/M2Oidc/OAuth/Controller/Actions/ShowTestResults/Interceptor.php(32): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->___callPlugins('dispatch', Array, Array)
#14 /var/www/html/vendor/magento/framework/App/FrontController.php(245): M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor->dispatch(Object(Magento\Framework\App\Request\Http))
#15 /var/www/html/vendor/magento/framework/App/FrontController.php(212): Magento\Framework\App\FrontController->getActionResponse(Object(M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor), Object(Magento\Framework\App\Request\Http))
#16 /var/www/html/vendor/magento/framework/App/FrontController.php(146): Magento\Framework\App\FrontController->processRequest(Object(Magento\Framework\App\Request\Http), Object(M2Oidc\OAuth\Controller\Actions\ShowTestResults\Interceptor))
#17 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(58): Magento\Framework\App\FrontController->dispatch(Object(Magento\Framework\App\Request\Http))
#18 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(138): Magento\Framework\App\FrontController\Interceptor->___callParent('dispatch', Array)
#19 /var/www/html/vendor/magento/module-store/App/FrontController/Plugin/RequestPreprocessor.php(99): Magento\Framework\App\FrontController\Interceptor->Magento\Framework\Interception\{closure}(Object(Magento\Framework\App\Request\Http))
#20 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(135): Magento\Store\App\FrontController\Plugin\RequestPreprocessor->aroundDispatch(Object(Magento\Framework\App\FrontController\Interceptor), Object(Closure), Object(Magento\Framework\App\Request\Http))
#21 /var/www/html/vendor/magento/module-page-cache/Model/App/FrontController/BuiltinPlugin.php(76): Magento\Framework\App\FrontController\Interceptor->Magento\Framework\Interception\{closure}(Object(Magento\Framework\App\Request\Http))
#22 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(135): Magento\PageCache\Model\App\FrontController\BuiltinPlugin->aroundDispatch(Object(Magento\Framework\App\FrontController\Interceptor), Object(Closure), Object(Magento\Framework\App\Request\Http))
#23 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(153): Magento\Framework\App\FrontController\Interceptor->Magento\Framework\Interception\{closure}(Object(Magento\Framework\App\Request\Http))
#24 /var/www/html/generated/code/Magento/Framework/App/FrontController/Interceptor.php(23): Magento\Framework\App\FrontController\Interceptor->___callPlugins('dispatch', Array, NULL)
#25 /var/www/html/vendor/magento/framework/App/Http.php(116): Magento\Framework\App\FrontController\Interceptor->dispatch(Object(Magento\Framework\App\Request\Http))
#26 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(58): Magento\Framework\App\Http->launch()
#27 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(138): Magento\Framework\App\Http\Interceptor->___callParent('launch', Array)
#28 /var/www/html/vendor/magento/module-application-performance-monitor/Plugin/ApplicationPerformanceMonitor.php(38): Magento\Framework\App\Http\Interceptor->Magento\Framework\Interception\{closure}()
#29 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(135): Magento\ApplicationPerformanceMonitor\Plugin\ApplicationPerformanceMonitor->aroundLaunch(Object(Magento\Framework\App\Http\Interceptor), Object(Closure))
#30 /var/www/html/vendor/magento/framework/Interception/Interceptor.php(153): Magento\Framework\App\Http\Interceptor->Magento\Framework\Interception\{closure}()
#31 /var/www/html/generated/code/Magento/Framework/App/Http/Interceptor.php(23): Magento\Framework\App\Http\Interceptor->___callPlugins('launch', Array, NULL)
#32 /var/www/html/vendor/magento/framework/App/Bootstrap.php(264): Magento\Framework\App\Http\Interceptor->launch()
#33 /var/www/html/pub/index.php(30): Magento\Framework\App\Bootstrap->run(Object(Magento\Framework\App\Http\Interceptor))
#34 {main} {"report_id":"575ec455ff33fe6bb3c8f8d365066e0395e834d36ad1ce36da5edc454b57bac0","exception":"[object] (Exception(code: 0): Warning: Array to string conversion in /var/www/html/github/magento2-oidc-sso/view/frontend/templates/test_results.phtml on line 71 at /var/www/html/vendor/magento/framework/App/ErrorHandler.php:61)"} []
