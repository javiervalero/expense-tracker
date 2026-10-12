#!/usr/bin/env bash
# Usage: scripts/check-image-pins.sh [file-or-directory ...]
# Every external image used to build or run the stack must be pinned, so a moved tag cannot change what is built or run:
#   - Dockerfiles (Dockerfile, dockerfile, Containerfile, *.Dockerfile, Dockerfile.*, any case; plus any path named by a
#     Compose `build.dockerfile`, and every `dockerfile_inline`). Continuation lines are joined first, honouring a
#     `# escape=` directive; as in BuildKit, a continuation is the escape character followed only by spaces or tabs.
#     Checked: FROM, COPY --from=, RUN --mount=...from=, the same inside ONBUILD, and a `# syntax=` directive. Each must
#     end in @sha256:<64 lowercase hex>.
#   - Compose files (*compose*.yml / *.yaml, any case). The check asks Compose what it resolves (`docker compose
#     --env-file .env.example config --format json`, all profiles, no interpolation, the environment's COMPOSE_*
#     variables ignored, no real .env read), so indentation, flow style, quoted keys, anchors, merge keys, `extends` and
#     `include` cannot hide an image. The JSON is parsed in the pinned jq image (JQ_IMAGE, defined in the Makefile): a
#     fixed single-purpose binary that needs no build, unlike the PHP tools image. Before that, each file is parsed by the
#     pinned yq image (YQ_IMAGE) to read `include` and `extends.file` (see below). Checked per service: the image; image
#     volumes (`type: image`); build arguments; additional build contexts. A service image must be pinned, except when the
#     service has `build:` AND Compose will not pull it: `pull_policy: build`, or a project-local name
#     (expense-tracker-<name>[:tag]) with pull_policy unset, `missing` or `never`.
#   - Docker actions (action.yml / action.yaml with runs.using: docker): a docker:// image must be pinned, and a
#     Dockerfile named by runs.image is checked whatever its name. (lint-workflows.sh refuses local `uses:` of actions
#     in paths skipped here, so every local action that can run is covered.)
#   - With no argument, also the project exactly as `make up` loads it (repository root, no -f, so
#     docker-compose.override.yml is merged in; `--env-file .env.example -p expense-tracker`), and .env.example itself.
#     The project is loaded only after the root compose files passed the include/extends check, and not at all when
#     .env.example sets COMPOSE_*, so a refused remote source is never fetched.
#
# Refused outright (the repository does not use them; each message says what to do instead):
#   - any variable ($) in FROM, COPY --from, --mount from= or a Compose image (a --build-arg or an environment variable
#     could replace the value at build or run time, even if its default is pinned);
#   - in a Dockerfile, a byte outside TAB and printable ASCII (BuildKit reads some of them, such as a form feed or a
#     no-break space, as white space, which this parser does not), and heredocs (`<<`) in RUN, COPY and ADD (their
#     bodies follow other line rules, so a heredoc could hide an instruction from this parser);
#   - in Compose: `models:`; every build cache entry (cache_from / cache_to: none is used, and a type=local / type=gha
#     prefix proves nothing, as the last type= wins and a cache can carry its own URL); BUILDKIT_* build arguments (map or
#     list form, any case: BUILDKIT_SYNTAX swaps the frontend image); build.sbom and build.provenance (a generator= names
#     an image); additional build contexts other than a pinned docker-image://, service:<name> or a local directory, and
#     context NAMES containing / : or @ (a named context replaces the image of the same name in FROM); `include:` entries
#     and `extends.file` values that are not local relative paths (read with yq before Compose fetches anything,
#     because the resolved model no longer says where a service came from; anchors and aliases are expanded, so no
#     YAML spelling hides them, and anything under include/extends that is not classified is refused);
#   - in .env.example, any COMPOSE_* line (make up copies it to .env, where COMPOSE_FILE would change what is loaded).
# Allowed without a pin: `FROM scratch`; FROM / COPY --from / --mount from= naming an EARLIER stage of the same
# Dockerfile (case-insensitive) or, for COPY --from and --mount, the number of an earlier stage.
# With no argument it scans the repository except spikes/ (deleted at the end of Phase 0; the skip stays so a restored harness is not scanned) and the test
# fixtures. Dependency directories are pruned by EXACT path (frontend/node_modules, frontend/dist, backend/vendor,
# docker/local-aws/vendor, contract/..., .git, .terraform, build): a directory merely NAMED vendor or build is scanned.
# Directory arguments are scanned in full (the tests rely on that); arguments that start with `-` are refused.
# Symlinked Dockerfiles are refused (the file a symlink points to is not what a reviewer saw in the diff).
# Errors are reported as file:line (Dockerfile, raw Compose text) or file:service (resolved Compose).
#
# Known gaps (not checked here):
#   - Images named in the Makefile or in scripts (`docker run ...` lines, e.g. NODE_IMAGE, GITLEAKS_IMAGE,
#     scripts/terraform.sh) are not scanned; review keeps them pinned.
#   - Build-time --build-arg values and runtime overrides (environment, `docker compose -f other.yml`, a developer's own
#     .env) are out of scope. Variables are refused in the places above, so a build argument cannot feed a FROM; it can
#     still feed a RUN that downloads something.
#   - Remote build contexts (git or http URLs) cannot be read, so their Dockerfile is reported as unverifiable.
#   - Workflows, and actions other than a docker action's image, are NOT checked here: zizmor owns them
#     (`make lint-workflows`, .github/zizmor.yml).
#   - A pull request can edit this guard, its tests, the Makefile or the CI workflow themselves. That is mitigated only
#     by review and branch protection (owner gate G4), not by anything this script can do.
# Exit: 0 all pinned, 1 something unpinned or refused, 2 bad usage or nothing to scan.
set -euo pipefail
# Byte semantics everywhere: character classes and ranges below mean ASCII bytes, whatever the caller's locale.
export LC_ALL=C

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PIN_RE='^[^@[:space:]]+@sha256:[0-9a-f]{64}$'
STAGE_NAME_RE='^[a-z0-9_.-]+$'
LOCAL_IMAGE_RE='^expense-tracker-[a-z0-9._-]+(:[A-Za-z0-9._-]+)?$'
BAD_BYTE_RE=$'[^\t -~]'
PLAIN_PATH_RE='^[A-Za-z0-9._][A-Za-z0-9._/-]*$'
COMPOSE_PROJECT='image-pins-check'
MAKE_UP_PROJECT='expense-tracker'

problems=0
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
declare -A CHECKED=() RAW_SEEN=()
JQ_RESOLVED=''
YQ_RESOLVED=''
ENV_SETS_COMPOSE=0

report() { # <file> <locator: line, service, or empty> <message>
  local where="$1"
  [ -z "$2" ] || where="$1:$2"
  printf 'check-image-pins: %s: %s\n' "$where" "$3" >&2
  problems=$((problems + 1))
}

# Prints the file's path relative to the repository when it is inside it (nicer messages).
display() {
  case "$1" in "$REPO"/*) printf '%s' "${1#"$REPO"/}" ;; *) printf '%s' "$1" ;; esac
}

# ---- Dockerfile --------------------------------------------------------------------------------------------------
# These helpers read the caller's locals (file, n, stages, nstages): bash scopes locals dynamically.

# check_source <what> <ref> <from|use>: `from` is a FROM (only a stage name may stand in for an image);
# `use` is COPY --from / --mount from= (a stage name or the number of an earlier stage may stand in).
check_source() {
  local what="$1" ref="${2//[\"\']/}" kind="$3" key
  if [ -z "$ref" ]; then
    report "$file" "$n" "$what has an empty value; name a pinned image or an earlier stage"
    return
  fi
  case "$ref" in
    *'$'*)
      report "$file" "$n" "$what '$ref' uses a variable; variables are not allowed here, write the pinned image literally"
      return
      ;;
  esac
  key="${ref,,}"
  if [ "$kind" = from ] && [ "$key" = scratch ]; then
    return
  fi
  if [[ "$key" =~ $STAGE_NAME_RE ]]; then
    if [ -n "${stages[$key]+set}" ] && { [ "$kind" = from ] || [ "${stages[$key]}" -lt $((nstages - 1)) ]; }; then
      return
    fi
    if [ "$kind" = use ] && [[ "$key" =~ ^[0-9]{1,6}$ ]] && [ $((10#$key)) -lt $((nstages - 1)) ]; then
      return
    fi
  fi
  [[ "$ref" =~ $PIN_RE ]] ||
    report "$file" "$n" "$what '$ref' is not pinned by digest (expected name@sha256:<64 hex>; a stage name counts only when an earlier stage defines it)"
}

docker_from() { # words: FROM [--flag ...] image [AS name]
  local i=1 ref name
  while [[ "${words[$i]:-}" == --* ]]; do i=$((i + 1)); done
  ref="${words[$i]:-}"
  if [ -z "$ref" ]; then
    report "$file" "$n" 'FROM without an image'
  else
    check_source 'FROM' "$ref" from
  fi
  if [ "${in_onbuild:-0}" -eq 1 ]; then
    return
  fi
  name="${words[$((i + 2))]:-}"
  name="${name//[\"\']/}"
  if [ "${words[$((i + 1))]:-}" != "" ] && [ "${words[$((i + 1))],,}" = as ]; then
    if [ -z "$name" ]; then
      report "$file" "$n" 'AS without a stage name'
    elif ! [[ "${name,,}" =~ $STAGE_NAME_RE ]]; then
      report "$file" "$n" "stage name '$name' is not valid"
    elif [ -z "${stages[${name,,}]+set}" ]; then
      stages["${name,,}"]="$nstages"
    fi
  fi
  nstages=$((nstages + 1))
}

docker_copy() { # words: COPY|ADD [--from=ref ...] src... dest
  local w
  for w in "${words[@]:1}"; do
    case "${w,,}" in
      --from=*) check_source 'COPY --from' "${w#*=}" use ;;
      --from) report "$file" "$n" 'COPY --from without a value (write --from=<image or stage>)' ;;
    esac
  done
}

docker_run() { # words: RUN [--mount=...,from=ref,... | --other-flag ...] command
  local i=1 w part
  local -a parts
  while [[ "${words[$i]:-}" == --* ]]; do
    w="${words[$i]//[\"\']/}"
    if [[ "${w,,}" == --mount=* ]]; then
      IFS=, read -r -a parts <<<"${w#*=}"
      for part in "${parts[@]}"; do
        case "${part,,}" in from=*) check_source 'RUN --mount from=' "${part#*=}" use ;; esac
      done
    fi
    i=$((i + 1))
  done
}

docker_arg() { # words: ARG name[=default] ...
  local w
  if [ "${#words[@]}" -lt 2 ]; then
    report "$file" "$n" 'ARG with an empty name'
    return
  fi
  for w in "${words[@]:1}"; do
    [ -n "${w%%=*}" ] || report "$file" "$n" 'ARG with an empty name'
  done
}

docker_instruction() { # <logical line>; in_onbuild is set while the instruction is inside ONBUILD
  local -a words
  local kw
  read -r -a words <<<"$1"
  in_onbuild=0
  while [ "${#words[@]}" -gt 0 ] && [ "${words[0],,}" = onbuild ]; do
    in_onbuild=1
    words=("${words[@]:1}")
  done
  [ "${#words[@]}" -gt 0 ] || return 0
  kw="${words[0],,}"
  case "$kw" in
    run | copy | add)
      if [[ "$1" == *'<<'* ]]; then
        report "$file" "$n" "${kw^^} uses a heredoc (<<), which this check refuses (its body follows other line rules and could hide an instruction): put the commands on continuation lines, or COPY a file from the build context"
      fi
      ;;
  esac
  case "$kw" in
    from) docker_from ;;
    copy | add) docker_copy ;;
    run) docker_run ;;
    arg) docker_arg ;;
  esac
}

# Parser directives (# name=value) are only honoured before the first blank line, comment or instruction.
# A `# syntax=` comment later in the file is ignored by BuildKit, but it is checked anyway (`syntax-only`), so that a
# difference between BuildKit's parser and this one cannot hide an unpinned frontend.
docker_directive() { # <text> [syntax-only]: returns 0 when the line is a directive
  local name value
  [[ "$1" =~ ^#[[:space:]]*([A-Za-z]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || return 1
  name="${BASH_REMATCH[1],,}"
  value="${BASH_REMATCH[2]}"
  if [ "${2:-}" = syntax-only ] && [ "$name" != syntax ]; then
    return 1
  fi
  case "$name" in
    escape)
      if [ "$value" = '\' ] || [ "$value" = '`' ]; then
        escape="$value"
      else
        report "$file" "$n" "escape directive '$value' is not valid (use \\ or a backtick)"
      fi
      ;;
    syntax)
      if [[ "$value" == *'$'* ]] || ! [[ "$value" =~ $PIN_RE ]]; then
        report "$file" "$n" "syntax directive '$value' is not pinned by digest (expected image@sha256:<64 hex>)"
      fi
      ;;
    check) ;;
    *) return 1 ;;
  esac
  return 0
}

# A NUL byte never reaches the line loop (bash's read drops it), so it is looked for separately. Swapping NUL and LF
# makes the text before the first NUL one "line", whose (former) LFs are counted.
docker_nul_bytes() { # <path>
  local before
  [ "$(tr -d '\000' <"$path" | wc -c)" -ne "$(wc -c <"$path")" ] || return 0
  before="$(tr '\000\n' '\n\000' <"$path" | head -n 1 | tr -cd '\000' | wc -c)"
  report "$file" "$((before + 1))" 'has a NUL byte, which this check refuses: remove it'
}

check_dockerfile() { # <path> [label]
  local path="$1" file="${2:-$(display "$1")}"
  local raw line text phys=0 n=0 escape='\' in_directives=1 continuing=0 logical='' first=1 nstages=0 in_onbuild=0
  local -A stages=()
  docker_nul_bytes
  while IFS= read -r raw || [ -n "$raw" ]; do
    phys=$((phys + 1))
    if [ "$first" -eq 1 ]; then
      first=0
      raw="${raw#$'\xef\xbb\xbf'}"
    fi
    raw="${raw%$'\r'}"
    if [[ "$raw" =~ $BAD_BYTE_RE ]]; then
      report "$file" "$phys" 'has a byte outside TAB and printable ASCII (a control character such as a form feed, vertical tab or stray CR, or a non-ASCII character such as a no-break space), which BuildKit may read as white space and this check does not: replace it with a plain space or remove it'
    fi
    line="${raw%"${raw##*[!$' \t']}"}"
    text="${line#"${line%%[!$' \t']*}"}"
    if [ "$continuing" -eq 1 ]; then
      case "$text" in '' | '#'*) continue ;; esac
    else
      n="$phys"
      if [ "$in_directives" -eq 1 ]; then
        if docker_directive "$text"; then
          continue
        fi
        in_directives=0
      fi
      case "$text" in '' | '#'*)
        docker_directive "$text" syntax-only || true
        continue
        ;;
      esac
      logical=''
    fi
    if [ -n "$line" ] && [ "${line: -1}" = "$escape" ]; then
      logical+="${line%?}"
      continuing=1
      continue
    fi
    logical+="$line"
    continuing=0
    docker_instruction "$logical"
  done <"$path"
  if [ "$continuing" -eq 1 ]; then
    docker_instruction "$logical"
  fi
}

# check_dockerfile_once <path> <label> <reason>: refuses symlinks and missing files, and never checks a file twice.
check_dockerfile_once() {
  local path="$1" label="$2" key
  if [ -L "$path" ]; then
    report "$label" '' 'is a symlink; build files must be regular files (the target is not what the diff shows)'
    return
  fi
  if [ ! -f "$path" ]; then
    report "$label" '' 'build file not found'
    return
  fi
  key="$(realpath -m "$path")"
  if [ -n "${CHECKED[$key]+set}" ]; then
    return
  fi
  CHECKED["$key"]=1
  check_dockerfile "$path"
}

# ---- YAML read with yq (Compose include / extends.file, docker actions) ------------------------------------------
# The resolved Compose model no longer says which file a service came from, so `include:` entries and `extends.file`
# values are read from the file itself, BEFORE Compose sees it (Compose would fetch a remote one). The file is parsed by
# the pinned yq image (YQ_IMAGE, see the Makefile; no network, read-only, no capabilities), every document, with
# anchors, aliases and merge keys expanded, so quoting, escapes ("inc\x6cude"), complex keys (? include) or an alias
# used as a key cannot hide one. Every scalar below `include` and below each `services.*.extends` is classified by its
# path; anything unexpected is refused. The raw_* helpers read the caller's locals (file, dir, n, bad).
YQ_REFS='explode(.) | ((.include, (.services // {} | .[] | .extends)) | select(. != null) | .. | select(tag != "!!map" and tag != "!!seq") | ((path | join("/")) | @base64) + " " + (line | tostring) + " " + tag + " " + (. | tostring | @base64))'
YQ_ACTION='explode(.) | ((.runs.using // "" | tostring | @base64) + " " + (.runs.image // "" | tostring | @base64) + " " + (.runs.image | line | tostring))'

yq_image() {
  local image="${YQ_IMAGE:-$(make -s --no-print-directory -C "$REPO" print-YQ_IMAGE)}"
  [[ "$image" =~ $PIN_RE ]] || {
    echo "check-image-pins: the yq image '$image' is not pinned by digest (YQ_IMAGE must be name@sha256:<64 hex>)" >&2
    exit 2
  }
  printf '%s' "$image"
}

ensure_yq() { # must run in the main shell: yq_image's `exit 2` would only leave a $(...) subshell
  if [ -z "$YQ_RESOLVED" ]; then
    YQ_RESOLVED="$(yq_image)" || exit 2
  fi
}

yq_eval() { # <expression>, YAML on stdin
  docker run --rm -i --network none --cap-drop ALL --security-opt no-new-privileges --read-only \
    -u "$(id -u):$(id -g)" "$YQ_RESOLVED" eval -N "$1" -
}

b64d() { printf '%s' "$1" | base64 -d; }

# yq counts lines from the first line after the file's leading comment / blank block; this is that block's length,
# added back so messages name the real line.
yq_line_offset() { # <path>
  awk '/^[[:space:]]*(#.*)?\r?$/ { n++; next } { exit } END { print n + 0 }' "$1"
}

raw_refuse() { # <message>
  report "$file" "$n" "$1"
  bad=1
}

# raw_ref <what> <value> [follow]: the value must be a local relative path. With `follow`, a local *.yml / *.yaml
# target is read the same way (an included file can include further files).
raw_ref() {
  local what="$1" v="$2" why='' target
  case "$v" in
    *'$'*) why='uses a variable' ;;
    *://* | git@* | oci:*) why='is remote' ;;
    *.git | *.git#* | *.git/ | github.com/* | gitlab.com/* | bitbucket.org/*) why='names a git repository' ;;
    /* | '~'*) why='is an absolute path' ;;
    *) [[ "$v" =~ $PLAIN_PATH_RE ]] || why='is not a plain relative path (letters, digits, . _ - and /)' ;;
  esac
  if [ -n "$why" ]; then
    raw_refuse "$what '$v' $why; only local relative paths are allowed (what a remote file holds can change after review): copy the file into the repository and refer to it by its relative path"
    return
  fi
  target="$dir/$v"
  case "${v,,}" in *.yml | *.yaml) ;; *) return 0 ;; esac
  if [ "${3:-}" = follow ] && [ -f "$target" ] && [ ! -L "$target" ]; then
    raw_compose_refs "$target" || bad=1
  fi
}

raw_leaf() { # <path below the document, e.g. include/0/path/1> <yaml tag> <value>
  local p="$1" tag="$2" v="$3"
  if [[ "$p" =~ ^services/[^/]+/extends/service$ ]]; then
    return 0
  fi
  if [[ "$p" =~ ^services/[^/]+/extends$ ]]; then
    [ "$tag" = '!!str' ] && [[ "$v" =~ ^[A-Za-z0-9._-]+$ ]] ||
      raw_refuse "extends: '$v' is neither a service name nor a mapping with file: and service:"
    return 0
  fi
  if [ "$tag" != '!!str' ]; then
    raw_refuse "'$p' is $tag ('$v'), not a path; write a relative path as a plain string"
  elif [[ "$p" =~ ^include/[0-9]+$ ]]; then
    raw_ref 'include entry' "$v" follow
  elif [[ "$p" =~ ^include/[0-9]+/path(/[0-9]+)?$ ]]; then
    raw_ref 'include path' "$v" follow
  elif [[ "$p" =~ ^include/[0-9]+/(env_file|project_directory)(/[0-9]+)?$ ]]; then
    raw_ref "include ${BASH_REMATCH[1]}" "$v"
  elif [[ "$p" =~ ^services/[^/]+/extends/file$ ]]; then
    raw_ref 'extends.file' "$v" follow
  else
    raw_refuse "'$p' ('$v') is not something this check classifies (include takes a list of paths or of path:/env_file:/project_directory: entries; extends takes file: and service:): remove it"
  fi
}

raw_compose_refs() { # <path>: returns 1 when something was refused (remembered per file, so a second caller sees it)
  local path="$1" file dir rows p n='' tag v bad=0 key offset errors="$WORK/yq-errors"
  key="$(realpath -m "$path")"
  if [ -n "${RAW_SEEN[$key]+set}" ]; then
    return "${RAW_SEEN[$key]}"
  fi
  RAW_SEEN["$key"]=0
  file="$(display "$path")"
  dir="$(dirname "$path")"
  ensure_yq
  if ! rows="$(yq_eval "$YQ_REFS" <"$path" 2>"$errors")"; then
    raw_refuse "yq could not parse this YAML, so its include and extends sources cannot be checked (and Compose is not asked to): $(head -c 300 "$errors" | tr '\n' ' ')"
  else
    offset="$(yq_line_offset "$path")"
    while read -r p n tag v; do
      [ -n "$p" ] || continue
      n=$((n + offset))
      raw_leaf "$(b64d "$p")" "$tag" "$(b64d "$v")"
    done <<<"$rows"
  fi
  RAW_SEEN["$key"]="$bad"
  return "$bad"
}

# A docker action (runs.using: docker) names either docker://<image>, which must be pinned, or a Dockerfile relative to
# the action, which is checked like any other (whatever its name). Other actions are zizmor's (lint-workflows.sh).
check_action() { # <path of action.yml / action.yaml>
  local path="$1" file rows using image n dir errors="$WORK/yq-errors"
  file="$(display "$path")"
  if [ -L "$path" ]; then
    report "$file" '' 'is a symlink; action files must be regular files'
    return
  fi
  ensure_yq
  if ! rows="$(yq_eval "$YQ_ACTION" <"$path" 2>"$errors")"; then
    report "$file" '' "yq could not parse this action: $(head -c 300 "$errors" | tr '\n' ' ')"
    return
  fi
  read -r using image n <<<"$rows"
  n=$((n + $(yq_line_offset "$path")))
  using="$(b64d "$using")"
  image="$(b64d "$image")"
  [ "${using,,}" = docker ] || return 0
  dir="$(dirname "$path")"
  case "$image" in
    '') report "$file" '' 'is a docker action without runs.image' ;;
    *'$'*) report "$file" "$n" "runs.image '$image' uses a variable or expression; write the pinned image or the Dockerfile path literally" ;;
    docker://*) [[ "${image#docker://}" =~ $PIN_RE ]] ||
      report "$file" "$n" "runs.image '$image' is not pinned by digest (expected docker://name@sha256:<64 hex>)" ;;
    *://* | /*) report "$file" "$n" "runs.image '$image' is neither docker://<pinned image> nor a Dockerfile path relative to the action" ;;
    *) check_dockerfile_once "$dir/$image" "$(display "$dir/$image") (runs.image of $file)" ;;
  esac
}

# ---- Compose: resolved model -------------------------------------------------------------------------------------
# One row per thing to check; values are base64 of "_<value>" so empty values and blanks survive `read`.
JQ_FILTER='
def e: ("_" + (. // "" | tostring)) | @base64;
(if ((.models // {}) | length) > 0 then ([ "T", ((.models | keys | join(", ")) | e) ] | join(" ")) else empty end),
(.services // {} | to_entries[] | .key as $n | .value as $s | ($s.build // null) as $b |
  ([ "S", ($n | e), ($s.image | e), (if $b == null then "0" else "1" end), ($s.pull_policy | e),
     ($b.context | e), ($b.dockerfile | e), ($b.dockerfile_inline | e) ] | join(" ")),
  (if ($s.models // null) != null then ([ "M", ($n | e) ] | join(" ")) else empty end),
  (($s.volumes // [])[] | select(type == "object" and .type == "image") | [ "V", ($n | e), (.source | e) ] | join(" ")),
  (($b.additional_contexts // {}) | to_entries[] | [ "A", ($n | e), (.key | e), (.value | e) ] | join(" ")),
  (($b.cache_from // [])[] | [ "C", ($n | e), ("cache_from" | e), e ] | join(" ")),
  (($b.cache_to // [])[] | [ "C", ($n | e), ("cache_to" | e), e ] | join(" ")),
  (($b.args // {}) | (if type == "array" then (.[] | tostring | split("=")[0]) else keys[] end) |
    select(test("^buildkit_"; "i")) | [ "B", ($n | e), e ] | join(" ")),
  (($b // {}) | to_entries[] | select(.key == "sbom" or .key == "provenance") |
    [ "P", ($n | e), (.key | e), (.value | e) ] | join(" ")))
'

jq_image() {
  local image="${JQ_IMAGE:-$(make -s --no-print-directory -C "$REPO" print-JQ_IMAGE)}"
  [[ "$image" =~ $PIN_RE ]] || {
    echo "check-image-pins: the jq image '$image' is not pinned by digest (JQ_IMAGE must be name@sha256:<64 hex>)" >&2
    exit 2
  }
  printf '%s' "$image"
}

dec() { # base64 of "_<value>" -> value
  local d
  d="$(printf '%s' "$1" | base64 -d)"
  printf '%s' "${d#_}"
}

env_file() { # the env file every Compose call reads: .env.example, never a developer's .env
  if [ -f "$REPO/.env.example" ]; then printf '%s' "$REPO/.env.example"; else printf '/dev/null'; fi
}

drop_compose_env() { # COMPOSE_* variables of the caller's environment (COMPOSE_FILE, COMPOSE_PROFILES, ...)
  local v
  for v in $(compgen -e | grep '^COMPOSE_' || true); do unset "$v"; done
}

# What Compose resolves for one file, as JSON. The project directory is the file's own, and --env-file is given
# explicitly, so no `.env` is read.
compose_json() { # <file>
  drop_compose_env
  docker compose --project-directory "$(dirname "$1")" --env-file "$(env_file)" -p "$COMPOSE_PROJECT" -f "$1" \
    --profile '*' config --format json --no-interpolate --no-env-resolution
}

# What Compose resolves for the repository the way `make up` loads it: from the root, no -f (so the default file and
# docker-compose.override.yml are merged), the same project name, every profile.
project_json() {
  drop_compose_env
  cd "$REPO"
  docker compose --env-file "$(env_file)" -p "$MAKE_UP_PROJECT" --profile '*' config --format json --no-interpolate \
    --no-env-resolution
}

compose_service() { # <file> <service> <image> <has_build> <pull_policy> <context> <dockerfile> <inline>
  local file="$1" name="$2" image="$3" has_build="$4" pull="$5" context="$6" dockerfile="$7" inline="$8" where
  where="service '$name'"
  if [ -z "$image" ] && [ "$has_build" = 0 ]; then
    report "$file" "$where" 'has neither an image nor a build'
  elif [ -n "$image" ]; then
    if [[ "$image" == *'$'* ]]; then
      report "$file" "$where" "image '$image' uses a variable; variables are not allowed, write the pinned image literally"
    elif ! [[ "$image" =~ $PIN_RE ]]; then
      if [ "$has_build" = 1 ] && { [ "$pull" = build ] ||
        { [[ "$image" =~ $LOCAL_IMAGE_RE ]] && [[ "$pull" =~ ^(|missing|never)$ ]]; }; }; then
        :
      elif [ "$has_build" = 1 ]; then
        report "$file" "$where" "image '$image' is built here, but Compose may pull it instead (pull_policy '${pull:-unset}'): a built image is exempt from the pin only with pull_policy: build, or when it is named expense-tracker-<name>[:tag] with pull_policy unset, missing or never. Rename it, set pull_policy: build, or pin it by digest"
      else
        report "$file" "$where" "image '$image' is not pinned by digest (expected name@sha256:<64 hex>)"
      fi
    fi
  fi
  [ "$has_build" = 1 ] || return 0
  compose_build "$file" "$where" "$name" "$context" "$dockerfile" "$inline"
}

compose_build() { # <file> <where> <service> <context> <dockerfile> <inline>
  local file="$1" where="$2" name="$3" context="$4" dockerfile="$5" inline="$6" dir
  if [ -n "$inline" ]; then
    dir="$WORK/inline-$RANDOM-$name"
    mkdir -p "$dir"
    printf '%s\n' "$inline" >"$dir/Dockerfile"
    check_dockerfile "$dir/Dockerfile" "$file ($where, dockerfile_inline)"
    return
  fi
  if [[ "$context$dockerfile" == *'$'* ]]; then
    report "$file" "$where" 'build context or dockerfile uses a variable; its Dockerfile cannot be checked'
    return
  fi
  if [[ "$context" != /* ]]; then
    report "$file" "$where" "build context '$context' is not a local directory; its Dockerfile cannot be checked"
    return
  fi
  [ -n "$dockerfile" ] || dockerfile=Dockerfile
  case "$dockerfile" in /*) ;; *) dockerfile="$context/$dockerfile" ;; esac
  check_dockerfile_once "$dockerfile" "$(display "$dockerfile") (build.dockerfile of $where in $file)"
}

compose_pinned() { # <file> <service> <what> <image>: the shared rule for images named outside `image:`
  if [[ "$4" == *'$'* ]]; then
    report "$1" "service '$2'" "$3 '$4' uses a variable; write the pinned image literally"
  elif ! [[ "$4" =~ $PIN_RE ]]; then
    report "$1" "service '$2'" "$3 '$4' is not pinned by digest (expected name@sha256:<64 hex>)"
  fi
}

# Allowed: a pinned docker-image://, service:<name>, or a local directory (Compose turns a relative path into an
# absolute one; a value it leaves relative, such as github.com/x/y, is one BuildKit would fetch).
compose_context() { # <file> <service> <name> <value>
  local file="$1" where="service '$2'" name="$3" value="$4"
  if [[ "$name" == *[/:@]* ]] || [ "${name,,}" = scratch ]; then
    report "$file" "$where" "additional context name '$name' contains '/', ':' or '@' (or is 'scratch'); a named context replaces the image of the same name in FROM, so it could stand in for a pinned image: use a plain name (letters, digits, . _ -)"
  fi
  case "$value" in
    docker-image://*) compose_pinned "$file" "$2" "additional context '$name' image" "${value#docker-image://}" ;;
    service:*) [[ "$value" =~ ^service:[A-Za-z0-9._-]+$ ]] ||
      report "$file" "$where" "additional context '$name' value '$value' is not service:<name>" ;;
    *'$'* | *://* | git@* | *.git | *.git#* | *.git/ | oci-layout:*) compose_context_refused ;;
    /*) ;;
    *) compose_context_refused ;;
  esac
}

compose_context_refused() { # reads compose_context's locals
  report "$file" "$where" "additional context '$name' is '$value', which is not a pinned docker-image://, a service:<name> or a local directory (remote, git and OCI-layout contexts can change after review): copy the files into the repository, or use a pinned docker-image://"
}

# No build cache is imported or exported in this repository, and an entry cannot be validated by its prefix (BuildKit
# applies the LAST type= of the attribute list, and gha/s3/azblob caches carry their own URLs), so every entry is refused.
compose_cache() { # <file> <service> <cache_from|cache_to> <entry>
  report "$1" "service '$2'" "build.$3 entry '$4' is refused: this repository uses no build cache, and a cache entry cannot be checked safely (the last type= wins, and a cache can name its own URL, feeding layers that were not built from this repository): remove it"
}

jq_rows() { # <json>: prints the rows of JQ_FILTER (JQ_RESOLVED is set by the caller, in this shell)
  printf '%s' "$1" | docker run --rm -i --network none --cap-drop ALL --security-opt no-new-privileges --read-only \
    -u "$(id -u):$(id -g)" "$JQ_RESOLVED" -r "$JQ_FILTER"
}

check_compose_model() { # <label> <json>
  local file="$1" rows kind a b c d e f g errors="$WORK/jq-errors"
  # Resolved here, not inside $(...): jq_image's `exit 2` would only leave a subshell there.
  if [ -z "$JQ_RESOLVED" ]; then
    JQ_RESOLVED="$(jq_image)" || exit 2
  fi
  if ! rows="$(jq_rows "$2" 2>"$errors")"; then
    report "$file" '' "jq could not read the resolved Compose model: $(head -c 300 "$errors" | tr '\n' ' ')"
    return
  fi
  while read -r kind a b c d e f g; do
    case "$kind" in
      T) report "$file" '' "defines models: ($(dec "$a")); this repository does not use them, so they are refused: remove the models: section" ;;
      M) report "$file" "service '$(dec "$a")'" 'uses models:; this repository does not use them, so they are refused: remove it' ;;
      S) compose_service "$file" "$(dec "$a")" "$(dec "$b")" "$c" "$(dec "$d")" "$(dec "$e")" "$(dec "$f")" "$(dec "$g")" ;;
      V) compose_pinned "$file" "$(dec "$a")" 'image volume source' "$(dec "$b")" ;;
      A) compose_context "$file" "$(dec "$a")" "$(dec "$b")" "$(dec "$c")" ;;
      C) compose_cache "$file" "$(dec "$a")" "$(dec "$b")" "$(dec "$c")" ;;
      B) report "$file" "service '$(dec "$a")'" "build.args sets '$(dec "$b")'; BUILDKIT_* build arguments configure BuildKit itself (BUILDKIT_SYNTAX swaps the Dockerfile frontend image, like an unpinned # syntax=), so they are refused: remove it" ;;
      P) report "$file" "service '$(dec "$a")'" "build.$(dec "$b") ('$(dec "$c")') is refused: build attestations are not used here, and an SBOM generator= names an image that BuildKit pulls and runs: remove it" ;;
    esac
  done <<<"$rows"
}

check_compose() { # <path>
  local path="$1" file json errors="$WORK/compose-errors"
  file="$(display "$path")"
  if [ -L "$path" ]; then
    report "$file" '' 'is a symlink; Compose files must be regular files'
    return
  fi
  # Refused include/extends sources are never handed to Compose, which would fetch them.
  raw_compose_refs "$path" || return 0
  if ! json="$(compose_json "$path" 2>"$errors")"; then
    report "$file" '' "docker compose could not resolve this file, so it cannot be checked: $(head -c 300 "$errors" | tr '\n' ' ')"
    return
  fi
  check_compose_model "$file" "$json"
}

# ---- The repository as `make up` sees it -------------------------------------------------------------------------
check_env_example() {
  local f="$REPO/.env.example" line n=0
  [ -f "$f" ] || return 0
  while IFS= read -r line || [ -n "$line" ]; do
    n=$((n + 1))
    if [[ "$line" =~ ^[[:space:]]*(export[[:space:]]+)?(COMPOSE_[A-Za-z0-9_]*) ]]; then
      report "$(display "$f")" "$n" "sets ${BASH_REMATCH[2]}; COMPOSE_* variables change which files, profiles or project docker compose loads (make up copies this file to .env), so they are refused: put the setting in docker-compose.yml or the Makefile instead"
      ENV_SETS_COMPOSE=1
    fi
  done <"$f"
}

# The project is loaded only when nothing that Compose would read is already refused: not when .env.example sets
# COMPOSE_* (it could name other files), and not when a root compose file (or override) is a symlink or has an include
# or extends.file that is not local. Those are reported already; loading would make Compose fetch the remote source.
check_project() {
  local f json label='docker compose project (repository root, as make up loads it)' errors="$WORK/project-errors"
  local refused=0
  local -a roots=()
  [ "$ENV_SETS_COMPOSE" -eq 0 ] || return 0
  for f in compose.yaml compose.yml docker-compose.yaml docker-compose.yml \
    compose.override.yaml compose.override.yml docker-compose.override.yaml docker-compose.override.yml; do
    [ ! -e "$REPO/$f" ] || roots+=("$REPO/$f")
  done
  [ "${#roots[@]}" -gt 0 ] || return 0
  for f in "${roots[@]}"; do
    if [ -L "$f" ] || [ ! -f "$f" ]; then
      refused=1
    else
      raw_compose_refs "$f" || refused=1
    fi
  done
  [ "$refused" -eq 0 ] || return 0
  if ! json="$(project_json 2>"$errors")"; then
    report "$label" '' "docker compose could not resolve it, so it cannot be checked: $(head -c 300 "$errors" | tr '\n' ' ')"
    return
  fi
  check_compose_model "$label" "$json"
}

# ---- Discovery ---------------------------------------------------------------------------------------------------
# kind_of <path>: prints dockerfile, compose, or nothing. Case-insensitive.
kind_of() {
  local base="${1##*/}"
  base="${base,,}"
  case "$base" in
    *.dockerignore) return ;;
    dockerfile | containerfile | *.dockerfile | dockerfile.* | containerfile.*) echo dockerfile ;;
    action.yml | action.yaml) echo action ;;
    *compose*.yml | *compose*.yaml) echo compose ;;
  esac
}

scan_files=()
collect() { # <root> [extra pruned paths]: candidate files below root, without dependency/VCS/build directories
  local root="${1%/}" p
  shift
  local -a prune=(-path "$root/.git" -o -path "$root/.terraform" -o -path "$root/build"
    -o -path "$root/frontend/node_modules" -o -path "$root/frontend/dist"
    -o -path "$root/contract/node_modules" -o -path "$root/contract/dist"
    -o -path "$root/backend/vendor" -o -path "$root/docker/local-aws/vendor")
  for p in "$@"; do
    prune+=(-o -path "$p")
  done
  while IFS= read -r -d '' f; do
    scan_files+=("$f")
  done < <(
    find "$root" \( "${prune[@]}" \) -prune -o \( -type f -o -type l \) \
      \( -iname dockerfile -o -iname containerfile -o -iname '*.dockerfile' -o -iname 'dockerfile.*' -o -iname 'containerfile.*' \
      -o -iname '*compose*.yml' -o -iname '*compose*.yaml' -o -iname action.yml -o -iname action.yaml \) \
      ! -iname '*.dockerignore' -print0 | sort -z
  )
}

for arg in "$@"; do
  case "$arg" in
    -*)
      echo "check-image-pins: '$arg' looks like an option; arguments are files or directories (prefix with ./ if it is a name)" >&2
      exit 2
      ;;
  esac
done

if [ "$#" -eq 0 ]; then
  collect "$REPO" "$REPO/spikes" "$REPO/scripts/tests/fixtures"
else
  for arg in "$@"; do
    if [ -d "$arg" ]; then
      collect "$arg"
    elif [ -f "$arg" ]; then
      scan_files+=("$arg")
    else
      echo "check-image-pins: not a file or directory: $arg" >&2
      exit 2
    fi
  done
fi

scanned=0
for f in ${scan_files[@]+"${scan_files[@]}"}; do
  case "$(kind_of "$f")" in
    dockerfile) check_dockerfile_once "$f" "$(display "$f")" ;;
    compose) check_compose "$f" ;;
    action) check_action "$f" ;;
    *) continue ;;
  esac
  scanned=$((scanned + 1))
done

if [ "$scanned" -eq 0 ]; then
  echo 'check-image-pins: no Dockerfile or Compose file to scan' >&2
  exit 2
fi
if [ "$#" -eq 0 ]; then
  check_env_example
  check_project
fi
if [ "$problems" -ne 0 ]; then
  printf 'check-image-pins: %s problem(s) in %s file(s)\n' "$problems" "$scanned" >&2
  exit 1
fi
echo "check-image-pins: every image is pinned ($scanned files)"
