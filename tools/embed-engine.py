#!/usr/bin/env python3
"""Embed one committed Engine source archive without altering its owned files."""
import hashlib
import io
import json
import pathlib
import re
import subprocess
import sys
import tarfile

root = pathlib.Path(__file__).resolve().parent.parent
if len(sys.argv) != 3 or re.fullmatch(r'[0-9a-f]{40}', sys.argv[2]) is None:
    raise SystemExit('Usage: embed-engine.py ENGINE_REPOSITORY FULL_COMMIT')
source, commit = pathlib.Path(sys.argv[1]).resolve(), sys.argv[2]
if subprocess.check_output(['git', '-C', str(source), 'rev-parse', commit], text=True).strip() != commit:
    raise SystemExit('A complete committed source identity is required.')
archive = subprocess.check_output(['git', '-C', str(source), 'archive', '--format=tar', commit])
entries = []
with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
    for member in tar.getmembers():
        path = pathlib.PurePosixPath(member.name)
        if path.is_absolute() or '..' in path.parts or (not member.isfile() and not member.isdir()):
            raise SystemExit('Source archive must contain only regular relative files/directories.')
        if member.isfile():
            content = tar.extractfile(member).read()
            entries.append((member.name, content, member.mode))
target = root / 'vendor' / 'engine'
if target.exists():
    raise SystemExit('Existing Engine bundle must be reviewed and removed explicitly before replacement.')
for name, content, mode in entries:
    path = target / name
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(content)
    path.chmod(mode)
manifest = {
    'schema': 'kumwe-embedded-engine/v1', 'state': 'candidate',
    'repository': 'https://github.com/kumwe/engine', 'commit': commit,
    'release': None, 'archive_sha256': hashlib.sha256(archive).hexdigest(),
    'files': {name: hashlib.sha256(content).hexdigest() for name, content, mode in sorted(entries)},
    'release_verified': False,
}
(root / 'resources' / 'engine-lock.json').write_text(json.dumps(manifest, indent=2) + '\n')
(root / 'php_kumwe_engine_build.h').write_text(
    '#ifndef PHP_KUMWE_ENGINE_BUILD_H\n#define PHP_KUMWE_ENGINE_BUILD_H\n'
    '#define KUMWE_EMBEDDED_ENGINE_COMMIT "' + commit + '"\n'
    '#define KUMWE_EMBEDDED_ENGINE_SHA256 "' + manifest['archive_sha256'] + '"\n#endif\n')
print(f'Embedded {len(entries)} unmodified Engine files from {commit}; candidate only.')
