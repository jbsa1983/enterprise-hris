#!/usr/bin/env bash
# Generate an OFFLINE signed license key for a customer installation.
#
# Usage:
#   ./make-license.sh --domain hris.exssi.com --customer "GEEK Group"
#   ./make-license.sh --domain acme.com --customer "ACME Inc" --edition business --expires 2027-09-12
#   ./make-license.sh --domain acme.com --customer "ACME Inc" --max-users 75
#
# Editions auto-set the employee cap (override with --max-users):
#   starter    -> 50 active employees
#   business   -> 100
#   enterprise -> 300
#   standard   -> unlimited (0)
#
# Prints one license key. Give it to the customer; they paste it in
# Administration -> License. The key only validates on the --domain you set,
# stops working after --expires (omit for perpetual), and caps active employees.
set -euo pipefail
cd "$(dirname "$0")"
KEY="keys/vendor-private.pem"

DOMAIN=""; CUSTOMER=""; EDITION="standard"; EXPIRES=""; MAXUSERS=""
while [ $# -gt 0 ]; do
  case "$1" in
    --domain)    DOMAIN="${2:-}"; shift 2;;
    --customer)  CUSTOMER="${2:-}"; shift 2;;
    --edition)   EDITION="${2:-}"; shift 2;;
    --expires)   EXPIRES="${2:-}"; shift 2;;
    --max-users) MAXUSERS="${2:-}"; shift 2;;
    *) echo "Unknown argument: $1" >&2; exit 1;;
  esac
done

[ -n "$DOMAIN" ]   || { echo "Error: --domain is required"   >&2; exit 1; }
[ -n "$CUSTOMER" ] || { echo "Error: --customer is required" >&2; exit 1; }
[ -f "$KEY" ]      || { echo "Error: missing $KEY — run ./make-keypair.sh first" >&2; exit 1; }

# Cap defaults from the edition unless --max-users was given (0 = unlimited).
if [ -z "$MAXUSERS" ]; then
  case "$(printf '%s' "$EDITION" | tr 'A-Z' 'a-z')" in
    starter)    MAXUSERS=50;;
    business)   MAXUSERS=100;;
    enterprise) MAXUSERS=300;;
    *)          MAXUSERS=0;;
  esac
fi
case "$MAXUSERS" in ''|*[!0-9]*) echo "Error: --max-users must be a whole number" >&2; exit 1;; esac

# Normalize the domain the same way the app does: lowercase, no protocol, no www, no path.
DOMAIN="$(printf '%s' "$DOMAIN" | tr 'A-Z' 'a-z' | sed -e 's#^https\{0,1\}://##' -e 's#^www\.##' -e 's#/.*$##')"

if [ -z "$EXPIRES" ]; then EXP_JSON="null"; else EXP_JSON="\"$EXPIRES\""; fi
ISSUED="$(date +%Y-%m-%d)"
PAYLOAD="{\"customer\":\"$CUSTOMER\",\"domain\":\"$DOMAIN\",\"edition\":\"$EDITION\",\"max\":$MAXUSERS,\"issued\":\"$ISSUED\",\"expires\":$EXP_JSON}"

b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
P="$(printf '%s' "$PAYLOAD" | b64url)"
S="$(printf '%s' "$P" | openssl dgst -sha256 -sign "$KEY" -binary | b64url)"

echo "GEEKHRIS.$P.$S"
