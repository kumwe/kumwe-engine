#!/usr/bin/env python3
"""Exercise verified replacement with isolated source repositories and no network access."""
import hashlib
import json
import pathlib
import shutil
import subprocess
import sys
import tempfile

script = pathlib.Path(__file__).with_name('embed-engine.py')
checks = 0
with tempfile.TemporaryDirectory(prefix='kumwe-source-replacement-') as temporary:
    workspace = pathlib.Path(temporary)
    engine = workspace / 'engine'
    binding = workspace / 'binding'
    (binding / 'tools').mkdir(parents=True)
    shutil.copy2(script, binding / 'tools/embed-engine.py')
    engine.mkdir()
    def git(*args):
        return subprocess.check_output(['git', '-C', str(engine), *args], text=True).strip()
    git('init', '-q')
    git('config', 'user.name', 'Source fixture')
    git('config', 'user.email', 'source-fixture@example.invalid')
    def commit(message):
        git('add', '.')
        git('commit', '-qm', message)
        return git('rev-parse', 'HEAD')
    def embed(sha, option=None, succeeds=True):
        global checks
        command = [sys.executable, str(binding / 'tools/embed-engine.py'), str(engine), sha]
        if option:
            command.append(option)
        result = subprocess.run(command, text=True, capture_output=True)
        if (result.returncode == 0) != succeeds:
            raise AssertionError(result.stdout + result.stderr)
        checks += 1
    (engine / 'old.txt').write_text('old source\n')
    first = commit('first source')
    embed(first)
    bundle = binding / 'vendor/engine'
    original_lock = (binding / 'resources/engine-lock.json').read_bytes()
    (engine / 'old.txt').unlink()
    (engine / 'src').mkdir()
    (engine / 'src/engine.c').write_text('new Engine source\n')
    (engine / 'third_party/pcre2').mkdir(parents=True)
    dependency = b'Unmodified source dependency and license bytes.\n'
    (engine / 'third_party/pcre2/LICENCE').write_bytes(dependency)
    second = commit('complete new offline source closure')
    embed(second, succeeds=False)
    embed(second, '--same-source', succeeds=False)
    (bundle / 'old.txt').write_text('unreviewed edit\n')
    embed(second, '--replace-source', succeeds=False)
    if (binding / 'resources/engine-lock.json').read_bytes() != original_lock:
        raise AssertionError('A refusal changed the old source lock.')
    (bundle / 'old.txt').write_text('old source\n')
    (bundle / 'unexpected.txt').write_text('untracked work\n')
    embed(second, '--replace-source', succeeds=False)
    (bundle / 'unexpected.txt').unlink()
    (bundle / 'link').symlink_to('old.txt')
    embed(second, '--replace-source', succeeds=False)
    (bundle / 'link').unlink()
    embed(second, '--replace-source')
    lock = json.loads((binding / 'resources/engine-lock.json').read_text())
    assert lock['commit'] == second and not lock['release_verified']
    assert not (bundle / 'old.txt').exists()
    assert (bundle / 'third_party/pcre2/LICENCE').read_bytes() == dependency
    assert lock['files']['third_party/pcre2/LICENCE'] == hashlib.sha256(dependency).hexdigest()
    git('commit', '--allow-empty', '-qm', 'reachable same-tree identity')
    same = git('rev-parse', 'HEAD')
    embed(same, '--same-source')
    (engine / 'link').symlink_to('src/engine.c')
    linked = commit('invalid source shape')
    embed(linked, '--replace-source', succeeds=False)
    assert json.loads((binding / 'resources/engine-lock.json').read_text())['commit'] == same
print(f'{checks} isolated source embedding admission/replacement cases passed; offline dependency bytes preserved.')
