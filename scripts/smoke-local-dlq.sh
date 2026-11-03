#!/usr/bin/env bash
# Prints the number of messages in the LOCAL dead-letter queue (ElasticMQ on 127.0.0.1:9324), for `make smoke` (plan
# phase 6, D21): the smoke run must not make that number grow. One fixed, unsigned request; nothing else is asked.
# `make test-integration` leaves a poison message there on purpose, so the number is compared before and after, never
# against zero. Exit: 0 the number is printed; 1 ElasticMQ did not answer or the answer is not understood.
set -euo pipefail

readonly DLQ_URL='http://127.0.0.1:9324/000000000000/receipts-dlq?Action=GetQueueAttributes&AttributeName.1=ApproximateNumberOfMessages'

# -q: no ~/.curlrc. --noproxy '*': this address is local, never through a proxy.
answer="$(curl -q -sS --max-time 10 --max-redirs 0 --proto =http --noproxy '*' "$DLQ_URL")" || {
  echo "smoke-local-dlq: ElasticMQ did not answer on 127.0.0.1:9324 (is the stack up? run 'make up')." >&2
  exit 1
}
flat="$(printf '%s' "$answer" | tr -d '\n\r')"
if [[ $flat =~ \<Name\>ApproximateNumberOfMessages\</Name\>[[:space:]]*\<Value\>([0-9]+)\</Value\> ]]; then
  printf '%s\n' "${BASH_REMATCH[1]}"
else
  echo "smoke-local-dlq: the answer holds no ApproximateNumberOfMessages." >&2
  exit 1
fi
