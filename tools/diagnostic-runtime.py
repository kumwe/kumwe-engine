#!/usr/bin/env python3
"""Capture CI-installed PHP for diagnostics; verify all bytes before optional activation."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tempfile

SCHEMA = "kumwe-php-diagnostic-runtime/v1"
MODULES = ("pdo", "ctype", "tokenizer", "phar", "fileinfo", "iconv", "mbstring",
           "intl", "curl", "dom", "simplexml", "xml", "xmlreader", "xmlwriter", "zip", "sodium")
MAX_FILES = 1024
MAX_BYTES = 512 * 1024 * 1024
ROOT = Path(__file__).resolve().parent.parent


class Invalid(ValueError):
    """An incomplete, unsafe or mismatched diagnostic fixture."""


def run(*args):
    result = subprocess.run(args, check=False, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            env={**os.environ, "LC_ALL": "C"})
    if result.returncode:
        raise Invalid("Command failed: " + args[0] + ": " +
                      result.stderr.decode(errors="replace").strip())
    return result.stdout.decode()


def sha(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def document(value):
    return (json.dumps(value, indent=2, sort_keys=True) + "\n").encode()


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise Invalid("Duplicate JSON object key.")
        result[key] = value
    return result


def regular(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise Invalid("Expected a regular, unlinked file: " + str(path))
    return info


def directory(path):
    path = Path(os.path.abspath(path))
    for component in [*reversed(path.parents), path]:
        if not stat.S_ISDIR(component.lstat().st_mode):
            raise Invalid("Directory path contains a symlink or non-directory: " + str(component))
    return path


def relative(name):
    if (not isinstance(name, str) or not name or len(name) > 240
            or not re.fullmatch(r"[A-Za-z0-9._+/-]+", name)
            or PurePosixPath(name).is_absolute()
            or any(part in ("", ".", "..") for part in name.split("/"))
            or str(PurePosixPath(name)) != name):
        raise Invalid("Unsafe fixture path.")
    return name


def closure(root):
    files, dirs = {}, set()
    def walk_error(error):
        raise error
    for current, children, names in os.walk(root, followlinks=False, onerror=walk_error):
        for name in children:
            path = Path(current) / name
            if not stat.S_ISDIR(path.lstat().st_mode):
                raise Invalid("Fixture contains a directory symlink or special entry.")
            dirs.add(relative(path.relative_to(root).as_posix()))
        for name in names:
            path = Path(current) / name
            regular(path)
            files[relative(path.relative_to(root).as_posix())] = path
        if len(files) + len(dirs) > MAX_FILES:
            raise Invalid("Fixture file closure exceeds its bound.")
    return files, dirs


def verify(path, expected_commit, activate=False):
    if not isinstance(expected_commit, str) or re.fullmatch(r"[a-f0-9]{40}", expected_commit) is None:
        raise Invalid("Verification requires an independently supplied full --expected-commit.")
    root = directory(path)
    manifest = root / "fixture.json"
    if regular(manifest).st_size > 2 * 1024 * 1024:
        raise Invalid("Oversized diagnostic manifest.")
    data = json.loads(manifest.read_bytes(), object_pairs_hook=unique_object)
    if (not isinstance(data, dict) or data.get("schema") != SCHEMA
            or not isinstance(data.get("source"), dict)
            or data["source"].get("repository") != "https://github.com/kumwe/kumwe-engine"
            or data["source"].get("commit") != expected_commit
            or data.get("release_attestation") is not False
            or data.get("purpose") != "diagnostic-only"):
        raise Invalid("Diagnostic source identity or purpose differs from the expected CI artifact.")
    facts = data.get("php")
    if (not isinstance(facts, dict) or not isinstance(facts.get("version"), str)
            or re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+[A-Za-z0-9.+-]*", facts["version"]) is None
            or data.get("required_extensions") != list(MODULES)):
        raise Invalid("Invalid PHP facts or required module inventory.")
    recorded = data.get("files")
    expected_dirs = data.get("directories")
    if (not isinstance(recorded, dict) or not recorded or len(recorded) > MAX_FILES
            or not isinstance(expected_dirs, list)
            or any(not isinstance(name, str) for name in expected_dirs)
            or len(set(expected_dirs)) != len(expected_dirs)):
        raise Invalid("Invalid file closure inventory.")
    if "fixture.json" in recorded:
        raise Invalid("Manifest must not recursively inventory itself.")
    for name in expected_dirs:
        relative(name)
    actual, dirs = closure(root)
    if set(actual) != set(recorded) | {"fixture.json"} or dirs != set(expected_dirs):
        raise Invalid("Fixture contains missing or unrecorded files/directories.")
    if not {"bin/php", "runtime/php", "php.ini"} <= set(recorded):
        raise Invalid("Fixture omits its runtime entry points.")
    loader = relative(data.get("loader", ""))
    if not loader.startswith("lib/") or loader not in recorded:
        raise Invalid("Fixture omits its captured loader.")
    checked = []
    total = 0
    for name, item in recorded.items():
        relative(name)
        if (not isinstance(item, dict) or type(item.get("bytes")) is not int
                or item["bytes"] < 0 or item["bytes"] > MAX_BYTES
                or type(item.get("mode")) is not int or item["mode"] not in (0o644, 0o755)
                or not isinstance(item.get("sha256"), str)
                or re.fullmatch(r"[a-f0-9]{64}", item["sha256"]) is None):
            raise Invalid("Invalid file size, mode or digest: " + name)
        total += item["bytes"]
        if total > MAX_BYTES:
            raise Invalid("Fixture byte closure exceeds its bound.")
        info = regular(actual[name])
        if info.st_size != item["bytes"] or sha(actual[name]) != item["sha256"]:
            raise Invalid("Fixture byte identity mismatch: " + name)
        checked.append((actual[name], item["mode"], info))
    # ZIP downloads normalize executable bits. Activation is explicit, after all
    # bytes/paths pass. Use a private directory with no concurrent mutation.
    if activate:
        for file, mode, previous in checked:
            fd = os.open(file, os.O_RDONLY | os.O_NOFOLLOW)
            try:
                current = os.fstat(fd)
                if (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns) != (
                        previous.st_dev, previous.st_ino, previous.st_size, previous.st_mtime_ns):
                    raise Invalid("Fixture changed between verification and activation.")
                os.fchmod(fd, mode)
            finally:
                os.close(fd)
    return data


def package_for(path):
    paths = [str(path), str(path.resolve())]
    for name in list(paths):
        if name.startswith("/usr/lib/") or name.startswith("/usr/bin/"):
            paths.append(name.removeprefix("/usr"))
        elif name.startswith("/lib/"):
            paths.append("/usr" + name)
    for name in dict.fromkeys(paths):
        result = subprocess.run(["dpkg-query", "-S", name], stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, text=True, check=False)
        if result.returncode:
            continue
        for line in result.stdout.splitlines():
            owners, separator, owned_path = line.rpartition(": ")
            if separator and owned_path == name and not owners.startswith("diversion"):
                owner = owners.split(", ")[0]
                if re.fullmatch(r"[A-Za-z0-9.+:-]+", owner):
                    return owner
    raise Invalid("No installed package attribution for captured file: " + str(path))


def libraries(path):
    output = run("ldd", str(path))
    if "not found" in output:
        raise Invalid("Unresolved runtime ELF dependency: " + str(path))
    result = {}
    for line in output.splitlines():
        if not line.strip() or re.match(r"\s*linux-vdso\S* \(0x[0-9a-f]+\)", line):
            continue
        match = re.fullmatch(r"\s*(?:(\S+) => )?(/\S+) \(0x[0-9a-f]+\)\s*", line)
        if match is None:
            raise Invalid("Unrecognized ldd dependency: " + line)
        name = match[1] or Path(match[2]).name
        if not re.fullmatch(r"[A-Za-z0-9._+-]+", name):
            raise Invalid("Unsafe library soname.")
        source = Path(match[2])
        if name in result and sha(source) != sha(result[name]):
            raise Invalid("Conflicting library resolution.")
        result[name] = source
    return result


def capture(destination, php, module=None):
    destination = Path(os.path.abspath(destination))
    if destination.exists() or destination.is_symlink():
        raise Invalid("Refusing to overwrite a diagnostic fixture.")
    destination.parent.mkdir(parents=True, exist_ok=True)
    directory(destination.parent)
    if destination == ROOT or ROOT in destination.parents:
        raise Invalid("Diagnostic binaries must be captured outside the repository.")
    commit = run("git", "-C", str(ROOT), "rev-parse", "HEAD").strip()
    if run("git", "-C", str(ROOT), "status", "--porcelain", "--untracked-files=no").strip():
        raise Invalid("Capture requires committed, unchanged tracked source.")
    executable = Path(shutil.which(php) or php).resolve(strict=True)
    facts = json.loads(run(str(executable), "-n", "-r",
        'echo json_encode(["version"=>PHP_VERSION,"version_id"=>PHP_VERSION_ID,'
        '"int_size"=>PHP_INT_SIZE,"zts"=>PHP_ZTS,"debug"=>PHP_DEBUG,'
        '"sapi"=>PHP_SAPI,"os_family"=>PHP_OS_FAMILY,"architecture"=>php_uname("m"),'
        '"extension_dir"=>ini_get("extension_dir"),'
        '"builtin_extensions"=>get_loaded_extensions()], JSON_THROW_ON_ERROR);'))
    if (facts["sapi"] != "cli" or facts["os_family"] != "Linux"
            or not 80500 <= facts["version_id"] < 80600 or facts["int_size"] != 8
            or facts["zts"] or facts["debug"]):
        raise Invalid("Capture requires the installed PHP 8.5 Linux 64-bit NTS non-debug CLI.")
    interpreter = re.search(r"\[Requesting program interpreter: ([^\]]+)\]",
                            run("readelf", "-l", str(executable)))
    if interpreter is None:
        raise Invalid("PHP does not identify its ELF dynamic loader.")
    loader_source = Path(interpreter[1])
    loader_name = relative("lib/" + loader_source.name)
    if not loader_source.is_absolute():
        raise Invalid("Invalid ELF loader.")
    builtin = {name.lower() for name in facts["builtin_extensions"]}
    modules = {name: Path(facts["extension_dir"]) / (name + ".so")
               for name in MODULES if name not in builtin}
    resolved = {}
    dependency_roots = [executable, *modules.values()]
    if module is not None:
        dependency_roots.append(module.resolve(strict=True))
    for path in dependency_roots:
        if not path.is_file():
            raise Invalid("Curated PHP module is not installed: " + str(path))
        for name, source in libraries(path).items():
            if name in resolved and sha(source) != sha(resolved[name]):
                raise Invalid("Different libraries share a captured soname: " + name)
            resolved[name] = source
    with tempfile.TemporaryDirectory(prefix=".php-diagnostic-", dir=destination.parent) as temporary:
        root = Path(temporary)
        origins, owners = {}, set()
        def copy(source, name, attributed=True):
            relative(name)
            target = root / name
            source = Path(source)
            if not source.resolve().is_file():
                raise Invalid("Captured dependency is not a regular file: " + str(source))
            if target.exists():
                if sha(target) != sha(source):
                    raise Invalid("Captured filenames collide: " + name)
                return
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target, follow_symlinks=True)
            target.chmod(0o644)
            owner = package_for(source) if attributed else None
            origins[name] = {"path": str(source), "resolved_path": str(source.resolve()),
                             "package": owner}
            if owner:
                owners.add(owner)
        copy(executable, "runtime/php")
        copy(loader_source, loader_name)
        for name, source in sorted(resolved.items()):
            copy(source, "lib/" + name)
        for name, source in modules.items():
            copy(source, "extensions/" + name + ".so")
        package_inventory = []
        query_format = "-f=" + "\t".join("$" + "{" + name + "}" for name in
            ("binary:Package", "Version", "Architecture", "source:Package", "source:Version"))
        for owner in sorted(owners):
            fields = run("dpkg-query", "-W", query_format, owner).split("\t")
            if len(fields) != 5:
                raise Invalid("Incomplete installed package version record.")
            copyright_file = Path("/usr/share/doc") / owner.split(":")[0] / "copyright"
            if not copyright_file.is_file():
                raise Invalid("Installed package lacks copyright material: " + owner)
            target = "licenses/packages/" + owner.replace(":", "_") + "/copyright"
            copy(copyright_file, target, attributed=False)
            package_inventory.append(dict(zip(
                ("package", "version", "architecture", "source_package", "source_version"), fields))
                | {"copyright": target})
        common = Path("/usr/share/common-licenses")
        if not common.is_dir():
            raise Invalid("Installed common license texts are unavailable.")
        for path in sorted(common.iterdir()):
            if path.is_file():
                copy(path, "licenses/common/" + path.name, attributed=False)
        (root / "bin").mkdir()
        (root / "empty-ini").mkdir()
        (root / "empty-ini/README.txt").write_text("No scanned PHP configuration files.\n")
        (root / "extensions").mkdir(exist_ok=True)
        if not modules:
            (root / "extensions/README.txt").write_text("All curated extensions are built in.\n")
        wrapper = (
            '#!/bin/sh\nset -eu\n'
            'fixture_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)\n'
            'export KUMWE_DIAGNOSTIC_ROOT="$fixture_root"\n'
            'export PHPRC="$fixture_root/php.ini"\n'
            'export PHP_INI_SCAN_DIR="$fixture_root/empty-ini"\n'
            'unset LD_PRELOAD LD_AUDIT LD_LIBRARY_PATH\n'
            'exec "$fixture_root/' + loader_name + '" --inhibit-cache '
            '--library-path "$fixture_root/lib" "$fixture_root/runtime/php" "$@"\n')
        (root / "bin/php").write_text(wrapper)
        (root / "php.ini").write_text(
            '; Diagnostic CI host; no kumwe_engine module is loaded by default.\n'
            'date.timezone=UTC\n'
            'extension_dir="' + "$" + '{KUMWE_DIAGNOSTIC_ROOT}/extensions"\n' +
            "".join("extension=" + name + ".so\n" for name in modules))
        for name in ("bin/php", "runtime/php", loader_name):
            (root / name).chmod(0o755)
        files, dirs = closure(root)
        record = {
            "schema": SCHEMA, "purpose": "diagnostic-only", "release_attestation": False,
            "source": {"repository": "https://github.com/kumwe/kumwe-engine", "commit": commit},
            "php": facts, "required_extensions": list(MODULES), "loader": loader_name,
            "packages": package_inventory, "origins": origins,
            "native_module_probe": None if module is None else
                {"name": module.name, "sha256": sha(module), "included": False},
            "host_resources": ["Linux kernel, /proc and /dev", "Host timezone database",
                               "Host DNS configuration and CA certificates when networking is used"],
            "trust": "Obtain from the trusted CI run for the independently expected commit. "
                     "The manifest checks file consistency; it is not an artifact signature.",
            "directories": sorted(dirs),
            "files": {name: {"sha256": sha(path), "bytes": path.stat().st_size,
                             "mode": stat.S_IMODE(path.stat().st_mode)}
                      for name, path in sorted(files.items())},
        }
        (root / "fixture.json").write_bytes(document(record))
        verify(root, commit)
        if run("git", "-C", str(ROOT), "rev-parse", "HEAD").strip() != commit:
            raise Invalid("Source head changed during runtime capture.")
        if run("git", "-C", str(ROOT), "status", "--porcelain", "--untracked-files=no").strip():
            raise Invalid("Tracked source changed during runtime capture.")
        os.replace(root, destination)
    return record


def self_test():
    """Exercise downloaded-file activation and refusals without executing fixture bytes."""
    expected = "a" * 40
    count = 0
    with tempfile.TemporaryDirectory(prefix="php diagnostic verifier ") as temporary:
        base = Path(temporary) / "original"
        for name in ("bin/php", "runtime/php", "php.ini", "lib/ld-linux.so"):
            path = base / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("inert test bytes\\n")
            path.chmod(0o644)
        files, dirs = closure(base)
        data = {"schema": SCHEMA, "purpose": "diagnostic-only", "release_attestation": False,
                "source": {"repository": "https://github.com/kumwe/kumwe-engine", "commit": expected},
                "php": {"version": "8.5.10"}, "required_extensions": list(MODULES),
                "loader": "lib/ld-linux.so", "directories": sorted(dirs),
                "files": {name: {"sha256": sha(path), "bytes": path.stat().st_size,
                                  "mode": 0o644 if name == "php.ini" else 0o755}
                          for name, path in files.items()}}
        (base / "fixture.json").write_bytes(document(data))
        verify(base, expected)
        if stat.S_IMODE((base / "bin/php").stat().st_mode) != 0o644:
            raise Invalid("Read-only verification altered permissions.")
        relocated = Path(temporary) / "relocated download"
        shutil.copytree(base, relocated)
        verify(relocated, expected, activate=True)
        if stat.S_IMODE((relocated / "bin/php").stat().st_mode) != 0o755:
            raise Invalid("Verified activation did not restore executable permission.")
        count += 2
        def refuse(mutate, selected=expected, rewrite=None):
            nonlocal count
            target = Path(temporary) / ("refusal-" + str(count))
            shutil.copytree(base, target)
            if rewrite:
                changed = json.loads((target / "fixture.json").read_bytes())
                rewrite(changed)
                (target / "fixture.json").write_bytes(document(changed))
            mutate(target)
            try:
                verify(target, selected, activate=True)
            except (Invalid, OSError, ValueError):
                count += 1
                # A refusal must occur before changing executable bits.
                entry = target / "bin/php"
                if entry.exists() and not entry.is_symlink() and stat.S_IMODE(entry.stat().st_mode) != 0o644:
                    raise Invalid("Refused fixture was activated.")
                return
            raise Invalid("Verifier admitted a malformed fixture.")
        nothing = lambda path: None
        refuse(nothing, "b" * 40)
        refuse(nothing, None)
        refuse(lambda path: (path / "runtime/php").write_text("corrupt"))
        refuse(lambda path: (path / "extra").write_text("extra"))
        refuse(lambda path: (path / "empty-extra").mkdir())
        refuse(lambda path: (path / "php.ini").unlink())
        refuse(lambda path: (path / "fixture.json").write_bytes(
            b'{"schema":"duplicate",' + (path / "fixture.json").read_bytes()[1:]))
        for wrong in (True, -1, "1"):
            refuse(nothing, rewrite=lambda data, wrong=wrong: data["files"]["php.ini"].update(bytes=wrong))
        for name in ("../escape", "/absolute", "lib//alias", "lib/./alias"):
            refuse(nothing, rewrite=lambda data, name=name: data["files"].update(
                {name: data["files"].pop("php.ini")}))
        refuse(nothing, rewrite=lambda data: data.update(php=None))
        def link_file(path, name):
            file = path / name
            file.unlink()
            file.symlink_to(base / name)
        refuse(lambda path: link_file(path, "runtime/php"))
        refuse(lambda path: link_file(path, "fixture.json"))
        def link_directory(path):
            shutil.rmtree(path / "lib")
            (path / "lib").symlink_to(base / "lib", target_is_directory=True)
        refuse(link_directory)
        link = Path(temporary) / "root-link"
        link.symlink_to(base, target_is_directory=True)
        try:
            verify(link, expected)
        except Invalid:
            count += 1
        else:
            raise Invalid("Symlink fixture root was admitted.")
    print(str(count) + " diagnostic verifier closure/identity/activation checks passed.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("capture", "verify", "self-test"))
    parser.add_argument("directory", type=Path, nargs="?")
    parser.add_argument("--php", default="php", help="trusted CI-installed executable, capture only")
    parser.add_argument("--module", type=Path, help="capture its ELF dependencies, not the module itself")
    parser.add_argument("--expected-commit", help="independently supplied exact commit, verify only")
    parser.add_argument("--activate", action="store_true",
                        help="restore recorded modes only after complete successful verification")
    args = parser.parse_args()
    if args.action == "self-test":
        if args.directory or args.activate or args.module or args.expected_commit:
            raise Invalid("Self-test accepts no fixture options.")
        self_test()
        return
    if args.directory is None:
        raise Invalid("Capture/verify requires a fixture directory.")
    if args.action == "capture":
        if args.activate or args.expected_commit:
            raise Invalid("Capture does not accept verification/activation options.")
        record = capture(args.directory, args.php, args.module)
    else:
        if args.module:
            raise Invalid("Verification never probes executable/module dependencies.")
        record = verify(args.directory, args.expected_commit, args.activate)
    print(("Captured" if args.action == "capture" else "Verified") +
          " diagnostic PHP " + record["php"]["version"] + " for " + record["source"]["commit"])
    print("Diagnostic-only dependency inventory; no release verification or attestation.")


if __name__ == "__main__":
    try:
        main()
    except (Invalid, OSError, ValueError, KeyError, TypeError) as error:
        print("Diagnostic runtime refused: " + str(error), file=sys.stderr)
        raise SystemExit(1) from error
