#!/usr/bin/env bash
# Usage: scripts/check-bundle-csp.sh <dist-dir>
# Fails when the built front end could not run under a strict Content-Security-Policy (script-src 'self';
# style-src 'self'; img-src 'self'; see plan phase-4-frontend C16 and ADR 0008). Checks:
#   <dist>/index.html     no inline <script> (one without src), no <style> element, no style= attribute, no on*=
#                         event-handler attribute, no javascript: and no data: URL
#   every .js/.mjs file   no `new Function` and no call to eval(
#   every .css file       no url(data:...)
#   index.html also       no src/href/action to another origin (http:, https:, //): no third-party resource
# Word boundaries keep `.evaluate(`, `retrieval(` and `$eval(` from matching. Every broken rule is reported, with the
# file name, then the script exits 1.
#
# Exit: 0 compatible, 1 a rule is broken, 2 error (bad usage, no directory, no index.html or no script: nothing
# was built).
set -euo pipefail
export LC_ALL=C

if [ "$#" -ne 1 ]; then
  echo "usage: $0 <dist-dir>" >&2
  exit 2
fi
dir="$1"
if [ ! -d "$dir" ]; then
  echo "check-bundle-csp: not a directory: $dir (build first)" >&2
  exit 2
fi
index="$dir/index.html"
if [ ! -f "$index" ]; then
  echo "check-bundle-csp: $index not found; nothing was built" >&2
  exit 2
fi

problems=0
report() { # <file> <rule>
  echo "check-bundle-csp: $1: $2" >&2
  problems=$((problems + 1))
}

# index.html on one line (tags may span lines), lower-cased: HTML tag and attribute names are case-insensitive.
html="$(tr '\n\r\t' '   ' <"$index" | tr 'A-Z' 'a-z')"
# The same with every quoted attribute value emptied, so text inside a value (a meta content, say) is not an attribute.
bare="$(sed -e 's/"[^"]*"/""/g' -e "s/'[^']*'/''/g" <<<"$html")"
# Values of the attributes that load or navigate; the URL rules look only at these. Captured with `|| true` and tested
# with [ -n ], never with `grep -q` in a pipe: under pipefail an early-exiting grep can fail the whole pipeline.
urls="$(grep -oE '[[:space:]"'"'"'/](src|href|action|formaction|poster|srcset)[[:space:]]*=[[:space:]]*("[^"]*"|'"'"'[^'"'"']*'"'"'|[^[:space:]>]*)' <<<"$html" || true)"

# An inline script is a <script ...> start tag with no src attribute.
tags="$(grep -oE '<script([^>]*)>' <<<"$html" || true)"
inline="$(grep -vE '[[:space:]]src[[:space:]]*=' <<<"$tags" || true)"
if [ -n "$inline" ]; then
  report "$index" 'inline <script> (a script without src); CSP script-src is self'
fi
if grep -qE '<style[[:space:]>]' <<<"$bare"; then
  report "$index" '<style> element; CSP style-src is self'
fi
if grep -qE '[[:space:]"'"'"'/]style[[:space:]]*=' <<<"$bare"; then
  report "$index" 'style= attribute; CSP style-src is self'
fi
if grep -qE '[[:space:]"'"'"'/]on[a-z]+[[:space:]]*=' <<<"$bare"; then
  report "$index" 'on*= event-handler attribute; CSP script-src is self'
fi
if grep -qE '=[[:space:]]*["'"'"']?[[:space:]]*javascript:' <<<"$urls"; then
  report "$index" 'javascript: URL; CSP script-src is self'
fi
if grep -qE '=[[:space:]]*["'"'"']?[[:space:]]*data:' <<<"$urls"; then
  report "$index" 'data: URL; CSP img-src is self (set build.assetsInlineLimit to 0)'
fi
if grep -qE '=[[:space:]]*["'"'"']?[[:space:]]*(https?:|//)' <<<"$urls"; then
  report "$index" 'third-party resource (src or href to another origin); CSP is self'
fi

# Scripts and styles anywhere under the directory (nested folders, .mjs). No script at all means nothing was built.
scripts_found=0
while IFS= read -r file; do
  case "$file" in
    *.css)
      if grep -qiE 'url\([[:space:]]*["'"'"']?[[:space:]]*data:' "$file"; then
        report "$file" 'data: URL in CSS; CSP img-src is self'
      fi
      ;;
    *)
      scripts_found=1
      if grep -qE '(^|[^A-Za-z0-9_$])eval[[:space:]]*\(' "$file"; then
        report "$file" 'call to eval( ; CSP forbids unsafe-eval'
      fi
      if grep -qE '(^|[^A-Za-z0-9_$])new[[:space:]]+Function([^A-Za-z0-9_$]|$)' "$file"; then
        report "$file" 'new Function ; CSP forbids unsafe-eval'
      fi
      ;;
  esac
done < <(find "$dir" -type f \( -name '*.js' -o -name '*.mjs' -o -name '*.css' \) | sort)
if [ "$scripts_found" -eq 0 ]; then
  echo "check-bundle-csp: no .js or .mjs file under $dir; nothing was built" >&2
  exit 2
fi

if [ "$problems" -ne 0 ]; then
  echo "check-bundle-csp: $problems problem(s) in $dir" >&2
  exit 1
fi
echo "check-bundle-csp: $dir is compatible with a strict CSP"
