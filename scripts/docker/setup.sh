#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $EUID -eq 0 && $# -eq 2 ]] || { echo 'sudo bash scripts/docker/setup.sh DOMAIN ACME_EMAIL' >&2; exit 1; }
DOMAIN=$1; EMAIL=$2
[[ "$DOMAIN" =~ ^[A-Za-z0-9][A-Za-z0-9.-]+$ && "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$ ]]
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 24.04 && $(dpkg --print-architecture) == amd64 ]]
SOURCE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
ROOT=/opt/ubcc
[[ ! -L "$ROOT" ]]
apt-get update
apt-get install -y ca-certificates curl gnupg openssh-server python3 rsync sudo
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
printf 'deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu noble stable\n' > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
install -d -m 0700 "$ROOT" "$ROOT/secrets" "$ROOT/backups" "$ROOT/state" "$ROOT/scripts/docker" "$ROOT/docker"
install -d -m 0700 -o 10001 -g 10001 "$ROOT/data" "$ROOT/data/accounts" "$ROOT/data/panel" "$ROOT/data/panel/sessions" "$ROOT/data/panel/throttle" "$ROOT/data/caddy" "$ROOT/data/caddy-config"
if [[ -f "$ROOT/compose.production.yml" ]]; then
    echo 'نصب قبلی حفظ شد؛ به‌روزرسانی ابزار میزبان باید بعد از بازبینی انجام شود.'
else
    install -m 0600 "$SOURCE/compose.production.yml" "$ROOT/compose.production.yml"
    install -m 0600 "$SOURCE/docker/Caddyfile" "$ROOT/docker/Caddyfile"
    install -m 0700 "$SOURCE"/scripts/docker/*.sh "$ROOT/scripts/docker/"
    printf 'UBCC_ROOT=/opt/ubcc\nDOMAIN=%s\nACME_EMAIL=%s\n' "$DOMAIN" "$EMAIL" > "$ROOT/settings.env"
fi
[[ -f "$ROOT/secrets/config.php" ]] || printf '<?php return [];\n' > "$ROOT/secrets/config.php"
[[ -f "$ROOT/secrets/app.env" ]] || printf '# Optional UBCC_API_ID/UBCC_API_HASH/UBCC_ADMIN_PASSWORD environment values\n' > "$ROOT/secrets/app.env"
chown 10001:10001 "$ROOT/secrets/config.php" "$ROOT/secrets/app.env"
chmod 0400 "$ROOT/secrets/config.php" "$ROOT/secrets/app.env"
id ubcc-deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash ubcc-deploy
install -m 0755 "$SOURCE/scripts/docker/ssh-deploy.sh" /usr/local/sbin/ubcc-deploy
printf 'ubcc-deploy ALL=(root) NOPASSWD: /usr/local/sbin/ubcc-deploy\n' > /etc/sudoers.d/ubcc-deploy
chmod 0440 /etc/sudoers.d/ubcc-deploy
visudo -cf /etc/sudoers.d/ubcc-deploy
install -m 0644 "$SOURCE/docker/ubcc-docker.service" /etc/systemd/system/ubcc-docker.service
systemctl daemon-reload
systemctl enable ubcc-docker.service
echo 'کلید SSH را نصب، config خصوصی و DNS را تنظیم و اولین digest را deploy کنید؛ راهنمای Docker را بخوانید.'
