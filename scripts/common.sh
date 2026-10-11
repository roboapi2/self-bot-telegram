#!/usr/bin/env bash
# Shared deployment settings: Ubuntu 24.04 uses PHP 8.3 for CLI and FPM.
PHP_VERSION=8.3
PHP_BIN=/usr/bin/php8.3
require_project_service() {
    [[ -f /etc/systemd/system/userbot-supervisor.service ]] || { echo 'ابتدا نصب VPS را اجرا کنید.' >&2; return 1; }
    grep -Fxq "WorkingDirectory=$ROOT" /etc/systemd/system/userbot-supervisor.service || { echo 'سرویس متعلق به مسیر دیگری است.' >&2; return 1; }
}
