#!/usr/bin/env bash
# CI-only helpers. Progress goes to stderr so command substitutions stay machine-readable.
ci_progress() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; }
ci_require() {
    local command
    for command in "$@"; do
        command -v "$command" >/dev/null || { ci_progress "ERROR required command not found: $command"; return 1; }
    done
}
ci_run() {
    local seconds=$1 label=$2 status=0
    shift 2
    # Docker daemon containers outlive a timed-out CLI. Label all CI one-offs
    # so the workflow's always() cleanup can find them after any failed gate.
    if [[ ${1:-} == docker && ${2:-} == run && -n ${CI_DOCKER_PROJECT:-} ]]; then
        [[ "$CI_DOCKER_PROJECT" =~ ^ubcc-ci-[0-9]+-[0-9]+$ ]] || return 1
        set -- docker run --label "com.docker.compose.project=$CI_DOCKER_PROJECT" "${@:3}"
    fi
    ci_progress "START $label (limit ${seconds}s)"
    timeout --signal=TERM --kill-after="${CI_KILL_AFTER:-5}s" "${seconds}s" "$@" || status=$?
    ci_progress "END $label (exit $status)"
    return "$status"
}
