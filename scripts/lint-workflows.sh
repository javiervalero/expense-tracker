#!/usr/bin/env bash
# Usage: scripts/lint-workflows.sh [root]     (`make lint-workflows`)
# Audits GitHub Actions workflows and composite actions with zizmor, in the digest-pinned image (ZIZMOR_IMAGE, defined
# in the Makefile; read from there when the variable is not in the environment; an unpinned value is refused).
# Configuration: .github/zizmor.yml (hash-pinning of every `uses:`, pinned `container:` and `services.*.image` images).
# The pedantic persona is on: `unpinned-images` only exists there. This replaces the workflow part of
# scripts/check-image-pins.sh.
#
# The pin audits cannot be switched off from inside the repository: before zizmor runs, this script fails when
#   - .github/zizmor.yml configures `unpinned-uses` or `unpinned-images` in any way other than the expected blocks
#     (so no `disable: true`, no `ignore:` list, no weaker policy, no second or quoted entry), or uses YAML anchors,
#     aliases, merge keys or backslash escapes (which could build such an entry without spelling it out);
#   - any file under <root>/.github, or any action.yml / action.yaml audited, has a `zizmor: ignore[...unpinned-...]`
#     comment.
# (zizmor 1.30.1's own default is already `"*": hash-pin`; the config states it so that a future default cannot
# silently weaken it.)
# Every local `uses: ./X` / `uses: $/X` in the audited files must point at an action (or reusable workflow) that is
# itself audited; one in spikes/, the test fixtures, outside the repository or missing is refused (exit 1, after zizmor
# has run). Docker actions' images and Dockerfiles are checked by scripts/check-image-pins.sh.
#
# What is audited: every workflow and action below <root>/.github, and any action.yml / action.yaml elsewhere in <root>
# (dependency directories, spikes/ and the test fixtures are skipped when <root> is the repository).
# Network: offline by default (`--offline`, and `--network none` for the container), whatever the environment holds.
# ZIZMOR_ONLINE=1 turns on the online audits (impostor-commit, known-vulnerable-actions, ...) and then needs GH_TOKEN,
# which is passed to the container by NAME (`-e GH_TOKEN`), never as a value in the argument list. CI sets both in the
# step's `env:` (GH_TOKEN from the run's read-only GITHUB_TOKEN). Without ZIZMOR_ONLINE=1 the token is never forwarded.
# The container gets a read-only mount of the repository, a read-only root file system, no capabilities and
# no-new-privileges, and runs as the host user.
# Exit: 0 clean, 1 findings (or a config / comment that weakens the pin audits), 2 bad usage, nothing to audit, or the
# tool could not run.
set -euo pipefail
export LC_ALL=C

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="$REPO/.github/zizmor.yml"
PIN_RE='^[^@[:space:]]+@sha256:[0-9a-f]{64}$'

die() {
  echo "lint-workflows: $1" >&2
  exit 2
}

case "${1:-}" in -*) die "unexpected option '$1'; the only argument is a directory (prefix it with ./ if it starts with -)" ;; esac
ROOT="${1:-$REPO}"
[ -d "$ROOT" ] || die "not a directory: $ROOT"
ROOT="$(cd "$ROOT" && pwd -P)"
[ -f "$CONFIG" ] || die "missing configuration: $CONFIG"

IMAGE="${ZIZMOR_IMAGE:-$(make -s --no-print-directory -C "$REPO" print-ZIZMOR_IMAGE)}"
[[ "$IMAGE" =~ $PIN_RE ]] || die "the zizmor image '$IMAGE' is not pinned by digest (ZIZMOR_IMAGE must be name@sha256:<64 hex>)"
YQ="${YQ_IMAGE:-$(make -s --no-print-directory -C "$REPO" print-YQ_IMAGE)}"
[[ "$YQ" =~ $PIN_RE ]] || die "the yq image '$YQ' is not pinned by digest (YQ_IMAGE must be name@sha256:<64 hex>)"

case "${ZIZMOR_ONLINE:-0}" in
  0) online=0 ;;
  1)
    online=1
    [ -n "${GH_TOKEN:-}" ] || die 'ZIZMOR_ONLINE=1 needs GH_TOKEN in the environment (a read-only token for the online audits)'
    ;;
  *) die "ZIZMOR_ONLINE must be 0 or 1, not '${ZIZMOR_ONLINE}'" ;;
esac

inputs=()
action_files=()
if [ -d "$ROOT/.github" ]; then
  inputs+=(.github)
fi
# Actions outside .github/ (zizmor finds the ones inside by itself).
prune=(-path "$ROOT/.git" -o -path "$ROOT/.github" -o -path "$ROOT/.terraform" -o -path "$ROOT/build"
  -o -path "$ROOT/frontend/node_modules" -o -path "$ROOT/contract/node_modules" -o -path "$ROOT/backend/vendor"
  -o -path "$ROOT/docker/local-aws/vendor")
if [ "$ROOT" = "$REPO" ]; then
  prune+=(-o -path "$ROOT/spikes" -o -path "$ROOT/scripts/tests/fixtures")
fi
while IFS= read -r -d '' f; do
  inputs+=("./${f#"$ROOT"/}")
  action_files+=("$f")
done < <(find "$ROOT" \( "${prune[@]}" \) -prune -o -type f \( -iname action.yml -o -iname action.yaml \) -print0 | sort -z)

workflows="$(find "$ROOT/.github/workflows" -maxdepth 1 -type f \( -name '*.yml' -o -name '*.yaml' \) 2>/dev/null | head -n 1 || true)"
if [ -z "$workflows" ] && [ "${#inputs[@]}" -le 1 ] && [ ! -d "$ROOT/.github/actions" ]; then
  die "no workflow or action to audit in $ROOT"
fi

# ---- The pin audits cannot be weakened from inside the repository ------------------------------------------------
weakened=0
refuse() {
  echo "lint-workflows: $1" >&2
  weakened=1
}

# The configuration without comments and blank lines (trailing blanks removed), one line per entry.
config_lines() {
  sed -e 's/^[[:space:]]*#.*$//' -e 's/[[:space:]]#.*$//' -e 's/[[:space:]]*$//' "$CONFIG" | grep -v '^$' || true
}

# config_block <key line>: the lines indented below it (its block), joined by newlines.
config_block() {
  printf '%s\n' "${lines[@]}" | awk -v key="$1" '
    found && /^(  [^ ]|[^ ])/ { exit }
    found { print }
    $0 == key { found = 1 }'
}

check_config() {
  local -a lines
  local line uses_expected images_expected
  mapfile -t lines < <(config_lines)
  uses_expected=$'    config:\n      policies:\n        "*": hash-pin'
  images_expected='    disable: false'
  for line in "${lines[@]}"; do
    case "$line" in
      *'\'* | *'<<'* | *[[:space:][,{:]'&'[A-Za-z0-9_]* | *[[:space:][,{:]'*'[A-Za-z0-9_]*)
        refuse ".github/zizmor.yml: '$line' uses a backslash escape, an anchor, an alias or a merge key; write the configuration out in plain block form"
        ;;
    esac
    case "$line" in
      *unpinned*)
        [ "$line" = '  unpinned-uses:' ] || [ "$line" = '  unpinned-images:' ] ||
          refuse ".github/zizmor.yml: '$line' mentions an unpinned-* audit outside its expected entry (rules: unpinned-uses: / unpinned-images:, plain keys, each once)"
        ;;
    esac
  done
  check_rule unpinned-uses "$uses_expected"
  check_rule unpinned-images "$images_expected"
}

check_rule() { # <rule> <expected block>: absent is fine (zizmor's defaults are strict); present means exactly this
  local count block
  count="$(printf '%s\n' "${lines[@]}" | grep -cxF "  $1:" || true)"
  [ "$count" -gt 0 ] || return 0
  block="$(config_block "  $1:")"
  if [ "$count" -ne 1 ] || [ "$block" != "$2" ]; then
    refuse ".github/zizmor.yml: the $1 rule must be exactly (no disable: true, no ignore:, no other policy):
  $1:
$2"
  fi
}

check_ignore_comments() {
  local hits
  local -a scan=(${action_files[@]+"${action_files[@]}"})
  [ ! -d "$ROOT/.github" ] || scan+=("$ROOT/.github")
  [ "${#scan[@]}" -gt 0 ] || return 0
  hits="$(grep -rnIiE 'zizmor:[[:space:]]*ignore\[[^]]*unpinned-' "${scan[@]}" || true)"
  if [ -n "$hits" ]; then
    refuse "an inline 'zizmor: ignore[...]' comment switches off a pin audit; pin the reference (commit SHA or sha256 digest) instead:
$hits"
  fi
}

check_config
check_ignore_comments
if [ "$weakened" -ne 0 ]; then
  echo 'lint-workflows: the pin audits must not be disabled or ignored (see above); zizmor was not run' >&2
  exit 1
fi

# ---- Local actions (uses: ./X or $/X) must be ones this audit covers ---------------------------------------------
# zizmor audits the files it is given; it does not follow a local `uses:`. So every local reference in the audited
# workflows and actions must resolve, inside <root>, to an action.yml / action.yaml that IS audited (under .github/, or
# found above outside the skipped paths), or to a workflow file in .github/workflows/ (a reusable workflow). An action in
# spikes/, the test fixtures, a dependency directory, outside the repository, or missing is refused. A nested reference
# (an audited local action using another) is caught because every audited action is read too. The files are parsed
# with the pinned yq image (YQ_IMAGE), so any YAML spelling of `uses` is seen. zizmor still runs afterwards.
YQ_USES='explode(.) | .. | select(tag == "!!map") | select(has("uses")) | .uses | select(tag == "!!str") | (line | tostring) + " " + @base64'
declare -A AUDITED=()
unaudited=0
unparsed=0

while IFS= read -r -d '' f; do
  AUDITED["$(realpath "$f")"]=1
done < <(find "$ROOT/.github" -type f \( -name action.yml -o -name action.yaml \) -print0 2>/dev/null)
for f in ${action_files[@]+"${action_files[@]}"}; do
  AUDITED["$(realpath "$f")"]=1
done

uses_target_audited() { # <path relative to root>
  local real name
  real="$(realpath -m "$ROOT/$1")"
  case "$real" in "$ROOT" | "$ROOT"/*) ;; *) return 1 ;; esac
  if [ -f "$real" ]; then
    [ "$(dirname "$real")" = "$ROOT/.github/workflows" ] && [[ "$real" == *.yml || "$real" == *.yaml ]]
    return
  fi
  for name in action.yml action.yaml; do
    if [ -f "$real/$name" ] && [ -n "${AUDITED[$(realpath "$real/$name")]+set}" ]; then
      return 0
    fi
  done
  return 1
}

check_local_uses() { # <file>
  local file="$1" label rows line value rel offset
  label="${file#"$ROOT"/}"
  if ! rows="$(docker run --rm -i --network none --cap-drop ALL --security-opt no-new-privileges --read-only \
    -u "$(id -u):$(id -g)" "$YQ" eval -N "$YQ_USES" - <"$file" 2>&1)"; then
    echo "lint-workflows: $label: yq could not parse it: $(head -c 300 <<<"$rows" | tr '\n' ' ')" >&2
    unparsed=1
    return
  fi
  # yq counts lines from the end of the file's leading comment / blank block.
  offset="$(awk '/^[[:space:]]*(#.*)?\r?$/ { n++; next } { exit } END { print n + 0 }' "$file")"
  while read -r line value; do
    [ -n "$line" ] || continue
    value="$(printf '%s' "$value" | base64 -d)"
    case "$value" in
      ./* | '$/'*) rel="${value:2}" ;;
      *) continue ;;
    esac
    uses_target_audited "$rel" && continue
    echo "lint-workflows: $label:$((line + offset)): uses '$value' is not audited: it does not resolve to an action.yml / action.yaml that this check covers (it is missing, outside the repository, or in a skipped path such as spikes/, the test fixtures or a dependency directory). Move the action under .github/actions/ (or another audited path) and point uses: at it" >&2
    unaudited=1
  done <<<"$rows"
}

while IFS= read -r -d '' f; do
  check_local_uses "$f"
done < <(
  {
    find "$ROOT/.github" -type f \( -name '*.yml' -o -name '*.yaml' \) -print0 2>/dev/null
    for f in ${action_files[@]+"${action_files[@]}"}; do printf '%s\0' "$f"; done
  } | sort -zu
)
[ "$unparsed" -eq 0 ] || die 'a workflow or action could not be parsed (see above)'

# ---- Run zizmor --------------------------------------------------------------------------------------------------
docker_args=(run --rm --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp
  -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$ROOT":/repo:ro -v "$CONFIG":/zizmor.yml:ro -w /repo)
zizmor_args=(--pedantic --no-progress --strict-collection --config /zizmor.yml)
if [ "$online" -eq 1 ]; then
  docker_args+=(-e GH_TOKEN)
else
  docker_args+=(--network none)
  zizmor_args+=(--offline)
fi

status=0
docker "${docker_args[@]}" "$IMAGE" "${zizmor_args[@]}" "${inputs[@]}" || status=$?
# zizmor: 0 clean, 10-14 findings (by severity), anything else (1, 125, ...) the tool could not run.
if [ "$status" -ne 0 ] && { [ "$status" -lt 10 ] || [ "$status" -gt 14 ]; }; then
  echo "lint-workflows: zizmor could not run (exit $status)" >&2
  exit 2
fi
if [ "$status" -ne 0 ]; then
  echo 'lint-workflows: zizmor reported findings (see above)' >&2
fi
if [ "$unaudited" -ne 0 ]; then
  echo 'lint-workflows: a local uses: points at an action this audit does not cover (see above)' >&2
fi
if [ "$status" -ne 0 ] || [ "$unaudited" -ne 0 ]; then
  exit 1
fi
exit 0
