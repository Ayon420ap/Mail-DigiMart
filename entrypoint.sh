#!/bin/sh
set -eu

if [ -z "${BOT_TOKEN:-}" ]; then
  echo "ERROR: BOT_TOKEN is not set"
  exit 1
fi

if [ -n "${WEBHOOK_URL:-}" ]; then
  echo "Setting Telegram webhook: ${WEBHOOK_URL}"
  php /app/set_webhook.php
else
  echo "WEBHOOK_URL is not set; webhook was not changed."
fi

exec php -S 0.0.0.0:${PORT:-10000} -t /app
