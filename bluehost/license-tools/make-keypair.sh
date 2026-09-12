#!/usr/bin/env bash
# Create the vendor signing keypair (run ONCE). Keep keys/vendor-private.pem
# secret and offline — anyone with it can mint license keys. Ship only the
# public key (embedded in public/app/License.php).
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p keys
if [ -f keys/vendor-private.pem ]; then
  echo "keys/vendor-private.pem already exists — refusing to overwrite." >&2
  exit 1
fi
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out keys/vendor-private.pem
openssl rsa -in keys/vendor-private.pem -pubout -out keys/vendor-public.pem
echo "Created keys/vendor-private.pem (SECRET) and keys/vendor-public.pem."
echo
echo "Embed this public key into public/app/License.php (const PUBLIC_KEY):"
echo
cat keys/vendor-public.pem
