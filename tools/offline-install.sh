#!/usr/bin/env bash
set -euo pipefail
php tools/verify-engine.php
"${KUMWE_PIE_PATH:?}" repository:add path "$PWD" --no-interaction
"${KUMWE_PIE_PATH:?}" install "kumwe/kumwe-engine:*@dev" --no-interaction --no-cache
php tools/consumer.php
