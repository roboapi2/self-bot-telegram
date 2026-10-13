#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
unset UBCC_PROCESS_MODE UBCC_PHP_BIN UBCC_ADMIN_PASSWORD UBCC_API_ID UBCC_API_HASH
"${TEST_PHP:-php}" scripts/patch-madeline.php
for file in ./*.php tests/*.php scripts/*.php docker/*.php; do "${TEST_PHP:-php}" -l "$file"; done
for file in ./*.sh scripts/*.sh scripts/docker/*.sh docker/*.sh; do bash -n "$file"; done
"${TEST_PHP:-php}" tests/unit.php
"${TEST_PHP:-php}" tests/concurrency.php
"${TEST_PHP:-php}" tests/migration.php
"${TEST_PHP:-php}" tests/docker_config.php
"${TEST_PHP:-php}" tests/madeline_logger.php
"${TEST_PHP:-php}" tests/bot_contract.php
"${TEST_PHP:-php}" tests/linux_lifecycle.php
"${TEST_PYTHON:-python3}" tests/security_http.py
echo 'Source and HTTP security tests passed.'
