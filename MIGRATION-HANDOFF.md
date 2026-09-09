---
schema: kumwe-migration-handoff/v2
artifact_kind: php_extension
migration_id: KUMWE-MIG-2026-043
change_set: KUMWE-CS-2026-043
state: draft_pr_open
source:
  app:
    repository: https://github.com/kumwe/app
    baseline_commit: null
    examined_paths: []
    old_namespace_roots: []
    capability_index_sha256: null
  semantic_inputs:
  - owner: kumwe/engine
    version_or_commit: 1.0.1 (v1.0.1) at 6e2baa49e1a4e3dd4c13da53e59f62abdf641f8a
    manifest_or_corpus: resources/engine-lock.json; exact published release archive (kumwe-engine-source.tar.gz) and
      complete per-file closure
    sha256: 906dbb49c2fee9c28bee66c9f5cbd710494bc93a4fcb00067e0e60257b4013f7
  examined_dependencies:
  - Engine C ABI, complete locked source, all semantic corpora and capability manifests; no semantic implementation
    is owned by this binding.
  - PHP 8.5 Zend API and phpize/php-config build identity; PIE 1.4.10 source installer.
  - Computation portable contracts and native adapter are separate Composer packages; no Composer interfaces or
    semantic classes are registered by this extension.
  active_related_pull_requests:
  - https://github.com/kumwe/engine/pull/8
target:
  repository: https://github.com/kumwe/kumwe-engine
  artifact_identity: PIE kumwe/kumwe-engine; module kumwe_engine; ext-kumwe_engine
  canonical_namespace_or_abi: Kumwe\Engine\
  branch: codex/stable-native-readiness-20260908
  pull_request: https://github.com/kumwe/kumwe-engine/pull/4
ownership:
  responsibility: Thin bounded Zend marshalling, Engine ABI invocation, source/tuple handshake and request-local
    native ownership.
  non_responsibilities:
  - Business semantics, PHP fallback, Composer semantic interfaces and runtime package installation.
  - Database, authorization, transactions, HTTP, reference resolution, App provisioning and business-runtime cutover.
  allowed_dependency_ceiling:
  - PHP 8.5 Zend API and its declared built-in JSON/random/SPL modules.
  - Exactly embedded self-contained Engine source with its static PCRE2/Unicode closure and C/C++ system runtimes.
  implementation_owner: kumwe/kumwe-engine
  next_consumer: kumwe/computation
  public_manifests:
  - path: resources/api/v1.json
    sha256: 48c01fcc0344565375ab3ee227594a870a65e404352787688d12337e84cb5a93
  - path: resources/compatibility/v1.json
    sha256: 8f4f6c133e76699183d280aea63834b142f8e36b434893248eebafc069a509ea
  - path: resources/engine-lock.json
    sha256: 30a1a8161175653d9882a1dac56a45ae7f99e0416b354b54ff5b2067f93d3d41
  - path: stubs/kumwe_engine.stub.php
    sha256: 60759986320f6b6aa393452c9d41bb55c013c52d633124d14f9c260e07b8d976
  - path: src/kumwe_engine_arginfo.h
    sha256: 6b1ab80b944430f786e510e38f98f809efec80097be6e156de0afc63c9861c75
  intentionally_excluded:
  - No App code, configuration, dependency changes or test removal.
  - No PHP semantic algorithm, user callback, FFI bridge or Composer-autoloadable stub.
framework_php: null
native_cpp: null
php_extension:
  repository_package: kumwe/kumwe-engine
  module: kumwe_engine
  platform_package: ext-kumwe_engine
  php_namespace: Kumwe\Engine\
  php_api_manifest: resources/api/v1.json
  registered_classes:
  - Kumwe\Engine\Runtime
  - Kumwe\Engine\Exception\BindingFailure
  methods_and_exceptions:
  - 'Kumwe\Engine\Runtime::capabilities(): array'
  - 'Kumwe\Engine\Runtime::compile(array $envelope): array'
  - 'Kumwe\Engine\Runtime::execute(array $envelope): array'
  - 'Kumwe\Engine\Runtime::release(string $planId): void'
  - Kumwe\Engine\Exception\BindingFailure extends RuntimeException; native failure status is the exception code;
    portable findings remain unchanged.
  stubs_and_arginfo:
  - stubs/kumwe_engine.stub.php
  - src/kumwe_engine_arginfo.h
  - tools/generate-arginfo.php --check; stubs are never autoloaded.
  embedded_engine:
    version: 1.0.1
    source_commit: 6e2baa49e1a4e3dd4c13da53e59f62abdf641f8a
    source_archive_sha256: 906dbb49c2fee9c28bee66c9f5cbd710494bc93a4fcb00067e0e60257b4013f7
    abi_major: 1
    capabilities:
    - decimal-batch-draft/1
    - formula-draft/1
    - normalized-document-draft/1
    - normalized-preparation-draft/1
    - report-materialization-draft/1
    - kumwe-canonical-json/generic-v1
    corpus_digests:
    - 11033679b018fdc9a192e954ef11089444a00a1d89c6279d3c192be9252cf42f
    - 4eb1543929fcf5470eb8cae882127556cd57c02518109545483d8780266b561a
    - 635db251898707828e24f12b1abb672273552f5f633186a725cc9f50ac08140c
    - 65cde051396085a01e9723a120460c8e00519eb63acff80d7425533983f011b0
    - 6949c2763e05a1b76ea4f6c29cc7c2df57eb25d80ee625078a1d16545a8b41f4
    - 752f41632d2ad38d74c5c61db5ff5f373b9427596d7766449b84398ddb15bce2
    - 84b6c2e55ae591c921536aa755cbb5a9a40a7a19847fe47415e55dce2614c177
    - 84d21b12e7a2bfd752356d9a6e664bcb332e209d19017e7634e7485a4fa4e250
    - 8a1c1dce8686d09ca5e53d7b1971620e887237c6019a1189549bd873ec6a87da
    - 975116dc897a0bfdee4a08f9065eb10ccfec06a32f4eb93a48015b08af408c01
    - dbc005bd77fdd764c873bdde57261bc6cb79a8463164fb83e0da8cbe7603161c
    - fce91bfe3c9614ec3862b50c0defc10a9b69ed021671f8382d774d8cb0a20668
  handle_lifecycle:
  - Runtime owns at most 64 immutable plans and 16 MiB encoded source; opaque random plan IDs belong only to their
    creating Runtime.
  - Explicit release consumes its native plan and source budget. Foreign, released and unknown IDs refuse; live
    plans are never silently evicted.
  - Objects cannot clone or serialize; destruction releases all remaining native plans and outputs through the Engine
    ABI. No request-crossing handles or persistent cache.
  - Input marshalling and output ownership are bounded; all exception/refusal paths preserve native cleanup.
  pie:
    metadata_path: composer.json
    source_package_path: .
    network_free_consumer_build: true
    supported_tuples:
    - PHP 8.5 NTS and ZTS; Linux x86_64; exact PHP patch/Zend API, thread model and compiler/linker/flags recorded by
      configure; source distribution.
  dependency_injection:
    provider: null
    reason: The native module registers only its internal Runtime and BindingFailure at startup. Semantic Composer
      adapters own their providers; App provisioning is a later separate task.
tests:
  moved_or_added:
  - Native PHPT API/ownership/bounds/opaque result tests and every shared owner corpus replay.
  - Exact Engine/source/build handshake, arginfo drift, source replacement refusal, installed binary export/linkage,
    diagnostic PHP package provenance and Valgrind lifecycle tests.
  - Independent source-built PHP ASan/UBSan suite, network-isolated PIE consumer and full whole-boundary benchmark.
  remain_in_app_or_consumer:
  - App execution/composition/authorization/storage/provisioning tests remain unchanged.
  - Engine owns semantic algorithm, C ABI, thread safety, fuzz and portable corpus tests; Computation owns its semantic
    adapter and CompiledProgram association/cleanup.
  split_tests: []
  prohibited_duplicates:
  - No copied App or Composer semantic implementation or PHP fallback. Unchanged App/SDK benchmark oracle is an
    exact external checkout excluded from published source.
  corpora:
  - corpus/canonical/generic-v1.json
  - corpus/decimal/decimal-v1.tsv
  - corpus/definition/formula-v1.json
  - corpus/document/computed-normalization-v1.json
  - corpus/document/document-profile-v1.json
  - corpus/document/normalized-values-v1.json
  - corpus/document/preparation-v1.json
  - corpus/document/unicode-normalization-oracle-v1.json
  - corpus/document/validation-v1.json
  - corpus/document/validator-edges-v1.json
  - corpus/document/validator-extension-v1.json
  - corpus/reporting/materialization-v1.json
documentation:
  charter: CHARTER.md
  readme: README.md
  public_api: resources/api/v1.json
  architecture: docs/memory.md
  integration_or_consumer: docs/releasing.md
  examples:
  - tools/consumer.php
  - tests/common.inc
  changelog_record: CHANGELOG.md / Unreleased
release_expectations:
  version_policy: The extension version always equals the embedded Engine version. The Engine sync workflow embeds
    every published Engine release byte for byte; a default-branch commit that embeds a published release and passes
    every quality lane is published under that shared version (docs/releasing.md).
  expected_artifact_types:
  - PIE-installable source tar.gz
  - SHA256SUMS
  - SPDX source inventory
  - GitHub OIDC source provenance
  required_checks:
  - Exact source-head source preparation, the NTS PHP binding/PHPT/Valgrind lane, the ZTS binding/PHPT/lifecycle
    lane, ASan/UBSan, offline PIE and full whole-boundary benchmark jobs.
  - Complete Engine lock, verified Engine release checksum and build provenance, and frozen ABI/capability/corpus identity.
  - The release job refuses unreleased embedded source, version drift, tag moves and asset replacement.
  required_registry_or_installer: PIE 1.4.10; source builds without network after explicit toolchain provisioning.
  required_external_attestation: false
next_task:
  phase_name: Finish exact candidate qualification, then re-embed independently verified immutable Engine and qualify
    stable binding.
  permitted_only_when:
  - The Engine sync has embedded a published Engine release whose archive checksum and GitHub OIDC build provenance
    verified.
  - Every binding quality lane passes on that default-branch commit; the workflow then tags and publishes it.
  consumer_repository: https://github.com/kumwe/computation
  dependency_or_native_change: Publish the verified binding source and exact module identity; native Computation
    and SDK consumption follow. App provisioning/cutover is separately authorized later.
  namespace_or_api_replacements: []
  files_to_update:
  - vendor/engine
  - resources/engine-lock.json
  - resources/compatibility/v1.json
  - resources/api/v1.json
  - MIGRATION-HANDOFF.md
  - README.md
  - CHANGELOG.md
  files_to_remove: []
  tests_to_remove: []
  tests_to_retain_or_add:
  - Keep all cross-layer corpus, lifecycle, arginfo/source/build identity, offline install and boundary benchmark
    checks.
  di_or_provisioning_changes: []
  capability_index_changes: []
  changelog_and_evidence_changes:
  - Retain NRM-2026-043/044 enabling evidence; completion_claim false is not an App roadmap completion claim.
  - Each release's source.json and GitHub OIDC provenance bind the exact source/head/tree, embedded Engine and
    archive digests.
  verification_commands:
  - php tools/generate-arginfo.php --check
  - php tools/verify-binding.php
  - php tools/verify-engine.php
  - phpize && ./configure --enable-kumwe_engine && make -j2
  - NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test TESTS=tests
  - php tools/release-source-test.php
  - php tools/test-sync-engine.php
  - php tools/verify-toolchain.php
  - sudo unshare --net -- env PATH="$PATH" COMPOSER_DISABLE_NETWORK=1 KUMWE_PIE_PATH="$(command -v pie)" bash tools/offline-install.sh
concurrency:
  likely_conflict_files:
  - resources/engine-lock.json
  - vendor/engine
  - resources/compatibility/v1.json
  - MIGRATION-HANDOFF.md
  related_migrations:
  - KUMWE-MIG-2026-011
  ownership_conflicts: []
  integration_train: null
  resolution_rule: semantic-preservation
governance:
  roadmap_source_sha256: a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8
  roadmap_refs: []
  non_roadmap_refs:
  - NRM-2026-043
  - NRM-2026-044
  completion_claim: false
decisions:
- Keep semantic algorithms exclusively in Engine and adapters exclusively in their Composer owners.
- Qualify PHP 8.5 NTS and ZTS on Linux x86_64; other tuples require new passing evidence.
- Publish honest measured whole-boundary results, including slower workloads; App workload acceptance is later.
blockers:
- The embedded Engine records Reporting 0.1.3 as unverified metadata because its clean consumer cannot resolve the
  missing Access Control package registration; this no longer blocks publication.
- The first binding release follows the first published Engine release: the Engine sync workflow embeds it and the
  quality workflow publishes the binding under the same version.
---

# Zend binding implementation handoff

## Migration/implementation summary

The binding implements the complete native PHP transport and ownership surface for NRM-2026-043/044. Engine owns algorithms and semantic corpora. App integration remains a later task; no App source or test was moved.

## Public API and responsibility

[API manifest](resources/api/v1.json), [stubs](stubs/kumwe_engine.stub.php), [ownership rules](docs/memory.md) and [README](README.md) describe the complete internal Runtime and BindingFailure surface. All four methods, bounded plan IDs, opaque results and exception cleanup are checked by the native suite.

## Capability reuse/semantic input review

[Engine lock](resources/engine-lock.json) binds the exact published Engine release archive (tag, version, commit, archive digest) and every embedded source file, installed unchanged under `vendor/engine`. The embedded contracts matrix identifies semantic owner sources/corpora. The binding adds transport features only, never semantic substitutes. Every Engine release replaces the entire embedded closure through the Engine sync workflow and repeats all gates.

## Consumer inventory

Computation consumes the internal native Runtime through its own semantic adapter. The SDK verifies installer/archive and complete native tuples. PIE provisions source modules outside Composer request flows. No DI provider or autoload hook exists here. Later App infrastructure provisioning and Computation runtime adoption remain separate migrations.

## Test ownership

Engine retains C/C++ semantics, ABI and native threading tests. This repository owns PHP marshalling, request lifetime, arginfo, refusal recovery, binary linkage, exact source/build identity, offline installation and cross-layer corpus replay. App tests remain unchanged. The complete benchmark checks out its unchanged PHP oracle externally; it is excluded from the source distribution.

## Next-task execution notes

Follow [versioning, Engine synchronisation and releases](docs/releasing.md). The `Engine sync` workflow embeds each published Engine release through `tools/sync-engine.php` after verifying its checksum and GitHub OIDC build provenance, updates the lock, handshake header, declared versions and this handoff's digests, commits to the default branch and starts the quality workflow; a green run publishes the extension under the same version with signed provenance. Downstream native consumers pin those published tags; App provisioning is a later task.

## Drift check

Engine replacement refuses dirty, unrecorded or symlinked old source and verifies complete incoming archive/file identities. Generated arginfo and configured build identity must match committed manifests and the loaded native tuple. Exact-head CI prevents synthetic PR merge archives from being reported as tested heads. `tools/verify-engine.php` (also run by configure) enforces the hard version link on every build; released artifacts are never overwritten.

## Validation recipe and observed local results

Run the commands listed in the frontmatter and [release instructions](docs/releasing.md). The repaired predecessor candidate passed all five hosted quality lanes, including PHP 8.5 NTS Linux x86_64 package-attributed diagnostic capture, sanitizers, offline PIE and 176 whole-boundary cases with 48 capacity and 12 allocation probes. Those predecessor results establish repair evidence, not a passing attestation for a changed final source. Actual final-head module, installer, tuple, benchmark and source digests are retained externally by CI and independent verification.

## Detailed boundary and lifecycle behavior

The binding acceptance suite replays all 101 formula vectors (59 values, 17 compile refusals and 25 runtime refusals), all 116 report vectors (54 exact row results and 62 runtime refusals), and all 323 document, validator, normalized-value, preparation and computed-normalization owner vectors. Formula/report tests preserve opaque JSON float kinds and empty objects, and verify budget refusal followed by reuse. Document plans select the complete document-profile corpus digest. Dedicated tests retain converted report rounding/provenance and request-local domain instance identity, including refusal recovery. The existing 100-owner Valgrind lifecycle and offline PIE consumer now execute Unicode validation as well as formula plans.

After independently verified publication, extension Phase 2 provisions the exact PIE artifact and checks the complete extension/Engine ABI, capability, corpus, build and source tuple before Composer validation. It only owns platform provisioning/readiness. Computation Phase 2 exclusively owns App business-runtime cutover and removal of old App implementations/tests, after provisioning is human-merged and green. App retains all database, authorization, transaction, reference resolution and delivery authority.

Rollback restores the entire last-known-good image, extension, Engine, Composer lock and configuration tuple. It never selects a PHP implementation or replaces a loaded shared object in place.

The Runtime now provides explicit `release(string $planId): void` to reclaim plan slots and encoded-source bytes during long-lived execution. Computation owns association and garbage-collection cleanup of its portable CompiledProgram objects. The extension rejects foreign/released handles and never evicts a live caller-owned plan. Ordinary and canonical marshalling write bounded transport bytes directly without duplicated PHP trees; semantic algorithms remain entirely native.

The binding advertises `opaque-compiled-results/1` through its own `binding_features` list.
Compiled callers may select `result_format: "opaque"` and consume original `result_json` plus
correlation and portable findings without building an unused decoded result. Omission or `"both"`
retains the original result shape. Current Computation requires this feature before requesting it;
all compiled owner corpora execute in both formats with identical bytes and refusal codes. The
independent expected tuple derives the feature list from the committed binding API manifest.

## PHP and native tooling profile

Packaging, publication checks, diagnostics, source refresh and benchmark orchestration now run in PHP. The bundled Engine retains its complete native test and semantic corpus closure. Repository-specific upstream publishing automation is excluded; the binding owns its own PHP release gates. The lock distinguishes immutable upstream source from the authenticated tooling overlay, and refresh refuses changed overlay bases. All passing evidence must be regenerated for this changed source.
