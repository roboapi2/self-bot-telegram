#!/usr/bin/env bash
# Only disposable CI projects; never a production Compose project or data volume.
set -euo pipefail
source "$(dirname -- "${BASH_SOURCE[0]}")/ci-common.sh"
PROJECT=${1:?CI project required}
[[ "$PROJECT" =~ ^ubcc-ci-[0-9]+-[0-9]+$ ]] || { echo 'Refusing non-CI project' >&2; exit 1; }
ids=$(ci_run 5 'find leftover CI containers' docker ps --all --quiet --filter "label=com.docker.compose.project=$PROJECT")
if [[ -n "$ids" ]]; then
    mapfile -t containers <<< "$ids"
    ci_run 10 'force-remove leftover CI containers' docker rm --force "${containers[@]}"
fi
networks=$(ci_run 5 'find CI networks' docker network ls --quiet --filter "label=com.docker.compose.project=$PROJECT")
if [[ -n "$networks" ]]; then
    mapfile -t network_ids <<< "$networks"
    ci_run 5 'remove CI networks' docker network rm "${network_ids[@]}"
fi
remaining=$(ci_run 5 'verify no orphan CI containers' docker ps --all --quiet --filter "label=com.docker.compose.project=$PROJECT")
[[ -z "$remaining" ]] || { echo 'CI containers remain; cleanup failed' >&2; exit 1; }
