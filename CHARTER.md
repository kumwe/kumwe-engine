# Charter

Own the Zend boundary and distribution of one exact embedded Kumwe Engine source. Own PHP/native value transport, native handle lifetimes, module identity, stubs/arginfo, deterministic ABI status exceptions, PHPT and source installation evidence.

Engine owns algorithms, semantic plans, decimal/formula/document/report execution and canonical byte/digest production. Semantic Composer packages own interfaces/adapters. App owns persistence, auth, reference resolution, transactions and HTTP. No second runtime algorithm owner, fallback, FFI, subprocess or request-time installer is permitted.

The public PHP surface is exclusively the internal `Kumwe\Engine\Runtime` and
`Kumwe\Engine\Exception\BindingFailure` classes declared by `resources/api/v1.json`.
Stubs and generated arginfo describe that native API; Composer cannot autoload a
second implementation. Semantic adapters and their DI providers belong to their
Composer owners, and this extension registers no semantic package interfaces.

Supported source builds target PHP 8.5 NTS, Linux x86_64 and glibc. The exact PHP
patch, Zend API, compiler, linker, flags, module and embedded Engine identities
are recorded and verified as one tuple. CMake 3.25+, C11/C++20 compilers, matching
PHP development headers/phpize and make are provisioned before installation.
PIE 1.4.10 installs the complete committed source with networking disabled;
neither configure nor runtime downloads code or selects ambient Engine libraries.
Other PHP versions, ZTS, architectures and operating systems require their own
passing supported-tuple evidence before this charter may expand.

The proposed first stable binding is 1.0.0. Stable publication requires a frozen,
independently verified immutable Engine release, its exact source closure, full
native/binding/offline-install qualification, deterministic source inventory and
verified signed provenance. Candidate and published-release attestations remain
outside the source trees they identify. A version declaration alone never grants
publication or establishes release verification. Measured whole-boundary results,
including slower workloads, remain visible; App workload acceptance is separate.

Maintainers own the supported-line security policy in `SECURITY.md`. Recovery
restores the complete last-known-good PHP, extension, Engine, Composer lock and
configuration image. It cannot replace a loaded shared object or select a fallback.
Additional semantic kernels belong in Engine; adapters remain in their nearest
semantic package. A distinct binding/platform requires a separate reviewed API,
ownership and qualification decision rather than expanding this runtime boundary.
