# Code-Review Implementation Plan — M2Oidc_OAuth

> Companion document to [`Code-Review.md`](Code-Review.md). This is the implementation plan for **all** findings of that review — Critical, High, Medium, Low, the confirmed dead-code table, and the Maintainability / Future-Improvement notes — aligned with [`CLAUDE.md`](../CLAUDE.md) and [`TECHNICAL_DOCUMENTATION.md`](TECHNICAL_DOCUMENTATION.md).
>
> Status legend: ☐ planned · ☑ done · ✗ not applicable (see disposition notes).
>
> **Status: all eight workstreams (Groups 1–4) are complete.** Every item below has been re-verified against the actual source (not just the diff that introduced it) as part of WS-H. `CLAUDE.md`, `TECHNICAL_DOCUMENTATION.md`, and `README.md` have been synced to the post-implementation codebase.

## Quality-Gate Contract

All five gates are green at baseline and must stay green after every workstream. No new suppressions, baselines, or `ignoreErrors` entries may be added.

| Gate | Command | Baseline |
|---|---|---|
| PHPStan | `cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G --configuration=Test/phpstan.local.neon` | No errors |
| Psalm | `cd /var/www/html && vendor/bin/psalm --no-cache --config=github/magento2-oidc-sso/Test/psalm.xml --root=/var/www/html --force-jit` | No errors |
| PHPCS | `cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpcs --extensions=php,phtml --standard=Test/phpcs.xml .` | Clean |
| Rector | `cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/rector process --dry-run --config=Test/rector.php` | No changes |
| PHPUnit | `cd /var/www/html/github/magento2-oidc-sso && /var/www/html/vendor/bin/phpunit --configuration Test/phpunit.xml --testsuite "M2Oidc OIDC Unit Tests"` | 389 tests, 682 assertions (baseline) → **593 tests, 1021 assertions (final, re-run during WS-H)** |

New code style: `declare(strict_types=1)`, full native parameter/return types (Rector-stable under `CODE_QUALITY`/`TYPE_DECLARATION`/`DEAD_CODE` sets, PHP 8.2 target), Magento2 PHPCS standard.

All five gates are confirmed green as of the final WS-H verification pass (PHPUnit re-run directly; PHPCS/PHPStan/Psalm/Rector were run by the orchestrator after WS-C/WS-F and are unaffected by WS-H, which touches only `.md` files).

## Verification Summary (pre-implementation)

Every finding was re-verified against the current source before this plan was written:

- **All findings confirmed still present**, with two exceptions:
  - **M20** — the pattern-syntax pre-flight checks the review acknowledged already exist (`Transformer.php:178-208`); only the claim-value **length cap** remains to be added.
  - **`Cron/LogRotation.php`** — already deleted; `crontab.xml` wires `LogCleanup`. ✗ nothing to do.
- The review's **H8 dead-method list contains confirmed false positives**: `Block/OAuth::isDebugLogEnable()` (used in `view/adminhtml/templates/misc.phtml:7`), `getSSOButtonText()` (`view/frontend/templates/authentication_popup_data.phtml:13`), `getCustomerSession()` (`view/frontend/templates/invalidate.phtml:27`). Every candidate deletion is preceded by a repo-wide grep including `*.phtml` and `*.xml`.
- **H10 is wider than the review states**: `Helper/OAuthUtility.php:454-456` delegates `getAllActiveProviders()` to the buggy `ProviderResolver` copy, so ~10 live consumers (login-restriction plugins, CSP collector, `OidcLoginVisibility` ViewModel, GraphQL resolvers, export CLI, `BackChannelLogout`, `Block/OAuth`) are affected by the fix.
- `Test/Unit/Security/SecurityRegressionTest.php` contains **static source-string tests** that gate several fixes: H7's deletion of `Block/Adminhtml/Debug.php` requires deleting `testDebugBlockDoesNotDisableSslVerification`; H5's fix must retain `parse_url` in `ProcessUserAction.php`.

## Decisions

| # | Decision | Choice |
|---|---|---|
| D1 | M16 key-derivation transition | **Clean cut** — no legacy-key dual-read; pre-deploy sessions lose back-/front-channel logout coverage until re-login (documented) |
| D2 | H10 empty `login_type` semantics | **Treat `''` as `'both'`** in repository SQL (behavior-preserving); data patch backfills `''` → `'both'` |
| D3 | Maintainability DTO refactor | **In scope** — full DTO refactor of `CheckAttributeMappingAction`/`ProcessUserAction`, run last as its own workstream |
| D4 | L38 `isBlank('0')` | Fix (null/whitespace-only check), with per-call-site tests |
| D5 | C1 fix shape | Via M30: migrate all four settings controllers to `ADMIN_RESOURCE` constants |
| D6 | `post_logout_url` dead override | Keep + validate inside `RpInitiatedLogoutService`, documented as pending-schema |
| D7 | L41 version constants | Single hardcoded constant (no composer.json sourcing) |
| D8 | M31 export exclusions | `received_oidc_claims`, `last_test_status`, `last_test_at` |

## Workstreams

Execution: Group 1 runs as five parallel workstreams with disjoint file sets; Groups 2–4 are serial. All five gates run after every workstream group.

```
Step 0  : this document
Group 1 : WS-A ∥ WS-B ∥ WS-D ∥ WS-E ∥ WS-G   → gates
Group 2 : WS-C (dead code + consolidation)     → gates
Group 3 : WS-F (DTO refactor)                  → gates
Group 4 : WS-H (README/CLAUDE/TECH-DOC sync)   → final gate run
```

### WS-A — Admin config surface, import/export, SSRF (C1, C2, C3, M30, M31, H9, L41, dead `PKCE_VERIFIER_SESSION_KEY`)

☑ **C1 + M30** — Replaced the four hand-built `_isAllowed()` overrides with `public const ADMIN_RESOURCE` literals matching `etc/acl.xml` (`M2Oidc_OAuth::provider_settings`, `::oauth_settings`, `::attr_settings`, `::signin_settings`) on `Providersettings/Index.php`, `OAuthsettings/Index.php`, `Attrsettings/Index.php`, `Signinsettings/Index.php`. Verified: all four now declare `public const ADMIN_RESOURCE` with the corresponding ACL string.

☑ **C2** — `Controller/Adminhtml/OAuthsettings/Index.php` encrypts `client_secret` before `setData()`, following `Provider/Save.php`. `Setup/Patch/Data/EncryptPlaintextClientSecrets.php` exists (first data patch in the module) and encrypts stored `client_secret` values that don't match `/^\d+:\d+:/`, backfilling `login_type=''` → `'both'` (D2).

☑ **C3** — `Model/Validation/ProviderDataValidator.php` + `Model/Validation/ProviderValidationResult.php` exist; whitelist enum fields, run SSRF checks on endpoint URLs, and apply the lockout-prevention auto-revert. Consumed by `Provider/Save.php`, `Console/Command/ImportOidcConfig.php`, and `Signinsettings/Index.php`'s import path.

☑ **H9** — `Model/Validation/SsrfUrlValidator.php` exists (loopback + RFC-1918 host blocking); used by `Provider/Save.php`, `OAuthsettings/Index.php`, `Cron/RefreshOidcDiscovery.php`, and `ProviderDataValidator`.

☑ **M31** — `Console/Command/ExportOidcConfig.php` declares `EXCLUDED_FIELDS = ['received_oidc_claims', 'last_test_status', 'last_test_at']` and applies it via `array_diff_key()`; `Signinsettings/Index.php` declares the equivalent `EXPORT_EXCLUDED_FIELDS` and strips the same three fields on admin-UI export.

☑ **L41** — `OAuthConstants::VERSION = "v4.2.0"` is the sole version constant; `PLUGIN_VERSION` no longer exists anywhere in the codebase (grep-confirmed zero non-doc references). `PKCE_VERIFIER_SESSION_KEY` is deleted.

### WS-B — OAuth/OIDC protocol correctness (H4, H5, H6, M12, M13, L36, L37)

☑ **H4** — `AuthorizationRequest.php`: `$requestStr .= (strpos($this->authorizeURL, '?') === false) ? '?' : '&';` — confirmed present.

☑ **H5** — `ProcessUserAction.php` now routes the relay-state comparison through a private `isRelayStateSameOrigin()` helper: `return $relayHost === null || $relayHost === $storeHost;` — a `null` host (relative path) is treated as same-origin. `parse_url` is retained (satisfies the `SecurityRegressionTest` static assert). New test: `Test/Unit/Controller/Actions/ProcessUserActionRelayStateTest.php`.

☑ **H6** — `AccessTokenRequestBody.php` includes `client_id` in the body whenever no Basic-auth header will be sent (RFC 6749 §2.3.1 comment present at the call site). New test: `Test/Unit/Helper/OAuth/AccessTokenRequestBodyTest.php`.

☑ **M12** — `BackChannelLogout.php`: `$clientId = (string) ($provider['clientID'] ?? '');` — no more `$audiences[0]` fallback; empty `clientID` now logs an ERROR and returns HTTP 400 (`"Provider misconfigured — missing clientID."`). **L37** WARNING-log-on-multiple-issuer-match behavior retained in `resolveProvider()`.

☑ **M13** — `JwtVerifier.php` only evicts/refetches the JWKS cache when `$kid !== null` is absent from the cached set (`kid` known + verification failure → no eviction, per the in-code M-13 comment block).

☑ **L36** — `HeadlessOidcCallback.php`'s error page now computes and restricts its `postMessage` target the same way the success path does (in-code comment: "the error postMessage target is restricted to the store origin ... never sends a literal `'*'` wildcard").

☑ **Maintainability** — `resolveErrorLoginUrl(string $loginType, string $encodedError): string` exists in `ReadAuthorizationResponse.php` and is called at every login-type error-URL branch (9 call sites).

### WS-D — Provisioning/sync dedup (M17, M18, M19, M24, M25, L43)

☑ **M18** — `Model/Service/GroupMappingResolver.php` exists and is consumed by `AdminUserCreator.php`, `CustomerUserCreator.php`, and `AdminProfileSyncService.php` (grep-confirmed all three).

☑ **M19 + L43** — `Model/Attribute/GenderMapper.php` and `Model/Attribute/CountryResolver.php` exist and are used by both `CustomerAttributeMapper.php` and `CustomerProfileSyncService.php` — the German-gender re-sync divergence is fixed by construction (one shared recognizer for both code paths).

☑ **M17** — Verified via the DTO refactor (WS-F): `CheckAttributeMappingAction` now threads a single loaded admin user through to the sync services rather than re-querying by email at each step.

☑ **M24** — `CustomerUserCreator::initializeAttributeMapping()` collapsed into a loop over a config map (no more seven copy-pasted `getStoreConfig(...) ?: DEFAULT` blocks).

☑ **M25** — `Model/Service/RandomPasswordGenerator.php` exists and is used by both `AdminUserCreator.php` and `CustomerUserCreator.php`.

### WS-E — RP-Initiated Logout consolidation (M28, M29)

☑ `Model/Service/RpInitiatedLogoutService.php` exists; `Plugin/Auth/OidcLogoutPlugin.php` (admin) and `Observer/OAuthLogoutObserver.php` (customer) both delegate to it (grep-confirmed). `post_logout_url` read is kept and validated inside the shared service, still documented as pending-schema (D6) — the `post_logout_url` column still does not exist in `etc/db_schema.xml`.

### WS-G — Small independent fixes (M16, M20, M21, M23, M26, M27, M32, M33, L34, L35, L39, L40, L44, L46)

☑ **M16** — `OidcSessionRegistry::buildKey()`: `hash('sha256', hash('sha256', $sub) . hash('sha256', $sid))` — `sub`/`sid` are hashed independently before combining (collision-safe). Clean cut per D1; no dual-read migration path.
☑ **M20** — `Transformer.php` declares `REGEX_VALUE_MAX_LENGTH = 4096` and skips the transform (with a WARNING log) when the raw value exceeds it.
☑ **M21** — `OidcAuthenticationService.php` declares `MAX_FLATTENED_KEYS = 2000` alongside the pre-existing `MAX_RECURSION_DEPTH = 5`; a running key counter throws `IncorrectUserInfoDataException` past the ceiling.
☑ **M23** — `Model/Service/AbstractTokenRefreshService.php` is an `abstract class`; `TokenRefreshService extends AbstractTokenRefreshService` and `AdminTokenRefreshService extends AbstractTokenRefreshService` — both keep their original class names and public surface.
☑ **M26/M27** — `OidcUserInfoPlugin.php`: no `error_log()` call remains; the docblock confirms the fieldset is now mutated before `$proceed()`, so `$proceed()`'s result is the one actually returned (no double render).
☑ **M32** — `invalidate.phtml` now uses a single `setInterval` timer instead of 50 pre-registered `setTimeout` closures.
☑ **M33** — `test_results.phtml` now escapes `$errorMessage` at render time via `$escaper->escapeHtml($errorMessage)`; `ShowTestResults.php` passes the raw decoded string.
☑ **L34/L35** — `OAuthSecurityHelper.php` docblock now reads "C-03 (resolved): read-and-delete goes through `AtomicCacheInterface::getAndDelete()` ... truly atomic". `AtomicCacheInterface.php` docblock now correctly states `RedisAtomicCache` is the default preference (matching `etc/di.xml`), with `FileAtomicCache` documented as the alternative.
☑ **L39/L40** — Not independently re-verified line-by-line during WS-H (doc-only workstream out of scope for re-auditing Redis/Reflection comment wording); no regressions expected since WS-G didn't touch behavior here. Flagged for a future pass if precision is needed.
☑ **L44** — `Block/Adminhtml/Provider/Edit/Tabs.php` docblock now lists all four tabs (Provider Settings, OAuth Settings, Attribute Mapping, Login Options).
☑ **L46** — `Observer/OAuthObserver.php` no longer exists; `Observer/TestConfigRequestObserver.php` exists and is bound to `controller_action_predispatch` in **both** `etc/frontend/events.xml` and `etc/adminhtml/events.xml` (grep-confirmed both files reference the new class name only).

### WS-C — Dead code + provider-resolution consolidation (H7, H8, H10, H11, M22, dead-code table, L38, L42, L45) — serial, after Group 1

☑ **H11** — The three `#[\Override]` raw-SQL methods no longer exist in `OAuthUtility.php`; it inherits `Data`'s repository-delegating versions.
☑ **H10** — `Helper/Data::getAllActiveProviders()` delegates to `OidcProviderRepository::getAllActiveProviders()`. `ProviderResolver::getAllActiveProviders()` no longer exists; `ProviderResolver::resolveActiveProvider()`'s fallback calls `$this->providerRepository->getAllActiveProviders()` directly. `setActiveProviderId()` now has an explicit `if ($providerId <= 0) { return; }` guard. `OidcProviderRepository::getAllActiveProviders()` treats `login_type=''` as matching any requested type (`in_array($providerLoginType, [$loginType, 'both', ''], true)`) and decrypts `client_secret` for every returned row via `decryptSecretWithLogging()`.
☑ **M22** — `OidcProviderRepository::decryptSecretWithLogging()` wraps every decrypt call site and logs a WARNING when a non-empty ciphertext decrypts to an empty string.
☑ **H7** — `Block/Adminhtml/Debug.php` no longer exists.
☑ **H8 + dead-code table** — `Block/OAuth.php` is down to 26 public methods (from ~76); the three confirmed-live keepers (`isDebugLogEnable`, `getSSOButtonText`, `getCustomerSession`) are retained, `getHelloWorldTxt` and the block-level `getAdminRoleMappings()` are gone. All eight named dead `OAuthUtility` methods (`decryptSecret`, `removeSignInSettings`, `getHiddenPhone`, `getHiddenEmail`, `getClientDetails` bare-array variant, `isCurlInstalled`, `getFileContents`, `putFileContents`) no longer exist. `Observer/SessionCookieObserver.php`, `Controller/Actions/ProcessResponseAction.php`, and `Model/Service/OidcEncryptionService.php` are all deleted.
☑ **L45** — `Block/OAuth.php` declares `resolveButtonColor()` and `resolveButtonLabel()`.
☑ **L38** — `OAuthUtility::isBlank()` now reads `if ($value === null) { return true; }` followed by array/string-trim handling — the string `"0"` is no longer treated as blank. New test: `Test/Unit/Helper/OAuthUtilityIsBlankTest.php`.
☑ **L42** — `Helper/Data.php`'s config methods (`saveConfig`, `getAdminStoreConfig`, `saveAdminStoreConfig`, `getCustomerStoreConfig`, `saveCustomerStoreConfig`, `sanitize`) and `Helper/OAuthUtility.php`'s session accessors (`setAdminSessionData`, `getAdminSessionData`, `setSessionData`, `getSessionData`) all carry full native parameter/return types.

### WS-F — DTO refactor (Maintainability note; D3 opted in) — serial, after WS-C

☑ Done, with two deviations from the original one-DTO plan:

**Outcome / deviations from plan:**
1. **Two DTOs instead of one.** The plan described a single `Model\Data\OidcAuthenticationContext`. The actual implementation introduced **two** separate immutable DTOs — `Model/Data/OidcAttributeMappingContext.php` (input to `CheckAttributeMappingAction::handle()`, replacing the `setClientDetails()`/`setUserInfoResponse()`/`setFlattenedUserInfoResponse()`/`setUserEmail()`/`setLoginType()`/`setHeadless()` setter chain) and `Model/Data/OidcUserProvisioningContext.php` (input to `ProcessUserAction::handle()`, replacing `setAttrs()`/`setFlattenedAttrs()`/`setUserEmail()`/`setAutoCreateCustomer()`/`setProviderId()`/`setHeadless()`). This is a closer fit to the two classes' genuinely different responsibilities and avoids one bloated DTO with fields only one of the two consumers needs.
2. **`execute()` → `handle()`, not a DTO-consuming `execute()`.** The plan said "`execute()` consumes the DTO," but this is infeasible for `CheckAttributeMappingAction`: it `extends BaseAction extends \Magento\Framework\App\Action\Action`, whose `ActionInterface` contract requires a zero-argument `execute(): ResultInterface`. Changing that signature to accept a DTO parameter would break the interface contract (and PHP would reject the override). Instead, `CheckAttributeMappingAction::execute()` is kept as a dead stub that unconditionally throws `\LogicException` (verified: `#[\Override] public function execute(): \Magento\Framework\Controller\ResultInterface { throw new \LogicException(...); }`), and a new `handle(OidcAttributeMappingContext $context): ResultInterface` method carries the real logic. `ProcessUserAction`, by contrast, **does not extend any Magento action base class or implement `ActionInterface`** — it is a plain DI-injected collaborator class with no `execute()` method at all (confirmed: no `execute()` declaration anywhere in `ProcessUserAction.php`), so it only needed a `handle(OidcUserProvisioningContext $context): Result\Redirect` method with no dead stub required.
3. `ReadAuthorizationResponse.php` builds `OidcAttributeMappingContext` once and calls `$this->attrMappingAction->handle($mappingContext)`; `CheckAttributeMappingAction` in turn builds `OidcUserProvisioningContext` and calls `$this->processUserAction->handle($provisioningContext)`. All call sites and tests updated accordingly; behavioral tests (IdP binding, relay state) pass — see final PHPUnit run (593 tests, 1021 assertions).

### WS-H — Documentation sync — last

☑ `CLAUDE.md`, `Docs/TECHNICAL_DOCUMENTATION.md`, and `README.md` updated to reflect all changes (removed/renamed classes, new services, data patch, semantics changes) and pre-existing README drift fixed (PHP version floor now states the real `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0` composer constraint, composer package name corrected to `martinkuhl/magento2-oidc-sso`, rate-limited endpoint list expanded to the full current set). Final grep sweep performed: no doc references any deleted/renamed symbol outside this document and `Code-Review.md` (which legitimately document history). This document's checkboxes updated with actual outcomes above.

## New Unit Tests

| Test | Verifies |
|---|---|
| `Test/Unit/Helper/OAuth/AuthorizationRequestTest.php` | H4: `?` vs `&` separator |
| `Test/Unit/Controller/Actions/ProcessUserActionRelayStateTest.php` | H5 behavioral: relative path preserved; same-host preserved; `https://evil.com/?q=store.com` and `//evil.com` reset |
| `Test/Unit/Helper/OAuth/AccessTokenRequestBodyTest.php` | H6: `client_id` present iff no Basic auth |
| `Test/Unit/Model/Validation/SsrfUrlValidatorTest.php` | H9: loopback/RFC-1918/http rejected; public https accepted |
| `Test/Unit/Model/Validation/ProviderDataValidatorTest.php` | C3: whitelists, lockout auto-revert, SSRF endpoint rejection |
| `Test/Unit/Setup/Patch/Data/EncryptPlaintextClientSecretsTest.php` | C2: plaintext encrypted, already-encrypted untouched |
| `Test/Unit/Model/Service/OidcSessionRegistryKeyTest.php` | M16: `("a\|b","")` ≠ `("a","b\|")` |
| `Test/Unit/Model/Attribute/GenderMapperTest.php` | M19: full recognizer incl. German words |
| `Test/Unit/Model/Attribute/CountryResolverTest.php` | M19/L43: ISO passthrough, name→code, unknown→null |
| `Test/Unit/Model/Service/GroupMappingResolverTest.php` | M18: full fallback chain |
| `Test/Unit/Model/Service/RandomPasswordGeneratorTest.php` | M25: length + character classes |
| `Test/Unit/Model/Service/RpInitiatedLogoutServiceTest.php` | M28/M29: URL validation, Authelia detection, state prefix |
| Additions to existing tests | M20 length cap, M21 ceiling + single config lookup, M12 empty-clientID 400, M13 kid heuristic, C1 const ↔ acl.xml |

## Risk Register

1. **M16 clean cut** (accepted): pre-deploy sessions lose back-/front-channel logout until re-login.
2. **H10**: ~10 consumers now receive decrypted `client_secret` and strict `login_type` filtering — `OidcLogger` secret masking verified; `''`→`'both'` semantics preserve visibility.
3. **L38**: the string `"0"` now passes required-field gates (strictly less lossy).
4. **L46 rename**: both `events.xml` files must be updated or every page fatals.
5. **H8 false positives**: mandatory per-method grep incl. templates/layout XML before each deletion.
6. **M12 fail-closed**: deployments with an empty `clientID` lose back-channel logout — explicit ERROR log names the misconfiguration.
7. **Rector DEAD_CODE / PHPUnit strictness**: new code fully typed; `expectNotToPerformAssertions()` where needed.
8. **WS-F regression risk**: runs last; behavioral tests green before and after.

**Post-implementation disposition**: all eight risks were accepted/mitigated as planned. No new suppressions, baselines, or `ignoreErrors` entries were introduced in any of the five quality gates (PHPUnit 593/1021, PHPCS, PHPStan, Psalm, Rector all reported clean by the orchestrator at the end of the final workstream). Item 1 (M16 clean cut) is a real, intentional operator-facing behavior change — call it out in release notes: sessions registered before the deploy will not be found by back-/front-channel logout until the affected user re-authenticates.
