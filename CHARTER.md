# Charter

Own the Zend boundary and distribution of one exact embedded Kumwe Engine source. All maintained repository tooling uses PHP CLI, C or C++; shell and CMake coordinate the provisioned native build tools. Own PHP/native value transport, native handle lifetimes, module identity, stubs/arginfo, deterministic ABI status exceptions, PHPT and source installation evidence.

Engine owns algorithms, semantic plans, decimal/formula/document/report execution and canonical byte/digest production. Semantic Composer packages own interfaces/adapters. App owns persistence, auth, reference resolution, transactions and HTTP. No second runtime algorithm owner, fallback, FFI, subprocess or request-time installer is permitted.

The public PHP surface is exclusively the internal `Kumwe\Engine\Runtime` and
`Kumwe\Engine\Exception\BindingFailure` classes declared by `resources/api/v1.json`.
Stubs and generated arginfo describe that native API; Composer cannot autoload a
second implementation. Semantic adapters and their DI providers belong to their
Composer owners, and this extension registers no semantic package interfaces.

Supported source builds target PHP 8.5, non-thread-safe or thread-safe, on Linux
x86_64 and glibc. The exact PHP patch, Zend API, thread model, compiler, linker,
flags, module and embedded Engine identities are recorded and verified as one tuple.
CMake 3.25+, C11/C++20 compilers, matching PHP development headers/phpize and make are
provisioned before installation. PIE 1.4.10 installs the complete committed source with
networking disabled; neither configure nor runtime downloads code or selects ambient
Engine libraries. Other PHP versions, architectures and operating systems require
their own passing supported-tuple evidence before this charter may expand.

The extension version is hard-linked to the embedded Engine: every published Engine
release is embedded byte for byte, its archive checksum and GitHub OIDC build
provenance verified first, and the extension is published under the same version once
every quality lane (source packaging, NTS and ZTS builds with PHPT, sanitizers,
network-isolated PIE installation and the whole-boundary benchmark) passes on the
default branch. Tags and release assets are immutable. Measured whole-boundary
results, including slower workloads, remain visible; App workload acceptance is separate.

Maintainers own the supported-line security policy in `SECURITY.md`. Recovery
restores the complete last-known-good PHP, extension, Engine, Composer lock and
configuration image. It cannot replace a loaded shared object or select a fallback.
Additional semantic kernels belong in Engine; adapters remain in their nearest
semantic package. A distinct binding/platform requires a separate reviewed API,
ownership and qualification decision rather than expanding this runtime boundary.
