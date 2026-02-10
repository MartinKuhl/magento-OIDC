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

################

Update @github/OIDC/TECHNICAL_DOCUMENTATION.md  for this code:

Include:
1. Overview: What this module does and why it exists
2. Structure: How is the plugin structured 
3. Quick Start: How to use it in 3 steps or less
4. API Reference: Every public function with params, returns, and examples
5. Common Patterns: The 3 most common use cases with code
6. Gotchas: Edge cases, limitations, and things that will bite you
7. Related: What other modules this works with 

Write for a developer who's new to this codebase but not new to coding.

####################################################################################

- Storage of settings in 2 different tables why -> merging?
- whats needs to be adjusted to be future-proof and support multiple OIDC providers?

- Rename: --> Authelia OIDC (in Code)