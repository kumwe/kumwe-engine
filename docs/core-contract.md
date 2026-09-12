# Core integration contract

The extension exposes `Kumwe\Engine\Runtime` and `Kumwe\Engine\Exception\BindingFailure`.
It owns bounded Zend marshalling, Engine ABI invocation and request-local native ownership.
Engine owns algorithms; Computation owns semantic PHP adapters and compiled-program associations.
Core owns authorization, persistence, transactions, reference resolution, HTTP and deployment.

## Runtime and ownership

`Runtime::capabilities()` reports the extension, embedded Engine, ABI, capability, corpus and
build identities. `compile()` returns an opaque plan ID and descriptor; `execute()` runs a
bounded batch; `release()` frees the owned plan and its source budget. A Runtime owns at most
64 plans and 16 MiB of encoded source. IDs belong to their creating Runtime, live plans are
never evicted, and released or foreign IDs are refused. Objects cannot clone or serialize.
Destruction releases every remaining native plan through the Engine ABI.

`opaque-compiled-results/1` advertises support for `result_format: "opaque"`. Computation
checks it before requesting original `result_json`, correlation and portable findings without
decoding unused results. Omission or `"both"` retains the original result shape. Both modes
preserve semantic byte budgets and refusal codes. Decimal and canonical operations keep their
own documented transports. See the [API manifest](../resources/api/v1.json) and
[memory contract](memory.md).

## Provisioning and compatibility

PIE installs the source package outside request flows. Composer scripts and runtime requests
never provision extensions. Supported source builds target PHP 8.5 NTS or ZTS on Linux x86_64
with glibc. The executing PHP patch must match the independently recorded build patch. Matching
PHP development headers, CMake 3.25+, C11/C++20 compilers and make are provisioned first.

`resources/engine-lock.json` identifies the immutable upstream archive and complete per-file
closure. Configuration verifies this closure and cannot select an ambient Engine library.
Extension and Engine versions always agree. Consumers verify the approved module/build digest,
PHP/Zend API, thread model, ABI, capabilities, corpora and embedded source tuple before use.
Core deploys the qualified image and retains acceptance tests for its workload.

## Verification and recovery

The binding owns cross-layer corpora, PHP marshalling, request lifetime, refusal recovery,
arginfo, binary linkage, source/build identity, Valgrind, sanitizers and offline installation.
Engine owns native semantics, C ABI and threading tests. Core retains composition, authorization,
storage, provisioning and recovery tests. Whole-boundary measurements include slower workloads;
published packages alone do not prove Core integration or capacity.

Rollback restores the complete known-good PHP, extension, Engine, Composer lock and configuration
image. It never replaces a loaded shared object or silently selects a different execution backend.
See [release automation](releasing.md), [security](../SECURITY.md) and the
[release record](release-record.md).
