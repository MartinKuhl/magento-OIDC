# Zitadel + Magento 2 OIDC SSO — Complete Setup Guide

This guide walks through the full configuration of Single Sign-On between a **Zitadel** identity provider
and a **Magento 2** store using the **M2Oidc_OAuth** plugin. It covers both the standard OIDC setup
and the configuration of custom user metadata fields (phone, gender, date of birth, billing address)
that Zitadel does not include in tokens by default.

## Prerequisites

- Zitadel instance with admin access (this guide uses `https://zitadel.casa-kuhl.de`)
- Magento 2 store with the M2Oidc_OAuth module installed and enabled
- HTTPS on both Zitadel and Magento (required for PKCE and SameSite=None cookies)
- Magento admin access

---

## Part A — Zitadel Configuration

### Step 1: Create a Project and Application

1. Log into your Zitadel admin console
2. Navigate to **Organization** → **Projects**
3. Create a new project or select an existing one (e.g., `Magento`)
4. Inside the project, click **+ New Application**
5. Choose **Web Application**
6. Set the authentication method to **PKCE**

   > PKCE (Proof Key for Code Exchange) is the recommended method for web applications.
   > It does not require a client secret, making it more secure for server-rendered apps.

   > **What you will see in the app overview after saving:**
   > The application overview shows **Authentifizierungsmethode: None** — this is correct and
   > expected. In Zitadel, selecting "PKCE" during the wizard sets the token-endpoint
   > authentication method to **None**, meaning the token endpoint does not expect a
   > `client_secret`. PKCE (`code_verifier`/`code_challenge`) provides the security instead.
   > This makes the app a **public client** (RFC 6749 §2.1). The PKCE banner visible in the
   > app overview confirms this.

7. On the **Redirect URIs** screen, add:

   ```
   https://YOUR-MAGENTO-DOMAIN/m2oidc/actions/ReadAuthorizationResponse
   ```

8. On the **Post Logout Redirect URIs** screen, add:

   ```
   https://YOUR-MAGENTO-DOMAIN/m2oidc/actions/postlogout
   ```

9. Complete the wizard and note down the **Client ID** shown on the application overview screen.

   > You do not need a Client Secret when using PKCE.

#### Optional: Enable UserInfo inside ID Token

In the application settings, find the **Token Settings** section and enable
**User Info inside ID Token**. This embeds the userinfo claims directly into the ID token,
which can improve performance and reliability.

---

### Step 2: Add Custom User Metadata Fields

Zitadel's standard OIDC token only includes basic profile claims (`email`, `given_name`,
`family_name`, `preferred_username`). Fields like phone number, date of birth, gender,
and postal address are **not included by default**.

The solution is to store this data as **User Metadata** in Zitadel (a flexible key-value store
attached to each user account) and then use a **Zitadel Action** to inject those values as
custom OIDC claims at token creation time.

#### Metadata Key Reference

Store the following keys on each user. These key names match the claim names the Action script
(Step 3) will emit, which in turn match the attribute mapping configured in Magento (Part B).

| Metadata Key     | Example Value    | Description                                     |
|------------------|------------------|-------------------------------------------------|
| `phone`          | `+49123456789`   | Phone number (E.164 format recommended)         |
| `gender`         | `male`           | `male`, `female`, or `other`                    |
| `birthdate`      | `1985-04-23`     | Date of birth in ISO 8601 format (YYYY-MM-DD)   |
| `address_street` | `Musterstraße 12`| Street address including house number           |
| `address_zip`    | `12345`          | Postal / ZIP code                               |
| `address_city`   | `Berlin`         | City                                            |
| `address_country`| `DE`             | Country as ISO 3166-1 alpha-2 code (e.g., `DE`) |

#### Setting Metadata via the Zitadel Console (per user)

1. Go to **Users** in your organization
2. Click on a user to open their profile
3. Select the **Metadata** tab
4. Click **+** (Add Metadata)
5. Enter the key (e.g., `phone`) and value (e.g., `+49123456789`)
6. Click **Save**
7. Repeat for each metadata field

#### Setting Metadata via the Zitadel User Service API v2 (bulk / automated)

Use the User Service v2 endpoint to set metadata programmatically. The v2 API accepts an array,
so you can set all fields for a user in a single request:

```
POST /v2/users/{userId}/metadata
Content-Type: application/json
Authorization: Bearer <token>

{
  "metadata": [
    { "key": "phone",           "value": "<base64-encoded-value>" },
    { "key": "gender",          "value": "<base64-encoded-value>" },
    { "key": "birthdate",       "value": "<base64-encoded-value>" },
    { "key": "address_street",  "value": "<base64-encoded-value>" },
    { "key": "address_zip",     "value": "<base64-encoded-value>" },
    { "key": "address_city",    "value": "<base64-encoded-value>" },
    { "key": "address_country", "value": "<base64-encoded-value>" }
  ]
}
```

> **Important:** The API requires metadata values to be **base64-encoded**.
> When you set metadata via the **Console UI**, Zitadel handles encoding automatically — you
> just type the plain text value. Existing entries with matching keys are overwritten; entries
> with no matching key are left untouched.

Example using curl (all fields in one call):

```bash
# Helper to base64-encode a plain text value
b64() { echo -n "$1" | base64; }

curl -X POST "https://zitadel.casa-kuhl.deg/v2/users/USER_ID/metadata" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"metadata\": [
      { \"key\": \"phone\",           \"value\": \"$(b64 '+49123456789')\" },
      { \"key\": \"gender\",          \"value\": \"$(b64 'male')\" },
      { \"key\": \"birthdate\",       \"value\": \"$(b64 '1985-04-23')\" },
      { \"key\": \"address_street\",  \"value\": \"$(b64 'Musterstraße 12')\" },
      { \"key\": \"address_zip\",     \"value\": \"$(b64 '12345')\" },
      { \"key\": \"address_city\",    \"value\": \"$(b64 'Berlin')\" },
      { \"key\": \"address_country\", \"value\": \"$(b64 'DE')\" }
    ]
  }"
```

> **API v1 vs v2:** The older Management API used `POST /management/v1/users/{userId}/metadata`
> with a single `{ "key": "...", "value": "..." }` body. The current User Service API v2 uses
> `POST /v2/users/{userId}/metadata` with a `{ "metadata": [...] }` array body. Use the v2
> endpoint on current Zitadel instances.

---

### Step 3: Create a Zitadel Action to Inject Metadata into Tokens

A **Zitadel Action** is a JavaScript snippet that runs at a specific point in the authentication flow.
You will create one that reads the user metadata keys from Step 2 and adds them as flat OIDC claims
to the token.

#### 3.1 Create the Action

1. In the Zitadel console, go to **Organization** → **Actions**
2. Click **New**
3. Fill in:
   - **Name:** `addCustomClaims`
     > The name must exactly match the JavaScript function name in the script.
   - **Script:** paste the code below
   - **Timeout:** `10` (seconds)
   - **Allowed to Fail:** enable this toggle
     > Enabling "Allowed to Fail" means that if the script throws an error, the login still
     > succeeds — the custom claims are simply omitted. This prevents a script bug from
     > locking all users out.
4. Click **Save**

**Action script:**

```javascript
function addCustomClaims(ctx, api) {
  var meta = ctx.v1.user.getMetadata();

  // Phone number
  if (meta.phone) {
    api.v1.claims.setClaim('phone_number', meta.phone);
  }

  // Gender: the M2Oidc plugin accepts: male/m/1/mann/männlich → Male
  //                                     female/f/2/frau/weiblich → Female
  //                                     anything else → Not Specified
  if (meta.gender) {
    api.v1.claims.setClaim('gender', meta.gender);
  }

  // Date of birth (YYYY-MM-DD)
  if (meta.birthdate) {
    api.v1.claims.setClaim('birthdate', meta.birthdate);
  }

  // Billing address fields
  // Note: ALL four address fields (street, zip, city, country) must be present
  // for the M2Oidc plugin to create the billing address in Magento.
  if (meta.address_street) {
    api.v1.claims.setClaim('address_street', meta.address_street);
  }
  if (meta.address_zip) {
    api.v1.claims.setClaim('address_zip', meta.address_zip);
  }
  if (meta.address_city) {
    api.v1.claims.setClaim('address_city', meta.address_city);
  }
  if (meta.address_country) {
    api.v1.claims.setClaim('address_country', meta.address_country);
  }
}
```

> **Note on base64:** When metadata is read via `ctx.v1.user.getMetadata()` inside an Action,
> Zitadel automatically decodes the base64 values back to plain text. You do **not** need to
> decode them in your script.

> **Note on `ctx.v1` in the script:** The `v1` here is the **Actions JavaScript runtime namespace**
> — it is *not* the REST API version. This object path (`ctx.v1.user.getMetadata()`,
> `api.v1.claims.setClaim()`) is identical on all current Zitadel instances regardless of whether
> you use the Management API v1 or User Service API v2 for other operations. The script above
> is correct and does not need to change.

#### 3.2 Link the Action to the Complement Token Flow

Creating the Action alone does not execute it. You must attach it to a trigger:

1. In **Organization** → **Actions**, click on the **Flows** tab
2. Select **Complement Token** as the flow type
3. Click on the **Pre Userinfo Creation** trigger
4. Click **+ Add Action** and select `addCustomClaims`
5. Save

> **Why "Pre Userinfo Creation"?** This trigger fires before the userinfo endpoint response and
> the ID token are assembled, ensuring your custom claims appear in both the userinfo response
> and the ID token. This is the trigger the M2Oidc plugin relies on when exchanging the
> authorization code.

> **Optional:** Also add the action to the **Pre Access Token Creation** trigger if you want the
> custom claims present in access tokens as well (useful for API access scenarios).

#### 3.3 Alternative: Actions v2 (Webhook-Based)

Zitadel also supports **Actions v2**, which replaces embedded JavaScript with external HTTP
webhook targets. Instead of a script in the console, your own HTTP service receives a call from
Zitadel at token creation time and returns the extra claims.

**When to prefer Actions v2:**
- You want the claim logic version-controlled and deployed via CI/CD
- The logic needs to call external databases or APIs
- You prefer not to manage JavaScript inline in the Zitadel console

**When to stick with Actions v1 (JS):**
- You want zero extra infrastructure — no external service to host
- The claim data comes purely from user metadata already stored in Zitadel
- Simpler setup and easier debugging for this use case

**High-level steps for Actions v2:**

1. **Build and host an HTTP endpoint** that Zitadel will call. It receives a JSON payload with
   user context and must respond with the claims to add:
   ```json
   { "metadata": { "phone_number": "+49123456789", "gender": "male", ... } }
   ```
   *(Exact request/response schema: see Zitadel's Actions v2 documentation)*

2. In the Zitadel console, go to **Actions** → **Targets** → **New Target**
   - Enter your endpoint URL, timeout, and signing secret

3. Go to **Actions** → **Executions** → select **Complement Token** → **Pre Userinfo Creation**
   - Add your target to this execution

The embedded JavaScript approach (Step 3.1 / 3.2 above) is recommended for this specific use
case since it requires no extra hosting.

---

#### 3.4 Alternative: Expose Metadata via Scope (without an Action)

If you prefer not to use Actions, you can request the special scope
`urn:zitadel:iam:user:metadata` in your OAuth request. Zitadel will then include all metadata
under a nested key `urn:zitadel:iam:user:metadata` in the userinfo response.

The downside is that the claim is a nested object with the metadata keys inside, which makes
mapping harder in the plugin. The Action approach above produces flat, named claims that map
directly.

---

### Step 4: Optional — Configure Role/Group Mapping in Zitadel

Zitadel sends project role assignments in the claim `urn:zitadel:iam:org:project:roles`, but this
is a nested object (not a flat string array), which makes direct group mapping in the M2Oidc plugin
difficult. The plugin's automatic nested-role normalization (which reconstructs group names from
that nested shape) only matters if you map that raw claim directly — the recommended approach below
never produces a nested object in the first place, so it doesn't rely on that normalization.

The recommended approach for group/role mapping is to store group membership as a metadata field
and emit it as a flat claim via the Action:

1. Add a metadata key `groups` to users with a comma-separated list of group names, e.g.:
   ```
   groups = admins,editors
   ```
2. Add the following to your `addCustomClaims` Action script:
   ```javascript
   if (meta.groups) {
     // Split into an array so the plugin can match individual group names
     api.v1.claims.setClaim('groups', meta.groups.split(','));
   }
   ```
3. In the Magento plugin, set the **Group / Role Claim** to `groups` (see Part B, Step 4).

---

## Part B — Magento M2Oidc Plugin Configuration

### Step 1: Create a New Provider

1. Log into the Magento admin panel
2. Navigate to **M2 OIDC → Manage Providers** in the left sidebar
3. Click **Add New Provider**

#### Provider Settings tab

| Field          | Value                                      |
|----------------|--------------------------------------------|
| App Name       | `zitadel`                                  |
| Display Name   | `Zitadel` (shown in admin UI)              |
| Login Type     | `both` (or `customer` / `admin` as needed) |
| Is Active      | Yes                                        |
| Button Label   | `Login with Zitadel`                       |
| Button Color   | A 6-digit hex color via the color picker, e.g. `#2073c4` |
| Sort Order     | `0`                                        |

---

### Step 2: Configure OAuth / OIDC Settings

Open the **OAuth Settings** tab.

| Field                 | Value                                                                                      |
|-----------------------|--------------------------------------------------------------------------------------------|
| **Discovery URL** *(stored as `well_known_config_url`)* | `https://zitadel.casa-kuhl.de/.well-known/openid-configuration` |
| **Client ID**         | *(copy from Zitadel application overview)*                                                 |
| **Client Secret**     | *(leave empty — this is a public client; see "Public Client" below)*                       |
| **Public Client**     | ✅ Enable (check the "Public Client" checkbox)                                             |
| **Scope**             | `openid profile email phone` — see note below on the two Zitadel-specific scopes           |
| **Grant Type**        | Authorization Code *(the only option in the dropdown; the stored value has no effect on the actual token request, which always uses `authorization_code` — PKCE is the field that actually matters, see "PKCE Method" below)* |
| **Send Credentials**  | *(leave both "In Authorization Header" and "In Request Body" checkboxes unchecked — public client sends no secret)* |
| **PKCE Method**       | S256 (recommended)                                                                         |

> **Why "Public Client"?** Zitadel with Authentication Method **None** is a public client
> (RFC 6749 §2.1) — it does not issue a `client_secret`. Enabling this checkbox tells the
> module not to require or send a secret during token exchange. Without it, the module will
> show a validation error when you try to save without a secret.

> **Confidential client settings (if you changed Authentifizierungsmethode to Basic or POST):**
> Leave "Public Client" **unchecked**, enter the Client Secret copied from Zitadel, keep
> PKCE Method = S256, and under "Send Credentials" check **"In Authorization Header"** (for
> Basic) or **"In Request Body"** (for POST). Leave both credential checkboxes unchecked only
> for public clients.

> **About the two Zitadel-specific scopes:** `urn:zitadel:iam:user:metadata` and
> `urn:zitadel:iam:org:project:roles` are **not required** for the Action-based approach this
> guide recommends (Part A, Step 3) — the Action reads user metadata server-side via
> `ctx.v1.user.getMetadata()`, independent of whatever scopes the client requests. Only add
> `urn:zitadel:iam:user:metadata` if you use the no-Action alternative in
> [Step 3.4](#34-alternative-expose-metadata-via-scope-without-an-action), or
> `urn:zitadel:iam:org:project:roles` if you map Zitadel's raw project-role claim directly
> instead of the metadata-based `groups` field from Part A Step 4.

> **Auto-Discovery:** When you save with only the Discovery URL filled in, the plugin
> automatically fetches Zitadel's discovery document and populates all endpoint URLs
> (Authorize, Token, UserInfo, JWKS, End Session) for you. You do not need to enter them
> manually.

Click **Save**.

After saving, reopen the provider and verify that the **Authorize Endpoint**, **Token Endpoint**,
**User Info Endpoint**, **JWKS Endpoint**, and **End Session Endpoint** fields have been
auto-populated with your Zitadel instance's URLs.

---

### Step 3: Test the Connection

1. Reopen the provider in **M2 OIDC → Manage Providers**
2. Click the **Test OIDC Flow** button in the top button bar (next to "Save")
3. A **popup window** opens and redirects to Zitadel — authenticate there
4. After successful authentication the popup closes and the plugin displays all OIDC
   claims received from Zitadel

> **Popup blocked?** If nothing happens after clicking the button, your browser is blocking
> the popup. Look for a blocked-popup indicator in the address bar, allow popups from the
> Magento admin URL, and try again.

**What to look for:**

- Standard claims should appear: `email`, `given_name`, `family_name`, `preferred_username`, `sub`
- If you completed Part A Step 3 and the metadata keys are set on your test user, the custom
  claims should also appear: `phone_number`, `gender`, `birthdate`, `address_street`,
  `address_zip`, `address_city`, `address_country`

> If the custom claims are missing, verify:
> 1. The Action was saved and linked to **Complement Token → Pre Userinfo Creation**
> 2. The test user has the metadata keys set (Part A Step 2)
> 3. The Action has "Allowed to Fail" enabled — check Zitadel logs for script errors

---

### Step 4: Configure Attribute Mapping

Open the **Attribute Mapping** tab of the provider.

#### Claim Value Encoding

Leave **Claim Value Encoding** set to `None` for the Action-based setup in this guide — the
Zitadel Action decodes metadata to plain text before calling `setClaim()`, so the claims Magento
receives are already plain text. Only set this to `Base64` if you instead use the
[Step 3.4 alternative](#34-alternative-expose-metadata-via-scope-without-an-action) (metadata
exposed directly via scope, unprocessed by an Action) — see
[Troubleshooting: Metadata values arrive as base64 strings](#metadata-values-arrive-as-base64-strings).

#### Core claims (standard Zitadel)

| Plugin Field      | OIDC Claim Name       |
|-------------------|-----------------------|
| Email Claim       | `email`               |
| Username Claim    | `preferred_username`  |
| First Name Claim  | `given_name`          |
| Last Name Claim   | `family_name`         |

#### Extended claims (from Zitadel Action + metadata)

| Plugin Field               | OIDC Claim Name   | Notes                                                                                       |
|-----------------------------|-------------------|---------------------------------------------------------------------------------------------|
| Phone Number Claim          | `phone_number`    |                                                                                             |
| Date of Birth Claim         | `birthdate`       | Plugin expects YYYY-MM-DD format                                                            |
| Gender Claim                | `gender`          | Accepted values: `male`/`m`/`1`/`mann`/`männlich` → Male; `female`/`f`/`2`/`frau`/`weiblich` → Female; all others → Not Specified |
| Street *(Billing Address)*  | `address_street`  | Required for billing address creation                                                       |
| ZIP / Postal Code *(Billing Address)* | `address_zip` | Required for billing address creation                                                  |
| City *(Billing Address)*    | `address_city`    | Required for billing address creation                                                       |
| Country *(Billing Address)* | `address_country` | Required for billing address creation. Use ISO 3166-1 alpha-2 codes (e.g. `DE`, `US`)      |

> **Billing address rule:** The plugin only creates the billing address object when **all four**
> fields — Street, ZIP / Postal Code, City, and Country — are mapped **and** all four values are
> non-empty in the token. If any one is missing, no address is created (this prevents partial /
> invalid addresses). A separate **State / Region** field also exists on this tab; it is not part
> of the four-field billing address gate and is not currently used when creating the billing
> address, so it's fine to leave it unmapped for this setup.

> **Group / Role Claim** (only needed if using role/group mapping): set to `groups` if you
> configured the group emission in Part A Step 4.

Click **Save**.

---

### Step 5: Configure Login Options

Open the **Login Options** tab.

| Setting                                                        | Recommended Value                                |
|-------------------------------------------------------------------|--------------------------------------------------|
| Show SSO button on customer login page                          | Yes                                              |
| Show SSO button on admin login page                             | Yes                                              |
| Auto-create customer accounts on first SSO login                 | Yes (users are created on first login)           |
| Auto-create admin users on first SSO login                       | Optional (only if admins log in via Zitadel)     |
| Auto redirect to IdP on customer login page                     | No (keep off until flow is verified)             |
| Auto redirect to IdP on admin login page                        | No (keep off until flow is verified)             |
| Disable non-OIDC login for customers (OIDC login only)           | No (enable only after verifying OIDC works)      |
| Disable non-OIDC login for admins (OIDC login only)              | No (enable only after verifying OIDC works)      |
| Sync customer profile fields (name, email) on every SSO login    | Yes — so name/email edits made in Zitadel propagate on the customer's next login |
| Sync customer address on every SSO login                        | Yes — so billing address metadata edits made in Zitadel propagate on the customer's next login |

> **Post-Logout Landing Page** (`post_logout_url`) also lives on this tab — leave it blank unless
> you want to override the module's unified `/m2oidc/actions/postlogout` redirect for this
> provider specifically.

> **Debug Logging is not on this tab.** It's a global setting at **Stores > Configuration >
> M2Oidc > OAuth/OIDC > Sign In Settings > Enable debug logging**, applying to all providers.
> Enable it during initial setup; logs are written to `var/log/M2Oidc.log` and automatically
> rotated daily. This is the first place to look when troubleshooting.

Click **Save**.

---

## End-to-End Test Checklist

Run through these tests after completing both parts of the configuration.

### Customer login
- [ ] A **"Login with Zitadel"** button appears on the frontend login page
- [ ] Clicking the button redirects to `https://zitadel.casa-kuhl.de`
- [ ] After authenticating, you are redirected back to the Magento store
- [ ] A customer account is created (if new user) or the existing account is logged in
- [ ] Customer name and email are correctly populated
- [ ] If metadata is set: phone, date of birth, gender, and billing address are populated

### Admin login
- [ ] A **"Login with Zitadel"** button appears on the `/admin` login page
- [ ] After authenticating, you land on the Magento admin dashboard

### Logout
- [ ] Clicking **Sign Out** redirects to Zitadel for logout
- [ ] After Zitadel logout, you are redirected back to the Magento login page
- [ ] The SSO button is visible again but auto-redirect does **not** trigger immediately
  (the logout guard cookie prevents a redirect loop)

### Custom fields
- [ ] Open the Test Configuration result and verify all expected claims appear
- [ ] Log in as a customer with metadata set; check their account profile for phone, DOB, gender
- [ ] Check the customer's default billing address in Magento admin

---

## Troubleshooting

### Custom claims not appearing in Test Configuration

**Symptoms:** Only standard claims visible; `phone_number`, `gender`, etc. are missing.

**Checks:**
1. Confirm the Zitadel Action `addCustomClaims` exists and is saved
2. Go to **Flows → Complement Token** and confirm the action is listed under
   **Pre Userinfo Creation**
3. Verify the test user has the metadata keys set (Organization → Users → user → Metadata tab)
4. Enable "Allowed to Fail" on the Action, then check Zitadel's action logs for script errors

---

### Billing address not created after login

**Symptoms:** Customer logs in but no billing address is saved.

**Cause:** One or more of the four required fields (`address_street`, `address_zip`,
`address_city`, `address_country`) is missing from the token or not mapped in the plugin.

**Fix:**
1. Run **Test Configuration** and verify all four address claims are present in the output
2. Check **Attribute Mapping** — all four billing address fields must be filled
3. Confirm the metadata keys are set on the user in Zitadel
4. Enable debug logging and check `var/log/M2Oidc.log` for lines like
   `CustomerAttributeMapper: Skipping address creation — required field missing`

---

### Gender shows "Not Specified" after login

**Symptoms:** Gender is mapped but the customer's gender shows as "Not Specified" (3).

**Cause:** The claim value does not match any of the accepted strings.

**Accepted values:**
- → **Male (1):** `male`, `m`, `1`, `mann`, `männlich`
- → **Female (2):** `female`, `f`, `2`, `frau`, `weiblich`
- → **Not Specified (3):** anything else

**Fix:** Store one of the accepted values in the `gender` metadata key in Zitadel
(e.g., `male` or `female`).

---

### `post_logout_redirect_uri invalid` error on logout

**Symptoms:** After clicking Sign Out, Zitadel shows:
```json
{"error": "invalid_request", "error_description": "post_logout_redirect_uri invalid"}
```

**Cause:** The Post Logout Redirect URI registered in Zitadel does not match exactly what the
plugin sends. Zitadel performs strict string matching — a trailing slash difference is enough to
cause this error.

**Fix:** In the Zitadel application settings, register exactly:
```
https://YOUR-MAGENTO-DOMAIN/m2oidc/actions/postlogout
```
No trailing slash. If you previously added the URI with a trailing slash
(`/m2oidc/actions/postlogout/`), remove it and keep only the version without.

---

### Callback URL mismatch error

**Symptoms:** Zitadel shows an error like "redirect_uri mismatch" after login.

**Fix:**
1. Open the Zitadel application settings and confirm the Redirect URI is exactly:
   ```
   https://YOUR-MAGENTO-DOMAIN/m2oidc/actions/ReadAuthorizationResponse
   ```
2. Check for trailing slashes or HTTP vs HTTPS mismatch
3. If Magento is behind a load balancer, verify `X-Forwarded-Proto: https` is set

---

### Metadata values arrive as base64 strings

**Symptoms:** The Test Configuration shows claim values like `KzQ5MTIzNDU2Nzg5` instead of
`+49123456789`.

**Cause:** Metadata was set via the API with base64 encoding, and the Action script is not
decoding it, or the values were double-encoded.

**Fix:** Metadata read via `ctx.v1.user.getMetadata()` inside an Action is automatically decoded
by Zitadel. If you see base64 strings, the values were likely set already base64-encoded via
the Console UI. Clear the metadata value and re-enter it as plain text in the Console.

---

### Session expired / redirected back to login after callback

**Symptoms:** After authenticating at Zitadel, Magento shows the login page again.

**Cause:** Cross-origin session cookie dropped by the browser.

**Fix:**
1. Verify Magento is running on **HTTPS** (required for `SameSite=None; Secure` cookies)
2. Check the browser console for cookie rejection warnings
3. Ensure there are no mixed-content issues on the Magento frontend

---

### Non-OIDC password login stopped working for admin

**Symptoms:** Password login returns an error; only SSO button works.

**Cause:** "Disable non-OIDC admin logins" was enabled.

**Fix (temporary, via database):**
```sql
UPDATE m2oidc_oauth_client_apps SET m2oidc_disable_non_oidc_admin_login = 0;
```
Then flush the Magento cache:
```bash
bin/magento cache:flush
```

---

## Where to Find Logs

| Log file                    | Contents                                      |
|-----------------------------|-----------------------------------------------|
| `var/log/M2Oidc.log`        | Full OIDC flow details (enable debug logging) |
| `var/log/system.log`        | General Magento system messages               |
| `var/log/exception.log`     | PHP exceptions                                |

Enable debug logging in **Stores → Configuration → M2Oidc → OAuth/OIDC → Sign In Settings → Enable debug logging**.
Logs auto-rotate and are deleted after 7 days.
