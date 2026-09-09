# Kumwe Engine PHP binding

`kumwe/kumwe-engine` is the PIE source package for module `kumwe_engine` and Composer platform requirement `ext-kumwe_engine`. The proposed 1.0.0 source is an **unreleased candidate cross-build**, NRM-2026-043. It embeds one exact, digest-locked Engine source snapshot with a reviewed PHP tooling profile; it does not claim an immutable, externally verified Engine release.

The actual extension registers `Kumwe\Engine\Runtime` and `Kumwe\Engine\Exception\BindingFailure`. Its methods are `capabilities(): array`, `compile(array $envelope): array`, `execute(array $envelope): array`, and `release(string $planId): void`. Stubs are documentation and are never autoloaded.

A Runtime owns at most 64 immutable native plans, with a 16 MiB aggregate source budget. Compile returns an opaque random `plan_id` and the Engine descriptor. Execute takes `plan_id`, a bounded `batch`, and optional `cancelled`. IDs work only with the Runtime that created them. Objects cannot clone, serialize, gain dynamic properties, or outlive their request. Call `release($planId)` when a compiled plan is no longer needed; this reclaims both its slot and exact source-byte budget. A released or foreign ID is refused. Destroying a Runtime releases all remaining plans through the Engine ABI.

Canonical `execute` requests carry the GenericV1 profile, corpus digest, operation, original PHP input, and optional semantic limits. The binding writes PHP value/key types, raw string bytes and IEEE-754 bits directly into bounded binary value frames, without allocating a second PHP value tree. Sorting, escaping, formatting, limits, finding precedence and digest algorithms execute in Engine. Objects and resources become unsupported tags without invoking callbacks; repeated acyclic references are allowed and cycles terminate at the bounded depth boundary.

Decimal batch `execute` requests contain exactly `wire_version: 1`, `profile: "decimal-batch-draft/1"`, the committed decimal corpus SHA-256, and `input` holding opaque KED1 bytes. The result contains the same wire/profile and opaque KER1 `result` bytes. All four decimal operations, row ordering, rounding, refusal codes and resource budgets remain owned by the [embedded Engine ABI](vendor/engine/docs/abi.md). The binding limits each input/output to 1 MiB and never interprets decimal values.

## Candidate build

The tested target is PHP 8.5 NTS, Linux x86_64, source installation. Other PHP versions, ZTS, Windows and other architectures are refused. Module startup also compares the executing PHP patch with the independently recorded build patch; a different patch refuses before registering classes. CMake 3.25+, a C11/C++20 compiler, PHP development headers/phpize and make must already be provisioned.

```sh
php tools/verify-engine.php
phpize
./configure --enable-kumwe_engine
make -j2
NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test TESTS=tests
```

CI also installs the exact Git source archive using PIE inside a network namespace with networking disabled. Configure compiles only the checked-in, hash-verified static Engine source. It cannot fetch source or select an ambient Engine library. PIE installation is a provisioning action; Composer scripts and PHP requests never install extensions.

`resources/engine-lock.json` records the exact source commit, archive SHA-256 and every file digest. `capabilities()` retains the native ABI/capability/corpus/build identity and adds the compiled extension version and embedded source identity. The `binding_build` record includes the exact PHP patch and Zend API, NTS model, OS/architecture/libc, compiler and linker versions, actual flags for the binding and embedded Engine/PCRE2, debug/sanitizer status, source distribution identity, and ABI minor/manifest digest. Configure writes `build-identity.json` independently before loading the module; `binding_build_digest` binds its complete bytes. Consumers supply an independently approved exact digest through `NativeCompatibility`, including for sanitizer builds, and compare the resulting tuple.

The nearest semantic Composer package owns its interface adapter. App owns database access, authorization, HTTP, reference resolution and protected execution. This repository has no algorithms, fallback, FFI, user callbacks, subprocess runtime, or Composer interfaces registered at MINIT.

See [migration handoff](MIGRATION-HANDOFF.md), [memory ownership](docs/memory.md), and [candidate compatibility](resources/compatibility/v1.json). Release and App adoption gates remain open.

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
from the trusted binding run for the exact candidate head, into a private directory, then
use the verifier from that trusted checkout before executing any captured file:

    php tools/diagnostic-runtime.php verify /path/to/fixture --expected-commit FULL_COMMIT_SHA
    php tools/diagnostic-runtime.php verify /path/to/fixture --expected-commit FULL_COMMIT_SHA --activate
    /path/to/fixture/bin/php -v

Activation restores executable permissions stripped by artifact ZIP downloads only after
all paths and bytes pass verification. The fixture still uses the host Linux kernel and
system timezone/DNS/CA data; its manifest records that boundary. Pair it with the separate
binding-evidence module from the same run for native diagnostics. This inventory is
diagnostic evidence and makes no release-verification or attestation claim.

Repository maintenance, source packaging, release verification, diagnostics and benchmark orchestration use PHP 8.5 CLI. Native execution remains C/C++ behind the Zend extension. The embedded source lock separately records the immutable upstream archive and the exact reviewed snapshot; upstream repository publishing tools are excluded from this binding distribution. Run `php tools/verify-toolchain.php` to check source languages and PHP syntax.
