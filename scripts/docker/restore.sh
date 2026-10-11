#!/usr/bin/env bash
source /opt/ubcc/scripts/docker/common.sh
[[ $# -eq 1 && -f "$1" ]] || { echo 'مسیر پشتیبان خصوصی لازم است.' >&2; exit 1; }
ARCHIVE=$(realpath -- "$1")
lock; load_current
STAGE=$(mktemp -d /var/tmp/ubcc-docker-restore.XXXXXXXX)
trap 'rm -rf -- "$STAGE"' EXIT
python3 - "$ARCHIVE" "$STAGE" <<'PY'
import pathlib,sys,tarfile
root=pathlib.Path(sys.argv[2]).resolve()
with tarfile.open(sys.argv[1]) as archive:
    for member in archive.getmembers():
        target=(root/member.name).resolve()
        if not target.is_relative_to(root) or not (member.isfile() or member.isdir()):
            raise SystemExit('Unsafe backup; nothing restored')
    archive.extractall(root,filter='data')
PY
[[ -f "$STAGE/data/panel/accounts.json" && -d "$STAGE/data/accounts" ]] || exit 1
dc stop
snapshot
# Explicit restore replaces DATA only; current config, images and certificates remain.
rsync -a --delete "$STAGE/data/accounts/" "$ROOT/data/accounts/"
rsync -a --delete "$STAGE/data/panel/" "$ROOT/data/panel/"
chown -R 10001:10001 "$ROOT/data/accounts" "$ROOT/data/panel"
dc up -d --force-recreate --wait --wait-timeout 240
health
