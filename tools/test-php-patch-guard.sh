#!/usr/bin/env bash
set -euo pipefail
# Test the real MINIT refusal by rebuilding only the binding with a mismatched
# recorded PHP patch. Engine objects and the actual PHP host remain unchanged.
cp kumwe_engine_build_config.h kumwe_engine_build_config.h.verified
restore() {
  mv kumwe_engine_build_config.h.verified kumwe_engine_build_config.h
  touch kumwe_engine_build_config.h
  make -j2 > /dev/null
}
trap restore EXIT
php <<'PHPFIXTURE'
<?php
$path = 'kumwe_engine_build_config.h';
$source = file_get_contents($path);
$source = preg_replace('/^#define KUMWE_BINDING_PHP_VERSION .*$/m', '#define KUMWE_BINDING_PHP_VERSION "8.5.999-mismatch"', $source, -1, $count);
if ($count !== 1 || $source === null) { throw new RuntimeException('Missing unique compiled PHP patch guard'); }
if (file_put_contents($path, $source) === false) { throw new RuntimeException('Cannot write patch guard fixture'); }
PHPFIXTURE
make -j2 > /dev/null
set +e
output=$(php -n -d extension="$PWD/modules/kumwe_engine.so" -r 'if (class_exists("Kumwe\\Engine\\Runtime", false)) { exit(97); }' 2>&1)
status=$?
set -e
if [[ "$status" -eq 97 || "$output" != *"The executing PHP patch differs from the verified binding build"* ]]; then
  printf '%s\n' "$output"
  echo 'A mismatched PHP patch did not refuse actual module startup.' >&2
  exit 1
fi
restore
trap - EXIT
php -n -d extension="$PWD/modules/kumwe_engine.so" tools/consumer.php > /dev/null
echo 'Actual MINIT rejects a mismatched PHP patch; verified module rebuilt and accepted.'
