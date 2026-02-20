# Magento 2 OAuth/OIDC Single Sign-On Module

Enterprise-grade OpenID Connect authentication for Magento 2, supporting both customer (frontend) and admin (backend) users with automatic user provisioning and role management.

## Overview

### Why This Module?

Modern e-commerce platforms require secure, centralized authentication. This module bridges Magento 2 with your corporate Identity Provider (IdP), eliminating password management overhead while enhancing security.

**Key Benefits:**

- **Unified Identity Management**: Single source of truth for user authentication across your organization
- **Zero-Touch Provisioning**: Automatically create customer and admin accounts on first login
- **Enhanced Security**: Eliminate password-based attacks, enforce IdP-level MFA, centralize access control
- **Seamless Integration**: Works with Magento's native authentication—all security events fire correctly
- **Rich Attribute Mapping**: Map 30+ OIDC claims to Magento fields (address, phone, date of birth, customer groups)
- **Compliance Ready**: Centralized audit trails, GDPR-compliant identity management

### Key Features

- ✅ **Dual Authentication Flows**: Separate customer (frontend) and admin (backend) SSO
- ✅ **Just-in-Time (JIT) Provisioning**: Auto-create users with group/role mapping
- ✅ **OIDC Group Mapping**: Map IdP groups to Magento admin roles and customer groups
- ✅ **Security Enhancements**: CAPTCHA bypass, password verification bypass for OIDC users
- ✅ **Cross-Origin Session Handling**: SameSite=None cookies for IdP redirects
- ✅ **JWT Token Verification**: RS256/384/512 signatures with JWKS caching
- ✅ **Optional OIDC-Only Mode**: Disable password logins entirely
- ✅ **Comprehensive Debug Logging**: Detailed flow logs for troubleshooting
- ✅ **Test Configuration UI**: Verify OIDC claims before production deployment

### Supported Identity Providers

- Authelia
- Keycloak
- Auth0
- Okta
- Azure Active Directory (Azure AD)
- Google Workspace
- Any OIDC-compliant Identity Provider

---

## Requirements

- **PHP**: 8.2 or higher
- **Magento**: 2.4.7 or higher
- **Identity Provider**: OIDC-compliant IdP (OpenID Connect 1.0)
- **HTTPS**: Required for production (SameSite=None cookies require Secure flag)

---

## Installation

### Step 1: Install via Composer

```bash
composer require miniorange_inc/miniorange-oauth-sso
bin/magento module:enable MiniOrange_OAuth
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Step 2: Register Callback URL with Your Identity Provider

In your IdP's OAuth/OIDC client configuration, register this callback URL:

```
https://your-magento-site.com/mooauth/actions/ReadAuthorizationResponse
```

**Important**: Replace `your-magento-site.com` with your actual domain. The protocol **must be HTTPS** in production.

### Step 3: Configure Magento

1. Navigate to **Stores > Configuration > MiniOrange > OAuth/OIDC**

2. Fill in **OAuth Settings**:
   - **App Name**: Identifier for your IdP (e.g., `authelia`, `keycloak`)
   - **Client ID**: OAuth client ID from your IdP
   - **Client Secret**: OAuth client secret from your IdP
   - **Authorize Endpoint**: Authorization endpoint URL (e.g., `https://auth.example.com/api/oidc/authorization`)
   - **Token Endpoint**: Token exchange endpoint URL (e.g., `https://auth.example.com/api/oidc/token`)
   - **User Info Endpoint**: User information endpoint URL (e.g., `https://auth.example.com/api/oidc/userinfo`)
   - **Scope**: OAuth scopes (typically: `openid profile email groups`)

3. **Save Config**

### Step 4: Test Configuration

1. Click **Test Configuration** button in the OAuth Settings page
2. You'll be redirected to your IdP for authentication
3. On successful return, you'll see all OIDC claims received from your IdP
4. Use this to verify claim names for attribute mapping (next step)

### Step 5: Configure Attribute Mapping

1. Navigate to **Stores > Configuration > MiniOrange > OAuth/OIDC > Attribute Mapping**

2. Map OIDC claims to Magento fields:
   - **Email Attribute**: Claim containing user email (default: `email`)
   - **Username Attribute**: Claim containing username (default: `preferred_username`)
   - **First Name Attribute**: Claim containing first name (default: `firstName` or split from `name`)
   - **Last Name Attribute**: Claim containing last name (default: `lastName` or split from `name`)
   - **Group Attribute**: Claim containing group memberships (default: `groups`)

3. **(Optional)** Configure address mappings for customer auto-population:
   - Billing and shipping address fields (city, state, country, address, phone, zip)
   - Date of birth, gender, phone mappings

4. **Save Config**

### Step 6: Configure Sign-In Settings

1. Navigate to **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings**

2. Configure auto-provisioning:
   - **Auto Create Customers**: Enable to automatically create customer accounts on first login
   - **Auto Create Admin Users**: Enable to automatically create admin accounts on first login
   - **Default Customer Group**: Group assigned to new customers if no OIDC group mapping matches
   - **Default Admin Role**: Role assigned to new admins if no OIDC group mapping matches

3. Configure SSO buttons:
   - **Show Customer SSO Link**: Display "Login with SSO" button on customer login page
   - **Show Admin SSO Link**: Display "Login with SSO" button on admin login page

4. **(Optional)** Enable OIDC-only mode:
   - **Disable Non-OIDC Admin Logins**: Force all admins to use OIDC (⚠️ create emergency admin via CLI first)

5. **Enable Debug Logging** (recommended for initial setup):
   - Check **Enable debug logging** to write detailed flow logs to `var/log/mo_oauth.log`

6. **Save Config**

---

## Configuration Guide

### OAuth Settings

| Setting | Description | Example |
|---------|-------------|---------|
| App Name | Identifier for your IdP | `authelia`, `keycloak` |
| Client ID | OAuth client ID from IdP | `magento-store` |
| Client Secret | OAuth client secret (encrypted in database) | `your-secret-here` |
| Authorize Endpoint | IdP authorization URL | `https://auth.example.com/api/oidc/authorization` |
| Token Endpoint | IdP token exchange URL | `https://auth.example.com/api/oidc/token` |
| User Info Endpoint | IdP user information URL | `https://auth.example.com/api/oidc/userinfo` |
| Scope | OAuth scopes (space-separated) | `openid profile email groups` |
| JWKS Endpoint | (Optional) JWT verification keys URL | `https://auth.example.com/api/oidc/jwks` |
| Logout URL | (Optional) IdP logout URL | `https://auth.example.com/api/oidc/logout` |

**Tip**: Most OIDC providers support auto-discovery via `.well-known/openid-configuration`. Check your IdP's documentation.

### Attribute Mapping

Map OIDC claims (from your IdP) to Magento user fields:

#### Core User Attributes

- **Email Attribute**: OIDC claim containing email (required, default: `email`)
- **Username Attribute**: OIDC claim containing username (default: `preferred_username`)
- **First Name Attribute**: OIDC claim for first name (default: `firstName`)
- **Last Name Attribute**: OIDC claim for last name (default: `lastName`)
- **Group Attribute**: OIDC claim containing group memberships (default: `groups`)

#### Customer Profile Attributes (Optional)

- **Date of Birth Attribute**: Claim for DOB, format YYYY-MM-DD (default: `birthdate`)
- **Gender Attribute**: Claim for gender (default: `gender`)
- **Phone Attribute**: Claim for phone number (default: `phone_number`)

#### Customer Address Attributes (Optional)

Map billing and shipping addresses (30+ fields total):

- **Billing Address**: `billing_city`, `billing_state`, `billing_country`, `billing_address`, `billing_phone`, `billing_zip`
- **Shipping Address**: `shipping_city`, `shipping_state`, `shipping_country`, `shipping_address`, `shipping_phone`, `shipping_zip`

**Tip**: Use **Test Configuration** to see actual claim names from your IdP. Claim names are **case-sensitive**.

### Sign-In Settings

#### Auto-Provisioning

- **Auto Create Customers**: Automatically create Magento customer accounts on first OIDC login
- **Auto Create Admin Users**: Automatically create Magento admin accounts on first OIDC login
- **Default Customer Group**: Fallback group if no OIDC group mapping matches
- **Default Admin Role**: Fallback role if no OIDC group mapping matches

#### Group/Role Mapping

**Admin Role Mapping**:
1. Set **Group Attribute Name** to the OIDC claim containing groups (e.g., `groups`, `roles`, `memberOf`)
2. Add mappings: **OIDC Group** → **Magento Admin Role**
   - Example: `Engineering` → `Content Editors`
   - Example: `Finance` → `Sales`
   - Matching is case-insensitive

**Customer Group Mapping**:
- Similar to admin role mapping, maps OIDC groups to Magento customer groups
- Example: `VIP` → `VIP Customer Group` (with special pricing)

#### SSO Button Visibility

- **Show Customer SSO Link**: Display "Login with SSO" button on customer login page
- **Show Admin SSO Link**: Display "Login with SSO" button on admin login page

#### OIDC-Only Mode (Advanced)

- **Disable Non-OIDC Admin Logins**: Force all admins to use OIDC authentication
- ⚠️ **Warning**: Create an emergency admin via CLI before enabling this:
  ```bash
  bin/magento admin:user:create
  ```

#### Debug Logging

- **Enable debug logging**: Writes detailed flow logs to `var/log/mo_oauth.log`
- **Auto-expires**: Logs older than 7 days are automatically deleted
- **Privacy**: Contains user emails and OIDC claims — handle securely

---

## Usage Examples

### Customer Login Flow

1. **Customer clicks "Login with SSO"** on frontend login page
2. **Magento redirects** to IdP authorization endpoint
3. **Customer authenticates** at IdP (username/password, MFA if configured)
4. **IdP redirects back** to Magento callback URL with authorization code
5. **Magento exchanges** authorization code for access token and ID token
6. **Magento extracts** user information from tokens (email, name, groups, etc.)
7. **Magento creates or matches** customer account:
   - If email exists: logs in existing customer
   - If email doesn't exist and auto-create enabled: creates new customer with mapped attributes
8. **Customer session established**, redirected to original page (shopping cart, checkout preserved)

### Admin Login Flow

1. **Admin navigates** to `/admin` and clicks "Login with SSO"
2. **Magento redirects** to IdP (same as customer flow)
3. **Admin authenticates** at IdP
4. **IdP redirects back** to Magento callback URL
5. **Magento exchanges** authorization code for tokens
6. **Magento checks** if email exists in `admin_user` table:
   - If exists: proceeds to admin login
   - If doesn't exist and auto-create enabled: creates admin user with role mapping
7. **Magento calls** native `Auth::login()` with OIDC marker
8. **Security plugins activate**:
   - CAPTCHA automatically bypassed (user already authenticated at IdP)
   - All standard Magento authentication events fire correctly
9. **Admin session established**, redirected to admin dashboard

### Custom SSO Button in Your Theme

Add an SSO button to your custom theme:

**Layout XML** (`app/design/frontend/YourVendor/YourTheme/Magento_Customer/layout/customer_account_login.xml`):

```xml
<referenceBlock name="customer_form_login">
    <arguments>
        <argument name="oauth_helper" xsi:type="object">MiniOrange\OAuth\Helper\OAuthUtility</argument>
    </arguments>
</referenceBlock>
```

**Template** (`.phtml` file):

```php
<?php
/** @var \MiniOrange\OAuth\Helper\OAuthUtility $oauthHelper */
$oauthHelper = $block->getData('oauth_helper');
$customerLoginUrl = $oauthHelper->getSPInitiatedUrl();
?>

<div class="sso-login-button">
    <a href="<?= $escaper->escapeUrl($customerLoginUrl) ?>"
       class="action primary">
        <span><?= $escaper->escapeHtml(__('Login with SSO')) ?></span>
    </a>
</div>
```

---

## Troubleshooting

### Issue 1: "Callback URL mismatch" Error

**Symptom**: After IdP authentication, you see an error about callback URL mismatch.

**Cause**: Callback URL registered in IdP doesn't match the URL Magento is sending.

**Solution**:
1. Verify callback URL in IdP configuration matches exactly:
   ```
   https://your-magento-site.com/mooauth/actions/ReadAuthorizationResponse
   ```
2. Check protocol (HTTP vs HTTPS)—**must use HTTPS in production**
3. Check for trailing slashes (some IdPs are strict about this)
4. If behind a load balancer, verify `X-Forwarded-Proto` header handled correctly

---

### Issue 2: "Attribute not found" / Empty User Profile

**Symptom**: User logs in successfully but profile fields are empty, or you see "attribute not found" errors.

**Cause**: OIDC claim names from IdP don't match your attribute mapping configuration.

**Solution**:
1. **Enable debug logging**: **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings > Enable debug logging**
2. Click **Test Configuration** to see actual claim names from your IdP
3. Check `var/log/mo_oauth.log` for lines like:
   ```
   Received OIDC claims: {"email": "user@example.com", "preferred_username": "user", ...}
   ```
4. Update **Attribute Mapping** to match actual claim names (**case-sensitive**)
5. Common mismatches:
   - `email` vs `Email`
   - `firstName` vs `given_name` vs `name`
   - `groups` vs `roles` vs `memberOf`

---

### Issue 3: Admin Auto-Creation Fails

**Symptom**: OIDC authentication succeeds but admin account not created, error "Admin account not found".

**Cause**: Role mapping failed—no suitable Magento admin role found for user's OIDC groups.

**Solution**:
1. **Check logs**: `var/log/mo_oauth.log` will show:
   ```
   AdminUserCreator: No suitable role found for user. Creation aborted.
   ```
2. Verify **Group Attribute Name** configured correctly (e.g., `groups`, `roles`)
3. Verify user's IdP profile includes group information in that claim
4. Check **Admin Role Mapping**:
   - At least one mapping should match user's groups
   - Or set a **Default Admin Role** as fallback
5. Test with **Test Configuration** to see if groups claim present

**Example configuration**:
- IdP sends: `{"groups": ["Engineering", "Developers"]}`
- Attribute Mapping: **Group Attribute Name** = `groups`
- Role Mapping: `Engineering` → `Content Editors` role

---

### Issue 4: "Session expired" on Callback

**Symptom**: After IdP authentication, you're redirected back to Magento but see "session expired" or login page again.

**Cause**: Cross-origin cookie issues—session cookie not preserved across IdP redirect.

**Solution**:
1. **Verify HTTPS enabled**—SameSite=None cookies require Secure flag (HTTPS only)
2. Check browser console for cookie warnings:
   ```
   Cookie "PHPSESSID" has been rejected because it is in a cross-site context and its "SameSite" is "Lax" or "Strict"
   ```
3. Module automatically sets `SameSite=None; Secure; HttpOnly` on all cookies
4. If behind a load balancer, verify SSL termination configured correctly
5. Test in different browser (some browsers block third-party cookies by default)

---

### Issue 5: Non-OIDC Logins Stopped Working

**Symptom**: Password-based admin logins no longer work, only OIDC login accepted.

**Cause**: "Disable non-OIDC admin logins" setting enabled.

**Solution**:
1. **Check setting**: **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings > Disable Non-OIDC Admin Logins**
2. If enabled and you need password login:
   - Temporarily disable via database:
     ```sql
     UPDATE miniorange_oauth_client_apps SET disableNonOidcAdminLogin = 0;
     ```
   - Or create emergency admin via CLI:
     ```bash
     bin/magento admin:user:create
     ```
3. **Safety net**: If "Show Admin SSO Link" is disabled, password logins automatically allowed (prevents lockout)

---

### Where to Get Help

1. **Check logs**:
   - `var/log/mo_oauth.log` — OIDC flow details (enable debug logging first)
   - `var/log/system.log` — General Magento system logs
   - `var/log/exception.log` — PHP exceptions and errors

2. **Enable debug logging**:
   - **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings > Enable debug logging**
   - Logs auto-expire after 7 days

3. **Test Configuration**:
   - Click **Test Configuration** in OAuth Settings to verify IdP connectivity and claim structure

4. **Review documentation**:
   - [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) — Detailed API reference and architecture
   - [CLAUDE.md](CLAUDE.md) — Developer guidance for code modifications

5. **Contact support**:
   - GitHub Issues: (repository URL)
   - Commercial Support: MiniOrange support portal

---

## Security Considerations

### HTTPS is Required

OAuth/OIDC **requires HTTPS** in production:
- IdP callback URLs must use HTTPS protocol
- SameSite=None cookies (required for cross-origin flows) only work with Secure flag
- Unencrypted HTTP will fail with most IdPs

**Development exception**: Some IdPs allow HTTP for `localhost` testing only.

### Token Storage

- **Access tokens** and **ID tokens** stored in PHP session only, never in database
- **Session lifetime**: Standard Magento session timeout (default: 86400 seconds = 24 hours)
- **Refresh tokens**: Not currently stored (logout requires re-authentication at IdP)

### JWT Verification

Module validates JWT tokens using:
- **Signature verification**: RS256/384/512 algorithms with JWKS keys from IdP
- **Expiration validation**: Rejects expired tokens
- **Issuer validation**: Verifies token issued by configured IdP
- **Audience validation**: Ensures token intended for this Magento instance

### CSRF Protection

OAuth `state` parameter includes session ID to prevent CSRF attacks:
- State format: `encodedRelayState|sessionId|encodedAppName|loginType`
- Session ID validated on callback—rejects requests with mismatched session

### Emergency Access

**Before enabling OIDC-only mode**:
1. Create emergency admin account via CLI:
   ```bash
   bin/magento admin:user:create
   ```
2. Test OIDC login flow thoroughly
3. Document emergency access procedure

**During IdP outage**:
- Emergency admin account can still log in via password (if OIDC-only mode not enabled)
- Or temporarily disable OIDC-only via database query

### IdP Security is Your Security

Module inherits IdP's security policies:
- **MFA**: Configure at IdP level (TOTP, SMS, push, biometric)
- **Conditional Access**: IdP can enforce device trust, location-based access, etc.
- **Audit Logs**: IdP maintains authentication audit trail
- **Access Revocation**: Disable user at IdP—immediately affects all integrated systems

---

## Known Limitations

### Single Provider Per Store

- Module supports **one OIDC provider** per Magento store
- Database limitation: single row in `miniorange_oauth_client_apps` table
- **Workaround**: Separate store views with separate database configurations (requires customization)
- **Future enhancement**: Native multi-provider support planned (see TECHNICAL_DOCUMENTATION.md)

### SP-Initiated Flow Only

- Module supports **SP-initiated** SSO only (user starts at Magento, redirects to IdP)
- **IdP-initiated** flow (user starts at IdP, receives SAML-style POST assertion) not implemented
- **Workaround**: IdP can deep-link to Magento SSO URL, but still technically SP-initiated

### Partial Federated Logout

- Logout redirects to IdP logout URL (`post_logout_url`) but **no back-channel logout**
- Magento session cleared locally, but IdP may not clear other integrated systems' sessions
- **Workaround**: Users must log out at IdP separately for complete session termination
- **Future enhancement**: OpenID Connect RP-Initiated Logout spec implementation planned

### No Built-in Claim Transformation

- Attribute mappings are 1:1 only (OIDC claim → Magento field)
- No conditional logic (e.g., "if group = X, then map attribute Y differently")
- **Workaround**: Configure claim transformations at IdP level before sending to Magento
- Custom logic requires plugin on `CheckAttributeMappingAction::execute()` method

### Global SameSite=None Cookie Enforcement

- Module rewrites **all** cookies globally with `SameSite=None` (required for cross-origin OIDC flows)
- May affect other modules' cookie behavior
- **Future enhancement**: Scope to OIDC routes only (see TECHNICAL_DOCUMENTATION.md)

---

## Advanced Configuration

### Role Mapping Example

**Scenario**: Map IdP group "Engineering" to Magento admin role "Content Editors".

**Steps**:
1. Navigate to **Stores > Configuration > MiniOrange > OAuth/OIDC > Attribute Mapping**
2. Set **Group Attribute Name** to `groups` (or your IdP's group claim name)
3. Click **Add Role Mapping**:
   - **OIDC Group**: `Engineering`
   - **Magento Admin Role**: Select "Content Editors" from dropdown
4. (Optional) Set **Default Admin Role** to "Administrators" as fallback
5. Enable **Auto Create Admin users while SSO**
6. **Save Config**
7. Test login with a user who has "Engineering" group in IdP

**Result**: User automatically created as admin with "Content Editors" role on first login.

### Custom Attribute Mapping for Developers

To map custom OIDC claims to Magento customer attributes:

1. **Add database column** to customer entity (or create EAV attribute)
2. **Modify** `Model/Service/CustomerUserCreator.php` to map the claim
3. **Add UI field** in `view/adminhtml/templates/attrsettings.phtml`
4. Run `bin/magento setup:upgrade && bin/magento setup:di:compile`

See [CLAUDE.md](CLAUDE.md) for detailed developer instructions.

### Login Restriction Mode

**OIDC-Only Mode** forces all users to authenticate via OIDC, blocking password logins entirely.

**Enable**:
1. **Create emergency admin** via CLI first:
   ```bash
   bin/magento admin:user:create
   ```
2. **Stores > Configuration > MiniOrange > OAuth/OIDC > Sign In Settings**
3. Enable **Disable non-OIDC admin logins**
4. **Save Config**

**Warning**: If IdP is unavailable, you'll need the emergency admin or database access to restore password login.

**Safety net**: If "Show Admin SSO Link" is disabled, password logins automatically allowed (prevents accidental lockout).

---

## Contributing & Support

### Documentation

- **Technical Reference**: [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md) — API reference, architecture, implementation details
- **Developer Guide**: [CLAUDE.md](CLAUDE.md) — Developer commands, common modifications, testing checklist

### Support

- **GitHub Issues**: Report bugs or request features (repository URL)

### Version

- **Module Version**: 4.3.0
- **Package**: `miniorange_inc/miniorange-oauth-sso`
- **License**: Proprietary (check `composer.json` for details)
- **Minimum Requirements**: PHP 8.1+, Magento 2.4.7+

---

**For detailed technical documentation and API reference, see [TECHNICAL_DOCUMENTATION.md](TECHNICAL_DOCUMENTATION.md).**
