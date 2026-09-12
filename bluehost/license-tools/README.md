# GEEK HRIS — License tools (vendor only)

Offline, domain-locked license keys. Your **private key** signs them; the app
verifies them with the **public key** baked into `public/app/License.php`.
No license server needed. These tools use `openssl` (already on macOS/Linux).

**Never ship the `keys/` folder or the private key to a customer.**

## One-time setup
The keypair already exists in `keys/`. If you ever need a fresh one:

```bash
./make-keypair.sh
```

Then copy the printed public key into `public/app/License.php` (`const PUBLIC_KEY`)
and rebuild the package. (Changing keys invalidates every previously issued key.)

## Generate a license for a customer

```bash
./make-license.sh --domain their-subdomain.com --customer "Their Company" --expires 2027-09-12
```

- `--domain`   the exact domain their HRIS runs on (no `https://`, no `www.`)
- `--customer` shown in their License screen
- `--edition`  optional label (default `standard`)
- `--expires`  optional `YYYY-MM-DD`; omit for a perpetual license

It prints one key like `GEEKHRIS.xxxxx.yyyyy`. Send it to the customer; they paste
it in **Administration → License → Activate**. The key only works on that domain
and stops after the expiry date.

## Turn enforcement on for sold copies
In `public/app/License.php`, set `const ENFORCE = true;` before packaging a copy
you sell. With enforcement on, the app is locked (except sign-in + the License
screen) until a valid key is activated. Leave it `false` for your own instance.

## How it works / limits
- Keys are signed with RSA-2048 + SHA-256; the app checks the signature, the
  domain, and the expiry — all offline.
- Because the PHP source ships to the customer, a technical user could remove the
  check. This is a licensing + deterrent mechanism, not unbreakable DRM. For
  tamper-resistance, encode the PHP (ionCube/SourceGuardian) as well.
