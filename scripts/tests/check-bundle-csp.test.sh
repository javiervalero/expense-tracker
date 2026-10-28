#!/usr/bin/env bash
# Tests for scripts/check-bundle-csp.sh. Run: bash scripts/tests/check-bundle-csp.test.sh
# Exit codes asserted: 0 = compatible with a strict CSP, 1 = a rule is broken, 2 = error (bad usage, no directory).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-bundle-csp.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
status=0
output=''

run_guard() {
  status=0
  output="$("$GUARD" "$@" 2>&1)" || status=$?
}

expect() { # <expected status> <label>
  if [ "$status" -ne "$1" ]; then
    failures=$((failures + 1))
    printf 'FAIL %s: expected exit %s, got %s\n%s\n' "$2" "$1" "$status" "$output"
  fi
}

expect_output_has() { # <text> <label>
  if ! grep -qF -- "$1" <<<"$output"; then
    failures=$((failures + 1))
    printf 'FAIL %s: output lacks "%s": %s\n' "$2" "$1" "$output"
  fi
}

CLEAN_HTML='<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" href="/favicon.ico" />
    <script type="module" crossorigin src="/assets/index-abc.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/index-abc.css">
  </head>
  <body><div id="app"></div></body>
</html>'

fresh_dist() { # [index.html content] [app.js content]
  rm -rf "$WORK/dist"
  mkdir -p "$WORK/dist/assets"
  printf '%s\n' "${1:-$CLEAN_HTML}" >"$WORK/dist/index.html"
  printf '%s\n' "${2:-const a=1;export{a};}" >"$WORK/dist/assets/index-abc.js"
}

# check_html <label> <html> <rule>: index.html with one forbidden construct; the output names the file and the rule.
check_html() {
  fresh_dist "$2"
  run_guard "$WORK/dist"
  expect 1 "$1"
  expect_output_has 'index.html' "$1 (names the file)"
  expect_output_has "$3" "$1 (names the rule)"
}

# check_js <label> <js> <rule>
check_js() {
  fresh_dist "$CLEAN_HTML" "$2"
  run_guard "$WORK/dist"
  expect 1 "$1"
  expect_output_has 'index-abc.js' "$1 (names the file)"
  expect_output_has "$3" "$1 (names the rule)"
}

fresh_dist
run_guard "$WORK/dist"
expect 0 'a clean bundle'

# index.html
check_html 'an inline script' '<html><head><script>alert(1)</script></head></html>' 'inline <script>'
check_html 'an inline module script' '<html><head><script type="module">import "./a.js"</script></head></html>' 'inline <script>'
check_html 'an inline script over several lines' '<html><head><script
  type="module">
  boot()
</script></head></html>' 'inline <script>'
check_html 'an inline script after an external one' '<html><head><script src="/a.js"></script><script>x()</script></head></html>' 'inline <script>'
check_html 'an inline script in capitals' '<html><head><SCRIPT>x()</SCRIPT></head></html>' 'inline <script>'
check_html 'a style element' '<html><head><style>body{color:red}</style></head></html>' '<style>'
check_html 'a style attribute' '<html><body><div style="color:red"></div></body></html>' 'style='
check_html 'an onclick attribute' '<html><body><a onclick="go()">x</a></body></html>' 'on*='
check_html 'an onload attribute with quotes and spaces' "<html><body onload = 'go()'></body></html>" 'on*='
check_html 'a javascript: URL' '<html><body><a href="javascript:void(0)">x</a></body></html>' 'javascript:'
check_html 'a data: URL' '<html><head><link rel="icon" href="data:image/png;base64,AAAA"></head></html>' 'data:'

# False positives in index.html: external script, and words that only look like the rules.
fresh_dist '<html><head><meta name="description" content="a one-page application, stylesheet and contentful"><script src="/a.js" defer></script><title>Metadata: javascript is fine as a word</title></head><body>Update your data</body></html>'
run_guard "$WORK/dist"
expect 0 'an external script, "content=", plain words'

# Assets
check_js 'new Function' 'const f=new Function("return 1");' 'new Function'
check_js 'new  Function with extra spaces' 'const f=new  Function("a","return a");' 'new Function'
check_js 'eval(' 'eval("1+1");' 'eval('
check_js 'eval ( with a space' 'const r=eval ("1");' 'eval('
check_js 'window.eval(' 'window.eval("1");' 'eval('
check_js 'eval after a semicolon' 'a();eval(b);' 'eval('

fresh_dist "$CLEAN_HTML" 'x.evaluate(1);retrieval(2);const medieval=(3);function myeval(){};$eval(4);_eval(5);new FunctionName();'
run_guard "$WORK/dist"
expect 0 'evaluate(, retrieval(, medieval(, myeval(, $eval(, _eval(, new FunctionName'

# Only dist/assets/*.js is searched for eval: a source map may quote it.
fresh_dist
printf '{"sourcesContent":["eval(1)"]}\n' >"$WORK/dist/assets/index-abc.js.map"
run_guard "$WORK/dist"
expect 0 'a source map is not a script'

# Several failures are all reported.
fresh_dist '<html><body style="x" onclick="y()"></body></html>' 'eval(1)'
run_guard "$WORK/dist"
expect 1 'several problems'
expect_output_has 'style=' 'several problems (style)'
expect_output_has 'on*=' 'several problems (on*)'
expect_output_has 'eval(' 'several problems (eval)'

# No script at all: nothing was built, whatever index.html says.
fresh_dist
rm -rf "$WORK/dist/assets"
run_guard "$WORK/dist"
expect 2 'a dist without any .js or .mjs file'

# Scripts in nested folders and .mjs files are searched too.
fresh_dist
mkdir -p "$WORK/dist/assets/chunks"
printf 'eval(1)\n' >"$WORK/dist/assets/chunks/lazy.js"
run_guard "$WORK/dist"
expect 1 'eval( in a nested folder'
expect_output_has 'lazy.js' 'eval( in a nested folder (names the file)'
fresh_dist
printf 'const f=new Function("a")\n' >"$WORK/dist/assets/worker.mjs"
run_guard "$WORK/dist"
expect 1 'new Function in an .mjs file'
expect_output_has 'worker.mjs' 'new Function in an .mjs file (names the file)'

# CSS: url(data:...) breaks img-src 'self'; a same-origin url() is fine.
fresh_dist
printf '.a{background:url(data:image/png;base64,AAAA)}\n' >"$WORK/dist/assets/index-abc.css"
run_guard "$WORK/dist"
expect 1 'url(data:) in CSS'
expect_output_has 'index-abc.css' 'url(data:) in CSS (names the file)'
expect_output_has 'data: URL in CSS' 'url(data:) in CSS (names the rule)'
printf ".a{background:url( 'data:image/svg+xml,%%3Csvg%%3E' )}\n" >"$WORK/dist/assets/index-abc.css"
run_guard "$WORK/dist"
expect 1 'url( "data:) with quotes and spaces in CSS'
printf '.a{background:url(/assets/x.png)}.b{background:url("/assets/y.svg")}\n' >"$WORK/dist/assets/index-abc.css"
run_guard "$WORK/dist"
expect 0 'same-origin url() in CSS'

# Attribute values are not attributes.
fresh_dist '<html><head><meta name="x" content="a onfoo=1 style=x metadata:abc"></head></html>'
run_guard "$WORK/dist"
expect 0 'style=, on*= and data: inside an attribute value'
check_html 'an img with a data: src' '<html><body><img src="data:image/png;base64,AA"></body></html>' 'data:'
check_html 'a javascript: href' '<html><body><a href="javascript:x">x</a></body></html>' 'javascript:'

# A slash separates attributes too.
check_html 'an on*= attribute after a slash' '<html><body><img/onerror=x()></body></html>' 'on*='
check_html 'a style attribute after a slash' '<html><body><p/style=color:red>x</p></body></html>' 'style='

# C16: no third-party resource.
check_html 'an https script' '<html><head><script src="https://cdn.example.com/a.js"></script></head></html>' 'third-party resource'
check_html 'an http stylesheet' "<html><head><link rel=stylesheet href='http://example.com/a.css'></head></html>" 'third-party resource'
check_html 'a protocol-relative src' '<html><body><img src="//example.com/a.png"></body></html>' 'third-party resource'
fresh_dist '<html><head><script type="module" src="/assets/x.js"></script><link href="/assets/x.css" rel="stylesheet"><meta name="x" content="https://example.com"></head></html>'
run_guard "$WORK/dist"
expect 0 'same-origin src and href, a URL in a meta content'

# Errors.
run_guard
expect 2 'no argument'
run_guard "$WORK/dist" extra
expect 2 'two arguments'
run_guard "$WORK/missing"
expect 2 'a missing directory'
mkdir -p "$WORK/empty"
run_guard "$WORK/empty"
expect 2 'a directory without index.html (nothing was built)'

if [ "$failures" -ne 0 ]; then
  echo "check-bundle-csp.test.sh: $failures failure(s)"
  exit 1
fi
echo "check-bundle-csp.test.sh: all checks passed"
