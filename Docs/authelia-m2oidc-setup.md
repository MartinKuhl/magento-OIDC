# Authelia + Magento 2 OIDC SSO — Complete Setup Guide

This guide walks through the full configuration of Single Sign-On between an **Authelia** identity provider
and a **Magento 2** store using the **M2Oidc_OAuth** plugin. Unlike the [Zitadel guide](zitadel-m2oidc-setup.md),
no custom claims scripting is required — Authelia's user backend natively supports phone number, date of
birth, gender, and a structured address per user, exposed through standard OIDC scopes.

## Prerequisites

- Authelia instance with admin access to `configuration.yml` (this guide uses `https://auth.your-domain.xyz`)
- Authelia's `file` or `ldap` authentication backend already configured
- Magento 2 store with the M2Oidc_OAuth module installed and enabled
- HTTPS on both Authelia and Magento (required for PKCE and `SameSite=None` cookies)
- Magento admin access
- Access to the `authelia` CLI (bundled in the official Docker image) to generate secrets/keys

---

## Part A — Authelia Configuration

### Step 1: Generate the Signing Key and HMAC Secret

Authelia's OIDC provider requires an RSA issuer key (signs tokens) and an `hmac_secret` (signs
authorization codes/PKCE state internally) — generate both once via the `authelia` CLI (drop the
`docker run ... authelia/authelia:latest` prefix on bare-metal installs) and keep them out of version
control (env vars / secret files):

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/keys authelia/authelia:latest \
  authelia crypto certificate rsa generate --common-name auth.your-domain.xyz --directory /keys   # -> private.pem, public.crt

docker run --rm authelia/authelia:latest authelia crypto rand --length 64 --charset alphanumeric   # -> hmac_secret value
```

---

### Step 2: Configure the OIDC Provider

Add an `identity_providers.oidc` block to `configuration.yml`:

```yaml
identity_providers:
  oidc:
    hmac_secret: '<paste the generated HMAC secret>'
    jwks:
      - key_id: 'primary'
        algorithm: 'RS256'
        use: 'sig'
        key: |
          -----BEGIN PRIVATE KEY-----
          ...contents of private.pem...
          -----END PRIVATE KEY-----
        certificate_chain: |
          -----BEGIN CERTIFICATE-----
          ...contents of public.crt...
          -----END CERTIFICATE-----
    clients: []   # populated in Step 3
```

> **Discovery URL:** once Authelia is restarted, its discovery document is available at
> `https://auth.your-domain.xyz/.well-known/openid-configuration`. Authelia's OIDC endpoints
> (relative to its base URL) are `/api/oidc/authorization`, `/api/oidc/token`, `/api/oidc/userinfo`,
> and `/jwks.json` — you won't need to type these manually; Magento's auto-discovery picks them up
> in Part B.

---

### Step 3: Generate Client Credentials and Register the Magento Client

Per Authelia's own guidance on registering OIDC clients
([FAQ](https://www.authelia.com/integration/openid-connect/frequently-asked-questions/)), generate a
random `client_id` and a `client_secret` using the `authelia crypto` CLI rather than hand-picking
values — this avoids weak/guessable identifiers and keeps both values restricted to the RFC3986
"unreserved" character set, which sidesteps encoding issues some relying parties have with special
characters.

```bash
# client_id
docker run --rm authelia/authelia:latest \
  authelia crypto rand --length 72 --charset rfc3986

# client_secret — prints a plaintext value AND a hash; the plaintext goes into Magento,
# the hash goes into configuration.yml (never store the plaintext in configuration.yml)
docker run --rm authelia/authelia:latest \
  authelia crypto hash generate pbkdf2 --variant sha512 --random --random.length 72 --random.charset rfc3986
```

Add the resulting values as a confidential client entry under `identity_providers.oidc.clients`:

```yaml
identity_providers:
  oidc:
    clients:
      - client_id: '<generated client_id>'
        client_name: 'Magento Store'
        client_secret: '$pbkdf2-sha512$...'   # the HASH printed above, not the plaintext
        public: false
        redirect_uris:
          - 'https://YOUR-MAGENTO-DOMAIN/m2oidc/actions/ReadAuthorizationResponse'
        scopes:
          - 'openid'
          - 'profile'
          - 'email'
          - 'phone'
          - 'address'
          - 'groups'
        grant_types:
          - 'authorization_code'
        response_types:
          - 'code'
        token_endpoint_auth_method: 'client_secret_basic'
        revocation_endpoint_auth_method: 'client_secret_post'
        require_pkce: true
        pkce_challenge_method: 'S256'
        authorization_policy: 'two_factor'
        consent_mode: 'implicit'
```

> **Why `revocation_endpoint_auth_method: client_secret_post` (different from the token endpoint's
> `client_secret_basic`)?** The M2Oidc plugin's token exchange authenticates with Basic auth, but its
> RFC 7009 revocation call (`RpInitiatedLogoutService::revokeToken()`, fired on every logout) always
> sends `client_id`/`client_secret` in the POST body, never an `Authorization` header — i.e.
> `client_secret_post` semantics, regardless of what the token endpoint uses. Authelia's default for
> `revocation_endpoint_auth_method` is `client_secret_basic`, same as the token endpoint, so without
> this explicit override Authelia rejects the plugin's revocation requests. Because revocation is
> fire-and-forget and non-fatal, logout still *appears* to work — the access token just never actually
> gets revoked at Authelia, and Magento's logs show the failure. Not applicable to the public-client
> alternative below, since there's no `client_secret` to authenticate revocation with.

> **Keep the plaintext secret** printed by `authelia crypto hash generate pbkdf2` — that's the value
> you paste into Magento's **Client Secret** field in Part B. Authelia only ever stores the hash;
> there's no way to recover the plaintext from `configuration.yml` afterward, so if you lose it you'll
> need to generate a new secret and update both sides.

> **Public client alternative (no secret):** if you'd rather not manage a secret at all, set
> `public: true`, drop `client_secret` entirely, and set `token_endpoint_auth_method: 'none'`. PKCE
> (`pkce_challenge_method: S256`) then carries all the security, matching the Zitadel guide's
> recommended public-client setup. In Magento, check "Public Client", leave Client Secret empty, and
> leave both "Send Credentials" checkboxes unchecked. `client_id` is still worth generating randomly
> via `authelia crypto rand` even for a public client.

> **`consent_mode`:** `implicit` skips the consent screen (good for a first-party store login button).
> Use `explicit` if you want users to approve the scopes on first login, or `pre-configured` to
> remember consent for a configurable duration.

> **Scopes:** `groups`, `phone`, and `address` are **not** in Authelia's default scope list
> (`openid, groups, profile, email`) unless you request/allow them explicitly, as done above — omit
> `address`/`phone` here if you don't plan to sync billing address / phone number.

Restart Authelia after saving `configuration.yml` and confirm the discovery document loads:

```bash
curl -s https://auth.your-domain.xyz/.well-known/openid-configuration | jq .
```

---

### Step 4: Add User Profile, Address, and Group Attributes

Authelia's `file` backend (`users_database.yml`) supports the standard OIDC profile/address fields
directly per user — no metadata store or custom Action script is needed.

```yaml
users:
  jdoe:
    displayname: 'John Doe'
    password: '$argon2id$v=19$m=65536,t=3,p=2$...'
    email: 'jdoe@example.com'
    given_name: 'John'
    family_name: 'Doe'
    gender: 'male'
    birthdate: '1985-04-23'
    phone_number: '+49123456789'
    address:
      street_address: 'Musterstraße 12'
      locality: 'Berlin'
      region: 'Berlin'
      postal_code: '12345'
      country: 'Germany'
    groups:
      - 'admins'
      - 'editors'
```

> **Field reference:**
>
> | User Field                 | Emitted Claim (scope)        | Notes                                            |
> |-----------------------------|-------------------------------|---------------------------------------------------|
> | `given_name` / `family_name`| `given_name` / `family_name` (`profile`) | Sent as separate claims — no name-splitting needed |
> | `gender`                    | `gender` (`profile`)          | Free text — see Part B gender mapping notes        |
> | `birthdate`                 | `birthdate` (`profile`)       | `YYYY-MM-DD`, matches Magento's expected format    |
> | `phone_number`              | `phone_number` (`phone`)      |                                                     |
> | `address.*`                 | single nested `address` object (`address`) | Flattened by Magento into `address.street_address`, `address.locality`, `address.region`, `address.postal_code`, `address.country` — see Part B |
> | `groups`                    | flat `groups` array (`groups`)| Already flat — no Zitadel-style nested-role reconstruction needed |

> **LDAP backend:** if you use `authentication_backend.ldap` instead of `file`, map these same
> attributes from your directory schema (e.g. `givenName`, `sn`, `telephoneNumber`, `postalAddress`,
> `memberOf`) via the LDAP backend's attribute configuration — the claims Authelia emits afterward are
> identical to the file-backend case above.

> **Claims are returned via UserInfo, not the ID token, by default.** Authelia only puts identity
> claims on the UserInfo endpoint unless you configure otherwise. This is fine as-is: the M2Oidc
> plugin reads claims from the UserInfo response, so no extra configuration is needed here.

---

## Part B — Magento M2Oidc Plugin Configuration

### Step 1: Create a New Provider

1. Log into the Magento admin panel
2. Navigate to **M2 OIDC → Manage Providers**
3. Click **Add New Provider**

#### Provider Settings tab

| Field          | Value                                      |
|----------------|--------------------------------------------|
| App Name       | `authelia`                                 |
| Display Name   | `Authelia` (shown in admin UI)             |
| Login Type     | `both` (or `customer` / `admin` as needed) |
| Is Active      | Yes                                        |
| Button Label   | `Login with Authelia`                      |
| Button Color   | A 6-digit hex color, e.g. `#3a3a3a`        |
| Sort Order     | `0`                                        |

---

### Step 2: Configure OAuth / OIDC Settings

Open the **OAuth Settings** tab.

| Field                 | Value                                                                                      |
|-----------------------|--------------------------------------------------------------------------------------------|
| **Discovery URL**     | `https://auth.your-domain.xyz/.well-known/openid-configuration`                             |
| **Client ID**         | the `client_id` generated and registered in Part A Step 3                                  |
| **Client Secret**     | the **plaintext** secret printed by `authelia crypto hash generate pbkdf2` in Part A Step 3 |
| **Public Client**     | ⬜ Leave unchecked                                                                           |
| **Scope**             | `openid profile email phone address groups`                                                |
| **Grant Type**        | Authorization Code                                                                          |
| **Send Credentials**  | ✅ Check "In Authorization Header" (matches `token_endpoint_auth_method: client_secret_basic`) |
| **PKCE Method**       | S256                                                                                         |

> **Using the public-client alternative instead?** Leave Client Secret empty, check "Public Client",
> and leave both "Send Credentials" checkboxes unchecked — see the callout in Part A Step 3.

> **Auto-Discovery:** saving with only the Discovery URL filled in makes the plugin fetch Authelia's
> discovery document and auto-populate the Authorize/Token/UserInfo/JWKS endpoint fields. Authelia
> does **not** advertise an `end_session_endpoint` (see logout note below), so that one field stays
> blank after auto-discovery and must be set manually in Step 5.

Click **Save**, then reopen the provider and confirm the Authorize/Token/UserInfo/JWKS endpoints were
populated.

---

### Step 3: Test the Connection

1. Reopen the provider in **M2 OIDC → Manage Providers**
2. Click **Test OIDC Flow**
3. Authenticate against Authelia in the popup
4. Confirm the claims listed include: `email`, `given_name`, `family_name`, `preferred_username`,
   `gender`, `birthdate`, `phone_number`, `address.street_address`, `address.locality`,
   `address.region`, `address.postal_code`, `address.country`, `groups`

> If `phone_number`/`address.*`/`groups` are missing, double check the client's `scopes` list in
> `configuration.yml` (Part A Step 3) includes `phone`, `address`, and `groups`, and that the test
> user actually has those fields set in `users_database.yml`.

---

### Step 4: Configure Attribute Mapping

Open the **Attribute Mapping** tab.

#### Claim Value Encoding

Set to `None` — Authelia does not base64-encode claim values.

#### Core claims

| Plugin Field      | OIDC Claim Name       |
|-------------------|-----------------------|
| Email Claim       | `email`               |
| Username Claim    | `preferred_username`  |
| First Name Claim  | `given_name`          |
| Last Name Claim   | `family_name`         |

#### Extended claims

| Plugin Field                | OIDC Claim Name          | Notes                                                          |
|-------------------------------|---------------------------|-----------------------------------------------------------------|
| Phone Number Claim            | `phone_number`             |                                                                   |
| Date of Birth Claim           | `birthdate`                | `YYYY-MM-DD`                                                     |
| Gender Claim                  | `gender`                   | Accepted values: `male`/`m`/`1`/`mann`/`männlich` → Male; `female`/`f`/`2`/`frau`/`weiblich` → Female; all others → Not Specified |
| Street *(Billing Address)*    | `address.street_address`   | Required for billing address creation                           |
| ZIP / Postal Code *(Billing Address)* | `address.postal_code` | Required for billing address creation                           |
| City *(Billing Address)*      | `address.locality`         | Required for billing address creation                           |
| Country *(Billing Address)*   | `address.country`          | Required for billing address creation. Plain English names (e.g. `Germany`) work — Magento's country resolver matches them against ISO codes automatically |
| State / Region                | `address.region`           | Unlike Zitadel, Authelia's `address` object has a real region field — safe to map this here |

> **Billing address rule:** the plugin only creates the billing address when Street, ZIP, City, and
> Country are all mapped **and** all four values are non-empty in the token.

> **Group / Role Claim:** set to `groups` — Authelia's `groups` scope already emits a flat array, so
> no extra normalization or claim-splitting is required.

Click **Save**.

---

### Step 5: Configure Login Options

Open the **Login Options** tab. Use the same recommended values as the Zitadel guide (SSO buttons on,
auto-create accounts on, auto-redirect off until verified, profile/address sync on).

#### Logout: setting the End Session Endpoint

Authelia does not implement RP-Initiated Logout, OIDC Session Management, or Front-/Back-Channel
Logout — there is no standards-based `end_session_endpoint` to point at. Instead, set:

```
End Session Endpoint: https://auth.your-domain.xyz/logout
```

on the provider's **OAuth Settings** tab (this is Authelia's own portal logout page, not an OIDC
endpoint). The M2Oidc plugin detects this shape automatically — any `endsession_endpoint` whose path
ends in `/logout` and contains neither `/oauth2/` nor `/oidc/` is treated as Authelia-style
forward-auth logout. When that happens, the plugin sends `?rd=<url>` instead of the standard
`id_token_hint` / `state` / `post_logout_redirect_uri` parameters, where `<url>` is the static admin
or customer base URL (not the dynamic `/m2oidc/actions/postlogout` callback, since Authelia has
nothing to register that callback against). Access-token revocation (RFC 7009) still happens
normally beforehand if a revocation endpoint is configured.

> **No Post-Logout Redirect URI needs registering with Authelia** — unlike Zitadel/Keycloak/Entra,
> Authelia has no such concept, so there is nothing to add on the Authelia side for logout beyond
> the `/logout` endpoint above.

Click **Save**.

---

## End-to-End Test Checklist

### Customer login
- [ ] A **"Login with Authelia"** button appears on the frontend login page
- [ ] Clicking it redirects to `https://auth.your-domain.xyz`
- [ ] After authenticating, you are redirected back to the Magento store
- [ ] A customer account is created (if new user) or the existing account is logged in
- [ ] Name and email are correctly populated
- [ ] Phone, date of birth, gender, and billing address (including region) are populated

### Admin login
- [ ] A **"Login with Authelia"** button appears on the `/admin` login page
- [ ] After authenticating, you land on the Magento admin dashboard

### Logout
- [ ] Clicking **Sign Out** redirects to `https://auth.your-domain.xyz/logout?rd=...`
- [ ] After Authelia logout, you are redirected back to the Magento login page
- [ ] The SSO button is visible again but auto-redirect does **not** trigger immediately

### Custom fields
- [ ] Test Configuration shows all expected claims, including `address.*` and `groups`
- [ ] Customer profile shows phone, DOB, gender after login
- [ ] Customer's default billing address (including state/region) is correct in Magento admin

---

## Troubleshooting

### Custom claims (`phone_number`, `address.*`, `groups`) not appearing

**Checks:**
1. Confirm `phone`, `address`, and `groups` are all listed under the client's `scopes` in
   `configuration.yml` (Part A Step 3) — they are **not** in Authelia's default scope list
2. Confirm the test user has `phone_number` / `address` / `groups` set in `users_database.yml`
   (or the equivalent LDAP attributes)
3. Confirm Magento's OAuth Settings **Scope** field includes `phone address groups`, not just
   `openid profile email`

---

### Billing address not created after login

Same four-field gate as the Zitadel guide, but the claim names differ: verify
`address.street_address`, `address.postal_code`, `address.locality`, and `address.country` are all
present in the Test Configuration output and all mapped in the Attribute Mapping tab. Check
`var/log/M2Oidc.log` for `CustomerAttributeMapper: Skipping address creation — required field missing`.

---

### Gender shows "Not Specified" after login

Same accepted-values rule as the Zitadel guide. Store one of `male`/`m`/`1`/`mann`/`männlich` or
`female`/`f`/`2`/`frau`/`weiblich` in the user's `gender` field in `users_database.yml`.

---

### Logout doesn't land back on Magento / `rd` parameter ignored

**Cause:** the provider's End Session Endpoint isn't set to Authelia's bare `/logout` portal path, so
the module's Authelia-detection heuristic (`path ends in /logout`, no `/oauth2/` or `/oidc/` segment)
doesn't match, and it falls back to sending standard OIDC logout parameters that Authelia ignores.

**Fix:** set End Session Endpoint to exactly `https://auth.your-domain.xyz/logout` (no `/api/oidc/...`
path). Do not point it at the discovery document's token/userinfo endpoints.

---

### `invalid_client` / client rejected during token exchange

**Cause:** mismatch between the Authelia client's `client_secret`/`token_endpoint_auth_method` and
what Magento is sending, or a `public`/"Public Client" mismatch between the two sides.

**Checks:**
1. Confirm you pasted the **plaintext** secret (printed once by
   `authelia crypto hash generate pbkdf2`) into Magento's Client Secret field — not the
   `$pbkdf2-sha512$...` hash, which only ever belongs in `configuration.yml`
2. Confirm `configuration.yml` has `token_endpoint_auth_method: client_secret_basic` and Magento has
   "Send Credentials → In Authorization Header" checked, with "Public Client" unchecked
3. If using the public-client alternative instead, confirm `public: true` +
   `token_endpoint_auth_method: none` in `configuration.yml` match Magento's "Public Client" checked,
   with no secret entered and no "Send Credentials" checkboxes ticked

---

### Callback URL mismatch error

**Fix:**
1. Confirm the `redirect_uris` entry in `configuration.yml` is exactly:
   ```
   https://YOUR-MAGENTO-DOMAIN/m2oidc/actions/ReadAuthorizationResponse
   ```
2. Check for trailing slashes or HTTP vs HTTPS mismatches
3. If Magento is behind a load balancer, verify `X-Forwarded-Proto: https` is set

---

### Country not resolving to the correct Magento country

Magento's `CountryResolver` already handles this: OIDC providers like Authelia send plain English
country names (e.g. `Germany`) regardless of store locale, and the resolver matches them against
Magento's active country list via ICU locale data. You do not need to store ISO codes
(e.g. `DE`) in `users_database.yml` — the full English name works out of the box.

---

## Where to Find Logs

| Log file                    | Contents                                      |
|-----------------------------|-----------------------------------------------|
| `var/log/M2Oidc.log`        | Full OIDC flow details (enable debug logging) |
| `var/log/system.log`        | General Magento system messages               |
| `var/log/exception.log`     | PHP exceptions                                |

Enable debug logging in **Stores → Configuration → M2Oidc → OAuth/OIDC → Sign In Settings → Enable debug logging**.
Logs auto-rotate and are deleted after 7 days.
