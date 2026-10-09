#!/usr/bin/env bash
# A7: does bref/extra-php-extensions publish a layers.json, and what does it say for gd-php-85?
# Read-only public HTTP lookups (GitHub raw + Packagist); no credentials.
set -euo pipefail
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
base=https://raw.githubusercontent.com/brefphp/extra-php-extensions/HEAD
curl -fsS "$base/layers.json" -o "$tmp/layers.json"
curl -fsS "$base/.gitattributes" -o "$tmp/gitattributes"
curl -fsS https://repo.packagist.org/p2/bref/extra-php-extensions.json -o "$tmp/packagist.json"
python3 -I - "$tmp" <<'PY'
import json, sys
tmp = sys.argv[1]
layers = json.load(open(f"{tmp}/layers.json"))
gd = layers["gd-php-85"]
print("layers.json: present on default branch, %d layer names" % len(layers))
print("gd-php-85 regions:", len(gd), "| eu-west-1 version:", gd["eu-west-1"], "| distinct versions:", sorted(set(gd.values())))
print("shape: {layer-name: {region: version}}")
pk = json.load(open(f"{tmp}/packagist.json"))["packages"]["bref/extra-php-extensions"]
print("packagist latest:", pk[0]["version"], "reference", pk[0]["dist"]["reference"])
PY
if grep -q 'layers.json' "$tmp/gitattributes"; then
  echo "layers.json is export-ignored (NOT in the composer dist)"
else
  echo "layers.json is not export-ignored (it IS in the composer dist zip)"
fi
echo "LOOKUP_OK"
