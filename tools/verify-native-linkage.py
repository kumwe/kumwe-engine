#!/usr/bin/env python3
"""Hold the Linux candidate to its documented system-runtime linkage boundary."""
import pathlib
import re
import subprocess
import sys

module = pathlib.Path(sys.argv[1]) if len(sys.argv) == 2 else pathlib.Path(__file__).resolve().parent.parent / 'modules/kumwe_engine.so'
if len(sys.argv) > 2 or not module.is_file():
    raise SystemExit('Usage: verify-native-linkage.py [BUILT_MODULE]')
dynamic = subprocess.check_output(['readelf', '-d', str(module)], text=True)
needed = set(re.findall(r'Shared library: \[([^]]+)\]', dynamic))
allowed = {'libstdc++.so.6', 'libm.so.6', 'libgcc_s.so.1', 'libc.so.6', 'libpthread.so.0', 'librt.so.1'}
if not needed or needed - allowed:
    raise SystemExit('Unexpected extension shared-library dependencies: ' + ', '.join(sorted(needed - allowed)))
exports = subprocess.check_output(['nm', '-D', '--defined-only', str(module)], text=True)
if re.search(r'\bpcre2_[A-Za-z0-9_]+', exports):
    raise SystemExit('Vendored PCRE2 internals must remain hidden inside the native module.')
print('Native module uses only documented system runtimes: ' + ', '.join(sorted(needed)))
