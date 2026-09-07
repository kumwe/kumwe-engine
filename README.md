# Kumwe Engine PHP binding

`kumwe/kumwe-engine` is the PIE source package for module `kumwe_engine` and Composer platform requirement `ext-kumwe_engine`. This branch is an **unreleased candidate cross-build**, NRM-2026-043. It embeds one exact committed Engine source snapshot; it does not claim an immutable, externally verified Engine release.

The actual extension registers `Kumwe\Engine\Runtime` and `Kumwe\Engine\Exception\BindingFailure`. Its three methods are `capabilities(): array`, `compile(array $envelope): array`, and `execute(array $envelope): array`. Stubs are documentation and are never autoloaded.

A Runtime owns at most 64 immutable native plans, with a 16 MiB aggregate source budget. Compile returns an opaque random `plan_id` and the Engine descriptor. Execute takes `plan_id`, a bounded `batch`, and optional `cancelled`. IDs work only with the Runtime that created them. Objects cannot clone, serialize, gain dynamic properties, or outlive their request. Destroying a Runtime releases all its plans through the Engine ABI.

Canonical `execute` requests carry the GenericV1 profile, corpus digest, operation, original PHP input, and optional semantic limits. The binding only preserves PHP value/key types, raw string bytes and IEEE-754 bits in tagged transport. Sorting, escaping, formatting, limits, finding precedence and digest algorithms execute in Engine. Objects and resources become unsupported tags without invoking callbacks; repeated acyclic references are allowed and cycles terminate at the bounded depth boundary.

## Candidate build

The tested target is PHP 8.5 NTS, Linux x86_64, source installation. Other PHP versions, ZTS, Windows and other architectures are refused. CMake 3.25+, a C11/C++20 compiler, PHP development headers/phpize and make must already be provisioned.

```sh
php tools/verify-engine.php
phpize
./configure --enable-kumwe_engine
make -j2
NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test TESTS=tests
```

CI also installs the exact Git source archive using PIE inside a network namespace with networking disabled. Configure compiles only the checked-in, hash-verified static Engine source. It cannot fetch source or select an ambient Engine library. PIE installation is a provisioning action; Composer scripts and PHP requests never install extensions.

`resources/engine-lock.json` records the exact source commit, archive SHA-256 and every file digest. `capabilities()` retains the native ABI/capability/corpus/build identity and adds the compiled extension version and embedded source identity. Consumers compare this complete tuple with independently reviewed expected metadata.

The nearest semantic Composer package owns its interface adapter. App owns database access, authorization, HTTP, reference resolution and protected execution. This repository has no algorithms, fallback, FFI, user callbacks, subprocess runtime, or Composer interfaces registered at MINIT.

See [migration handoff](MIGRATION-HANDOFF.md), [memory ownership](docs/memory.md), and [candidate compatibility](resources/compatibility/v1.json). No release, App adoption or roadmap completion is claimed.
