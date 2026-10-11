#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
umask 077
command -v php >/dev/null || { echo 'PHP CLI لازم است؛ راهنمای نصب را بخوانید.' >&2; exit 1; }
command -v composer >/dev/null || { echo 'Composer لازم است؛ راهنمای نصب را بخوانید.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80200 && PHP_INT_SIZE === 8 ? 0 : 1);'
mkdir -p accounts
[[ -f accounts.json ]] || printf '[]\n' > accounts.json
[[ -f config.php ]] || cp -n config.example.php config.php
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
composer check-platform-reqs --no-dev
bash scripts/test.sh
echo 'config.php را تنظیم کنید؛ برای Ubuntu فرمان sudo bash scripts/install-vps.sh را اجرا کنید.'
