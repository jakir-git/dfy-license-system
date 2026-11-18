#!/usr/bin/env bash
# Builds dist/dfy-license-hub-<version>.zip and dist/dfy-license-manager-<version>.zip.
# Copies the shared classes into both plugins and includes hub-config.php (kept out of Git).
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -f dfy-license-manager/hub-config.php ]; then
  echo "Missing dfy-license-manager/hub-config.php — copy hub-config.example.php and set your hub address." >&2
  exit 1
fi
for p in dfy-license-hub dfy-license-manager; do
  cp shared/*.php "$p/includes/"
done
mkdir -p dist
hv=$(sed -n "s/.*define( 'DFY_LH_VERSION', '\([^']*\)'.*/\1/p" dfy-license-hub/dfy-license-hub.php)
mv=$(sed -n "s/.*define( 'DFY_LM_VERSION', '\([^']*\)'.*/\1/p" dfy-license-manager/dfy-license-manager.php)
rm -f "dist/dfy-license-hub-$hv.zip" "dist/dfy-license-manager-$mv.zip"
zip -qr "dist/dfy-license-hub-$hv.zip" dfy-license-hub -x '*.DS_Store'
zip -qr "dist/dfy-license-manager-$mv.zip" dfy-license-manager -x '*.DS_Store' -x 'dfy-license-manager/hub-config.example.php'
echo "Built dist/dfy-license-hub-$hv.zip and dist/dfy-license-manager-$mv.zip"
