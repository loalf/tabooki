#!/usr/bin/env bash
# Run on the server: saves a Resend API key as Tabooki's mail settings, restarts Tabooki and,
# given an address, sends it a test email.
#
#   ssh -t demo.rootware.xyz /opt/tabooki/deploy/set-resend-key.sh you@example.com
#
# The key is read without echo and never passed as a command argument, so it stays out of the
# shell history and the process list.
set -euo pipefail

cd "$(dirname "$0")/.."
compose=(docker compose -f compose.yaml -f compose.prod.yaml)
from='no-reply@tabooki.xyz'

if [[ ! -f .env ]]; then
    echo "No $PWD/.env: set Tabooki up first (docs/DEPLOY.md)." >&2
    exit 1
fi

read -rsp 'Resend API key (re_…, hidden): ' key
echo
if [[ ! $key =~ ^re_[A-Za-z0-9_-]+$ ]]; then
    echo "That doesn't look like a Resend API key (re_…). Nothing changed." >&2
    exit 1
fi

# Replace any earlier mail settings, keeping everything else (and mode 600).
tmp=$(mktemp .env.XXXXXX)
chmod 600 "$tmp"
grep -vE '^(MAILER_DSN|MAILER_FROM)=' .env >"$tmp" || true
printf 'MAILER_DSN=smtp://resend:%s@smtp.resend.com:587\nMAILER_FROM="Tabooki <%s>"\n' "$key" "$from" >>"$tmp"
mv "$tmp" .env
unset key
echo 'Saved MAILER_DSN and MAILER_FROM in .env.'

# Recreates php and worker with the new settings (no rebuild).
"${compose[@]}" up -d --no-build --wait

if [[ $# -gt 0 ]]; then
    "${compose[@]}" exec -T php php bin/console mailer:test "$1" --from="$from" --subject='Tabooki: email works' --body='Password reset emails will arrive like this one.'
    echo "Test email sent to $1."
fi
