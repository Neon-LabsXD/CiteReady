#!/usr/bin/env bash
# Деплой CiteReady: public/ -> webroot, src/ і config/ -> поза webroot.
# storage/ на сервері не чіпаємо (кеш і rate-limit).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
WEBROOT="/var/www/html"
APPROOT="/var/www/citeready"

for f in $(find "$ROOT/public" "$ROOT/src" "$ROOT/config" -name '*.php'); do
  php -l "$f" >/dev/null
done

rsync -a --delete --exclude 'index.nginx-debian.html' "$ROOT/public/" "$WEBROOT/"
rsync -a --delete "$ROOT/src/" "$APPROOT/src/"
rsync -a --delete "$ROOT/config/" "$APPROOT/config/"

echo "Deployed: https://ws-109.ws.semalt.dev"
