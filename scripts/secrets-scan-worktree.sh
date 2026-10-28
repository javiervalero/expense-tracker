#!/usr/bin/env bash
# Usage: GITLEAKS_IMAGE=<pinned image> scripts/secrets-scan-worktree.sh     (`make secrets-scan-worktree`)
# Runs from the root of a git repository. gitleaks over UNCOMMITTED work, which `gitleaks git` (committed history only)
# never sees. Two roots, one `gitleaks dir` container each, so findings report repo-relative paths and the path
# allowlists of .gitleaks.toml (the working-tree copy) match exactly as in the history scan:
#   disk:  files that differ from HEAD (staged or unstaged edits, adds) plus untracked files that are not ignored,
#          copied from the working tree. Deleted paths are skipped.
#   index: the staged blobs (added, copied, modified, renamed), read with `git cat-file blob` (no smudge/eol filters).
#          They are scanned even when the disk file was overwritten, restored or removed after `git add`.
# Symlinks and submodules are not followed. Same hardening as the history scan: host user, read-only mounts and root
# file system, no network, no capabilities, no-new-privileges, findings redacted, inline `gitleaks:allow` comments
# ignored. A .gitleaksignore among the files is refused. The throwaway directory is removed on every exit path, and a
# failing git command fails the scan (it never reads as "nothing to scan").
# Exit: 0 clean (or nothing uncommitted); any non-zero status blocks. 1 means findings, a refused file, or a tool error
# (docker or gitleaks failing can also surface as 125-127); 2 means bad usage or a failing git command.
set -euo pipefail
export LC_ALL=C

die() {
  echo "secrets-scan-worktree: $1" >&2
  exit "${2:-2}"
}

[ -n "${GITLEAKS_IMAGE:-}" ] || die 'GITLEAKS_IMAGE is not set (it is defined in the Makefile)'
git rev-parse --show-toplevel >/dev/null 2>&1 || die 'not inside a git repository'
cd "$(git rev-parse --show-toplevel)"

# Without a commit yet, compare against the empty tree so that everything staged counts as changed.
base=HEAD
git rev-parse -q --verify HEAD >/dev/null || base="$(git hash-object -t tree /dev/null)"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir "$WORK/disk" "$WORK/index" "$WORK/cfg" "$WORK/lists"

refuse_ignorefile() { # <path>
  [ "${1##*/}" = .gitleaksignore ] || return 0
  die "a .gitleaksignore file is not allowed ($1); allow a known-fake value in .gitleaks.toml instead (by path AND rule AND value), or rotate a real secret" 1
}

make_parent() { # <root> <path>
  case "$2" in */*) mkdir -p -- "$1/${2%/*}" ;; esac
}

# Lists first, into files: a failing git command must stop the script (a process substitution would hide its status).
git diff --name-only -z "$base" -- >"$WORK/lists/changed" || die 'git diff failed' 2
git ls-files -z --others --exclude-standard >"$WORK/lists/untracked" || die 'git ls-files failed' 2
git diff --cached --raw -z --no-abbrev --no-renames --diff-filter=ACMR "$base" -- >"$WORK/lists/staged" || die 'git diff --cached failed' 2

disk_count=0
while IFS= read -r -d '' path; do
  # Regular files only: skips deleted paths, symlinks and submodule directories.
  if [ -L "$path" ] || [ ! -f "$path" ]; then continue; fi
  refuse_ignorefile "$path"
  make_parent "$WORK/disk" "$path"
  cp -- "$path" "$WORK/disk/$path"
  disk_count=$((disk_count + 1))
done < <(cat "$WORK/lists/changed" "$WORK/lists/untracked")

# Records of --raw -z: ":<old mode> <new mode> <old sha> <new sha> <status>" NUL "<path>" NUL
index_count=0
while IFS= read -r -d '' meta && IFS= read -r -d '' path; do
  read -r _ new_mode _ new_sha _ <<<"$meta"
  case "$new_mode" in 120000 | 160000) continue ;; esac # symlink, submodule
  refuse_ignorefile "$path"
  make_parent "$WORK/index" "$path"
  git cat-file blob "$new_sha" >"$WORK/index/$path" || die "git cat-file failed for $path" 2
  index_count=$((index_count + 1))
done <"$WORK/lists/staged"

if [ "$disk_count" -eq 0 ] && [ "$index_count" -eq 0 ]; then
  echo 'secrets-scan-worktree: nothing uncommitted to scan'
  exit 0
fi

args=(--redact --no-banner --ignore-gitleaks-allow --exit-code 1 -v)
cfg_mounts=()
if [ -f .gitleaks.toml ]; then
  cp -- .gitleaks.toml "$WORK/cfg/gitleaks.toml"
  cfg_mounts=(-v "$WORK/cfg":/cfg:ro)
  args+=(-c /cfg/gitleaks.toml)
fi

scan_root() { # <name> <file count>: one container, working directory = the root, so paths are repo-relative
  [ "$2" -gt 0 ] || return 0
  echo "secrets-scan-worktree: scanning $2 $1 file(s)"
  docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp \
    -u "$(id -u):$(id -g)" -v "$WORK/$1":/scan:ro "${cfg_mounts[@]}" -e HOME=/tmp -w /scan "$GITLEAKS_IMAGE" dir "${args[@]}" .
}

rc=0
scan_root disk "$disk_count" || rc=$?
scan_root index "$index_count" || rc=$?
exit "$rc"
