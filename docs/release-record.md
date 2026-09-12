---
schema: kumwe-package-release-record/v1
artifact_kind: php_extension
migration_id: KUMWE-MIG-2026-043
change_set: KUMWE-CS-2026-043
source:
  app:
    repository: https://github.com/kumwe/app
    baseline_commit: null
    examined_paths: []
    old_namespace_roots: []
    capability_index_sha256: null
  semantic_inputs:
  - owner: kumwe/engine
    version_or_commit: 1.0.4 (v1.0.4) at 9b20f80a2ed10eb55e6cea4209d2a672cb1af213
    manifest_or_corpus: resources/engine-lock.json; exact published release archive (kumwe-engine-source.tar.gz) and
      complete per-file closure
    sha256: 76b305790ac31fcd0b58b283fd23c975157324a589dbea646414276229e627be
  examined_dependencies:
  - Engine C ABI, complete locked source, all semantic corpora and capability manifests; no semantic implementation
    is owned by this binding.
  - PHP 8.5 Zend API and phpize/php-config build identity; PIE 1.4.10 source installer.
  - Computation portable contracts and native adapter are separate Composer packages; no Composer interfaces or
    semantic classes are registered by this extension.
target:
  repository: https://github.com/kumwe/kumwe-engine
  artifact_identity: PIE kumwe/kumwe-engine; module kumwe_engine; ext-kumwe_engine
  canonical_namespace_or_abi: Kumwe\Engine\
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
    sha256: 7f6bda4b0b26b8f7d8eae8c871ce856905582a56516c75c0e835b2a062409d89
  - path: resources/engine-lock.json
    sha256: eed402ca87d44d39a23d2d876c0938cb5886096b19c3c338e5f79781d98b2862
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
    version: 1.0.4
    source_commit: 9b20f80a2ed10eb55e6cea4209d2a672cb1af213
    source_archive_sha256: 76b305790ac31fcd0b58b283fd23c975157324a589dbea646414276229e627be
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
  changelog_record: CHANGELOG.md
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
consumer_contract:
  permitted_only_when:
  - Verify published archive checksums and GitHub OIDC build provenance.
  - Qualify the exact source, ABI, capabilities, corpora and supported platform tuple before deployment.
  consumer_repository: https://github.com/kumwe/computation
  dependency_or_native_change: Provision the published PIE extension and let Computation own semantic adapters and business-runtime execution.
  namespace_or_api_replacements: []
  files_to_update: []
  files_to_remove: []
  tests_to_remove: []
  tests_to_retain_or_add:
  - Retain cross-layer corpora, marshalling, lifecycle, arginfo, source identity and offline installation tests.
  - Core retains composition, authorization, persistence, provisioning, recovery and acceptance coverage.
  di_or_provisioning_changes: []
  capability_index_changes: []
  changelog_and_evidence_changes:
  - Release source records and independent verification bind exact commits and artifact digests.
  verification_commands:
  - php tools/generate-arginfo.php --check
  - php tools/verify-binding.php
  - php tools/verify-engine.php
  - php tools/release-source-test.php
  - php tools/test-sync-engine.php
  - php tools/verify-toolchain.php
governance:
  completion_claim: false
decisions:
- Keep semantic algorithms exclusively in Engine and adapters exclusively in their Composer owners.
- Qualify PHP 8.5 NTS and ZTS on Linux x86_64; other tuples require new passing evidence.
- Publish honest measured whole-boundary results, including slower workloads; App workload acceptance is later.
blockers: []
---

# PHP binding release record

## Package contract

The binding owns Zend transport, request-local native ownership and source distribution. See [Core contract](core-contract.md). The retained migration and change-set IDs identify existing independent attestations; they do not describe pending extraction work.

## Public API and responsibility

The [API manifest](../resources/api/v1.json), [stubs](../stubs/kumwe_engine.stub.php) and [memory contract](memory.md) define Runtime and BindingFailure. Algorithms belong to Engine.

## Dependencies and semantic inputs

The [Engine lock](../resources/engine-lock.json) pins the published upstream archive and complete embedded source closure. Source synchronization verifies checksums and GitHub OIDC build provenance.

## Consumer contract

PIE provisions the module; Computation owns PHP semantic adapters. Core owns authority, persistence, reference resolution, transactions, provisioning and recovery. Package publication does not establish Core integration or workload acceptance.

## Test ownership

The binding owns PHP marshalling, lifecycle, refusal recovery, arginfo, source/build identity, offline PIE and cross-layer corpora. Engine retains native semantics and ABI tests.

## Consumer verification

Follow [release verification and automation](releasing.md). Verify immutable source checksums and provenance, then qualify the complete supported tuple before deployment. Roll back the entire known-good deployment tuple.

## Compatibility and drift

Public manifest hashes above remain executable verification inputs. Generated arginfo, embedded source, build identity and extension/Engine versions must agree. Released tags and assets are immutable; changed source requires a new release.

## Validation

Run the verification commands above and the complete hosted quality workflow. CI evidence applies only to its tested commit and platform; performance measurements include slower workloads and do not imply production acceleration.
