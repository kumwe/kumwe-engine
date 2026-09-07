#!/usr/bin/env bash
set -euo pipefail
php tools/verify-engine.php
"${KUMWE_PIE_PATH:?}" install --no-interaction --no-cache
php tools/consumer.php
