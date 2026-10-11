#!/usr/bin/env bash
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
[[ $# -ge 1 && $# -le 2 && "$1" =~ ^ghcr.io/roboapi2/self-bot-telegram@sha256:[0-9a-f]{64}$ ]] || exit 1
[[ ${2:-0} =~ ^[0-9]{1,12}$ ]] || exit 1
exec bash /opt/ubcc/scripts/docker/deploy.sh "$@"
