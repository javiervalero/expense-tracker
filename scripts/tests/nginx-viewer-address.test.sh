#!/usr/bin/env bash
# Tests of the API nginx configuration (docker/api), with nginx alone, in throwaway containers of the pinned image.
# Needs Docker and the image (pulled on first use). Run: bash scripts/tests/nginx-viewer-address.test.sh
#
# 1. docker/api/viewer-address.conf (the CloudFront-Viewer-Address map, ADR 0012 section 4): a tiny nginx includes
#    the real file and echoes the mapped variable in a response header. Cases: absent, present, empty, duplicated.
# 2. docker/api/nginx.conf (the real file): `nginx -T` must accept it and show `fastcgi_param HTTP_PROXY ""` AFTER
#    the fastcgi_params include (httpoxy). Why not a live request: seeing HTTP_PROXY from PHP would need a FastCGI
#    echo (a test-only route is not allowed in src/, and a second image just for this is too heavy). The static check
#    is cheap and exact: nginx overrides an earlier fastcgi_param of the same name with the later one, so "present
#    and after the include" is the whole guarantee.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$HERE/../.."
# The image reference, as Compose resolves it (tolerates any indentation or quoting in the YAML). Always read with
# .env.example: this lookup only needs image refs, and CI has no .env (the compose file requires secrets via ${VAR:?}).
IMAGE="$(cd "$REPO" && docker compose --env-file .env.example -p expense-tracker config --images | grep '^nginxinc/nginx-unprivileged:' | head -n 1)"
[ -n "$IMAGE" ] || { echo "no nginx image found in docker-compose.yml" >&2; exit 1; }
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
chmod 755 "$WORK"

cat >"$WORK/nginx.conf" <<'EOF'
pid /tmp/nginx.pid;
error_log /dev/stderr warn;
events {}
http {
    access_log off;
    include /etc/nginx/viewer-address.conf;
    server {
        listen 8081;
        location / {
            add_header X-Viewer "[$viewer_address]" always;
            return 204;
        }
    }
}
EOF
chmod 644 "$WORK/nginx.conf"

# Starts nginx, polls until it answers (up to ~10 s), then sends four requests; prints each X-Viewer header line.
# busybox wget: the empty header is sent as "Name:" and the duplicate as two --header options.
probe=$(docker run --rm \
  -v "$WORK/nginx.conf":/etc/nginx/nginx.conf:ro \
  -v "$REPO/docker/api/viewer-address.conf":/etc/nginx/viewer-address.conf:ro \
  --entrypoint sh "$IMAGE" -c '
    nginx
    ready=0
    for _ in $(seq 1 50); do
      if wget -q -O /dev/null http://127.0.0.1:8081/ 2>/dev/null; then ready=1; break; fi
      sleep 0.2
    done
    [ "$ready" = 1 ] || { echo "nginx did not become ready" >&2; exit 1; }
    show() { grep -i "x-viewer" | tr -d "\r" | sed "s/^ *//"; }
    wget -q -S -O /dev/null http://127.0.0.1:8081/ 2>&1 | show
    wget -q -S -O /dev/null --header "CloudFront-Viewer-Address: 203.0.113.9:4711" http://127.0.0.1:8081/ 2>&1 | show
    wget -q -S -O /dev/null --header "CloudFront-Viewer-Address:" http://127.0.0.1:8081/ 2>&1 | show
    wget -q -S -O /dev/null --header "CloudFront-Viewer-Address: 203.0.113.1:1" --header "CloudFront-Viewer-Address: 203.0.113.2:2" http://127.0.0.1:8081/ 2>&1 | show
  ')

failures=0
absent="$(printf '%s\n' "$probe" | sed -n 1p)"
present="$(printf '%s\n' "$probe" | sed -n 2p)"
empty="$(printf '%s\n' "$probe" | sed -n 3p)"
duplicated="$(printf '%s\n' "$probe" | sed -n 4p)"

case "$absent" in
  'X-Viewer: [127.0.0.1:'[0-9]*']') ;;
  *) failures=$((failures + 1)); printf 'FAIL absent header: expected the socket address, got: %s\n' "$absent" ;;
esac
if [ "$present" != 'X-Viewer: [203.0.113.9:4711]' ]; then
  failures=$((failures + 1))
  printf 'FAIL present header: expected it passed through, got: %s\n' "$present"
fi
# Observed: an empty header counts as absent (the "" case of the map), so the socket address is used.
case "$empty" in
  'X-Viewer: [127.0.0.1:'[0-9]*']') ;;
  *) failures=$((failures + 1)); printf 'FAIL empty header: expected the socket address, got: %s\n' "$empty" ;;
esac
# Observed: with the header twice, nginx 1.30 joins the values with ", " into ONE string (so PHP sees
# "203.0.113.1:1, 203.0.113.2:2", not a valid host:port). The value is client-controlled free text locally: Phase 1's
# ClientIp must validate the format (a single host:port) and fail closed.
if [ "$duplicated" != 'X-Viewer: [203.0.113.1:1, 203.0.113.2:2]' ]; then
  failures=$((failures + 1))
  printf 'FAIL duplicated header: expected the joined values, got: %s\n' "$duplicated"
fi

# Real nginx.conf: valid, and httpoxy neutralised after the include. api-php must resolve for the test to parse it.
dump=$(docker run --rm \
  --add-host api-php:127.0.0.1 \
  -v "$REPO/docker/api/nginx.conf":/etc/nginx/nginx.conf:ro \
  -v "$REPO/docker/api/viewer-address.conf":/etc/nginx/viewer-address.conf:ro \
  --entrypoint nginx "$IMAGE" -T 2>&1) || { printf 'FAIL nginx -T rejected docker/api/nginx.conf:\n%s\n' "$dump"; exit 1; }
include_line="$(printf '%s\n' "$dump" | grep -n 'include /etc/nginx/fastcgi_params;' | head -n 1 | cut -d: -f1 || true)"
proxy_line="$(printf '%s\n' "$dump" | grep -n 'fastcgi_param HTTP_PROXY "";' | head -n 1 | cut -d: -f1 || true)"
if [ -z "$proxy_line" ]; then
  failures=$((failures + 1))
  echo 'FAIL httpoxy: fastcgi_param HTTP_PROXY "" is missing from docker/api/nginx.conf'
elif [ -z "$include_line" ] || [ "$proxy_line" -le "$include_line" ]; then
  failures=$((failures + 1))
  echo 'FAIL httpoxy: HTTP_PROXY must be set after the fastcgi_params include'
fi

if [ "$failures" -ne 0 ]; then
  exit 1
fi
echo "nginx-viewer-address: ok"
