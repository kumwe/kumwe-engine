# Kumwe Engine PHP binding

[![Packagist](https://img.shields.io/packagist/v/kumwe/kumwe-engine)](https://packagist.org/packages/kumwe/kumwe-engine)
[![Native binding quality](https://github.com/kumwe/kumwe-engine/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/kumwe/kumwe-engine/actions/workflows/ci.yml)
[![PHP 8.5 NTS and ZTS](https://img.shields.io/badge/PHP-8.5%20NTS%20%7C%20ZTS-777BB4)](#build)
[![Linux x86_64](https://img.shields.io/badge/platform-Linux%20x86__64-blue)](resources/compatibility/v1.json)
[![License: Apache-2.0](https://img.shields.io/github/license/kumwe/kumwe-engine)](LICENSE)

`kumwe/kumwe-engine` is the PIE source package for module `kumwe_engine` and Composer platform requirement `ext-kumwe_engine`. It embeds one exact, digest-locked Kumwe Engine source release under `vendor/engine`, byte for byte as published by [`kumwe/engine`](https://github.com/kumwe/engine), and is published under the same version: extension `vX.Y.Z` always contains Engine `vX.Y.Z`. See [versioning, Engine synchronisation and releases](docs/releasing.md).

The actual extension registers `Kumwe\Engine\Runtime` and `Kumwe\Engine\Exception\BindingFailure`. Its methods are `capabilities(): array`, `compile(array $envelope): array`, `execute(array $envelope): array`, and `release(string $planId): void`. Stubs are documentation and are never autoloaded.

A Runtime owns at most 64 immutable native plans, with a 16 MiB aggregate source budget. Compile returns an opaque random `plan_id` and the Engine descriptor. Execute takes `plan_id`, a bounded `batch`, and optional `cancelled`. IDs work only with the Runtime that created them. Objects cannot clone, serialize, gain dynamic properties, or outlive their request. Call `release($planId)` when a compiled plan is no longer needed; this reclaims both its slot and exact source-byte budget. A released or foreign ID is refused. Destroying a Runtime releases all remaining plans through the Engine ABI.

Canonical `execute` requests carry the GenericV1 profile, corpus digest, operation, original PHP input, and optional semantic limits. The binding writes PHP value/key types, raw string bytes and IEEE-754 bits directly into bounded binary value frames, without allocating a second PHP value tree. Sorting, escaping, formatting, limits, finding precedence and digest algorithms execute in Engine. Objects and resources become unsupported tags without invoking callbacks; repeated acyclic references are allowed and cycles terminate at the bounded depth boundary.

Decimal batch `execute` requests contain exactly `wire_version: 1`, `profile: "decimal-batch-draft/1"`, the committed decimal corpus SHA-256, and `input` holding opaque KED1 bytes. The result contains the same wire/profile and opaque KER1 `result` bytes. All four decimal operations, row ordering, rounding, refusal codes and resource budgets remain owned by the [embedded Engine ABI](vendor/engine/docs/abi.md). The binding limits each input/output to 1 MiB and never interprets decimal values.

## Build

The supported target is PHP 8.5, non-thread-safe (NTS) or thread-safe (ZTS), on Linux x86_64 from source. Other PHP versions, Windows and other architectures are refused at configure or compile time. Module startup also compares the executing PHP patch with the independently recorded build patch; a different patch refuses before registering classes. CMake 3.25+, a C11/C++20 compiler, PHP development headers/phpize and make must already be provisioned.

```sh
php tools/verify-engine.php
phpize
./configure --enable-kumwe_engine
make -j2
NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test TESTS=tests
```

Published versions install with PIE from Packagist:

```sh
pie install kumwe/kumwe-engine
```

CI also installs the exact Git source archive using PIE inside a network namespace with networking disabled, and builds and tests the module against both a non-thread-safe and a thread-safe PHP 8.5. The extension keeps no state shared between threads: class entries and handlers are registered once at module startup, every `Runtime` and plan is request-local, and the Engine C ABI is reentrant (the Engine repository exercises its shared immutable plan under ThreadSanitizer). Configure compiles only the checked-in, hash-verified static Engine source. It cannot fetch source or select an ambient Engine library. PIE installation is a provisioning action; Composer scripts and PHP requests never install extensions.

`resources/engine-lock.json` records the embedded Engine release tag, version, source commit, archive SHA-256 and every file digest; `php tools/verify-engine.php`, also run by configure, checks the tree against that lock and enforces the hard version link between `php_kumwe_engine.h`, `resources/compatibility/v1.json` and the embedded Engine. `capabilities()` retains the native ABI/capability/corpus/build identity and adds the compiled extension version and embedded source identity. The `binding_build` record includes the exact PHP patch and Zend API, thread model, OS/architecture/libc, compiler and linker versions, actual flags for the binding and embedded Engine/PCRE2, debug/sanitizer status, source distribution identity, and ABI minor/manifest digest. Configure writes `build-identity.json` independently before loading the module; `binding_build_digest` binds its complete bytes. Consumers supply an independently approved exact digest through `NativeCompatibility`, including for sanitizer builds, and compare the resulting tuple.

The nearest semantic Composer package owns its interface adapter. App owns database access, authorization, HTTP, reference resolution and protected execution. This repository has no algorithms, fallback, FFI, user callbacks, subprocess runtime, or Composer interfaces registered at MINIT.

See [releasing](docs/releasing.md), [memory ownership](docs/memory.md), [compatibility](resources/compatibility/v1.json) and the [Core contract](docs/core-contract.md) and [release record](docs/release-record.md).

## Releases

Releases are fully automated; people only merge. Every published Engine release is embedded automatically by the `Engine sync` workflow, which verifies the archive checksum and GitHub OIDC build provenance, commits `Embed Engine vX.Y.Z` to `main` and starts the quality workflow. When every lane passes on `main`, the release job tags `vX.Y.Z`, publishes the reproducible source archive with checksums, SPDX inventory and build provenance, and Packagist lists the new version with both thread-safety modes supported. A release an earlier run left unfinished is completed by the next run, and nobody creates, moves or deletes a tag by hand. A binding-only change ships with the next Engine release, because versions are hard-linked; the workflow requests that Engine release itself.

Opaque compiled documents use KEB1/KER2 framing internally; canonical PHP values use KEC1 frames.

Compiled `execute` envelopes may select `result_format: "opaque"` to return each row's
`correlation`, portable `findings` and original `result_json` without creating an unused decoded
PHP `result`. Omission or explicit `"both"` preserves the original four-key row. The option is a
strict string enum; canonical and decimal calls do not accept it. Original logical input/output
budgets apply in both modes. The optimized framing path relies on Engine-owned JSON serialization;
direct value-tree batches retain the same row shape after the compatibility decoder runs.
`binding_features` advertises `opaque-compiled-results/1`; current Computation verifies this
capability before requesting opaque results. It never substitutes a userland execution backend.
These bounded transports preserve the public arrays, ordered key types, raw bytes, semantic findings
and logical JSON byte budgets. Direct value-tree batches retain the JSON transport. The C ABI
contracts and framing belong to the embedded Engine; Zend performs only marshalling and ownership.

The diagnostic-runtime CI artifact captures the already installed PHP 8.5 CLI, curated
extensions, ELF loader/dependencies and installed package license/version inventory. It
contains no Kumwe repository code and loads no Kumwe extension by default. Download it
from the trusted binding run for the exact tested head, into a private directory, then
use the verifier from that trusted checkout before executing any captured file:

    php tools/diagnostic-runtime.php verify /path/to/fixture --expected-commit FULL_COMMIT_SHA
    php tools/diagnostic-runtime.php verify /path/to/fixture --expected-commit FULL_COMMIT_SHA --activate
    /path/to/fixture/bin/php -v

Activation restores executable permissions stripped by artifact ZIP downloads only after
all paths and bytes pass verification. The fixture still uses the host Linux kernel and
system timezone/DNS/CA data; its manifest records that boundary. Pair it with the separate
binding-evidence module from the same run for native diagnostics. This inventory is
diagnostic evidence only.

Repository maintenance, source packaging, release gating, Engine synchronisation, diagnostics and benchmark orchestration use PHP 8.5 CLI; the release publisher is Bash around `gh`. Native execution remains C/C++ behind the Zend extension. The whole-boundary benchmark worker and allocation probes live under `tools/benchmark/`. Run `php tools/verify-toolchain.php` to check source languages and PHP syntax.
