#!/usr/bin/env python3
"""Embed an exact committed Engine archive, including its offline source dependencies."""
import hashlib
import io
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile

root = pathlib.Path(__file__).resolve().parent.parent
options = {'--same-source', '--replace-source'}
if (len(sys.argv) not in (3, 4) or re.fullmatch(r'[0-9a-f]{40}', sys.argv[2]) is None
        or (len(sys.argv) == 4 and sys.argv[3] not in options)):
    raise SystemExit('Usage: embed-engine.py ENGINE_REPOSITORY FULL_COMMIT [--same-source|--replace-source]')
source, commit = pathlib.Path(sys.argv[1]).resolve(), sys.argv[2]
mode = sys.argv[3] if len(sys.argv) == 4 else None
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
            entries.append((member.name, tar.extractfile(member).read(), member.mode))
if not entries:
    raise SystemExit('An empty source archive cannot replace the Engine bundle.')
expected = {name: hashlib.sha256(content).hexdigest() for name, content, _ in sorted(entries)}
target = root / 'vendor' / 'engine'
lock_path = root / 'resources' / 'engine-lock.json'
header_path = root / 'php_kumwe_engine_build.h'
if target.is_symlink():
    raise SystemExit('The existing Engine bundle must not be a symbolic link.')
if target.exists():
    if mode is None:
        raise SystemExit('Existing bundle needs --same-source or verified --replace-source.')
    actual = {}
    for path in target.rglob('*'):
        if path.is_symlink() or (not path.is_file() and not path.is_dir()):
            raise SystemExit('Existing Engine bundle contains non-regular entries.')
        if path.is_file():
            actual[str(path.relative_to(target))] = hashlib.sha256(path.read_bytes()).hexdigest()
    previous = json.loads(lock_path.read_text())
    if previous.get('schema') != 'kumwe-embedded-engine/v1' or actual != previous.get('files'):
        raise SystemExit('Existing Engine bundle differs from its reviewed source lock; replacement refused.')
    old_header = header_path.read_text()
    if ('"' + previous.get('commit', '') + '"' not in old_header
            or '"' + previous.get('archive_sha256', '') + '"' not in old_header):
        raise SystemExit('Existing compiled identity differs from its reviewed source lock.')
    if mode == '--same-source' and actual != expected:
        raise SystemExit('Provenance refresh requires an identical existing source tree.')
elif mode is not None:
    raise SystemExit('A replacement option requires an existing verified Engine bundle.')

manifest = {
    'schema': 'kumwe-embedded-engine/v1', 'state': 'candidate',
    'repository': 'https://github.com/kumwe/engine', 'commit': commit,
    'release': None, 'archive_sha256': hashlib.sha256(archive).hexdigest(),
    'files': expected, 'release_verified': False,
}
header = ('#ifndef PHP_KUMWE_ENGINE_BUILD_H\n#define PHP_KUMWE_ENGINE_BUILD_H\n'
          '#define KUMWE_EMBEDDED_ENGINE_COMMIT "' + commit + '"\n'
          '#define KUMWE_EMBEDDED_ENGINE_SHA256 "' + manifest['archive_sha256'] + '"\n#endif\n')
target.parent.mkdir(parents=True, exist_ok=True)
lock_path.parent.mkdir(parents=True, exist_ok=True)
with tempfile.TemporaryDirectory(prefix='.engine-source-', dir=target.parent) as temporary:
    staging = pathlib.Path(temporary)
    bundle = staging / 'bundle'
    bundle.mkdir()
    for name, content, permissions in entries:
        path = bundle / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        path.chmod(permissions)
    (staging / 'lock.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (staging / 'build.h').write_text(header)
    previous_lock = lock_path.read_bytes() if lock_path.exists() else None
    previous_header = header_path.read_bytes() if header_path.exists() else None
    backup = staging / 'previous'
    if target.exists():
        os.replace(target, backup)
    try:
        os.replace(bundle, target)
        os.replace(staging / 'lock.json', lock_path)
        os.replace(staging / 'build.h', header_path)
    except BaseException:
        if target.exists():
            shutil.rmtree(target)
        if backup.exists():
            os.replace(backup, target)
        for path, content in [(lock_path, previous_lock), (header_path, previous_header)]:
            if content is None:
                path.unlink(missing_ok=True)
            else:
                path.write_bytes(content)
        raise
print(f'Embedded {len(entries)} unmodified Engine files from {commit}; candidate only.')
