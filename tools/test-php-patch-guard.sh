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
python3 - <<'PYFIXTURE'
from pathlib import Path
p = Path('kumwe_engine_build_config.h')
s = p.read_text()
import re
s, count = re.subn(r'^#define KUMWE_BINDING_PHP_VERSION .*$', '#define KUMWE_BINDING_PHP_VERSION "8.5.999-mismatch"', s, flags=re.M)
if count != 1:
    raise SystemExit('Missing unique compiled PHP patch guard')
p.write_text(s)
PYFIXTURE
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
