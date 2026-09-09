# Versioning, Engine synchronisation and releases

The extension version is hard-linked to the Engine: extension `vX.Y.Z` always embeds
Engine `vX.Y.Z`, byte for byte as published by `kumwe/engine`. Nothing here is versioned by
hand and nobody creates, moves or deletes tags, edits releases or starts workflows by hand:
people merge, and two workflows keep the link, publish releases and complete anything an
earlier run left unfinished.

## `Engine sync` (`.github/workflows/engine-sync.yml`)

Runs when `kumwe/engine` sends `repository_dispatch` `engine-release` after publishing a
release, when started by hand (optionally naming a tag), and every six hours as a fallback
that picks up any release the dispatch missed. It:

1. resolves the Engine release (the dispatched tag, the requested tag, or the latest
   published release);
2. downloads `kumwe-engine-source.tar.gz`, `SHA256SUMS`, `source.json` and
   `source.spdx.json` from that release, checks `sha256sum --check --strict SHA256SUMS`,
   verifies the archive's GitHub OIDC build provenance with `gh attestation verify
   --repo kumwe/engine --signer-workflow kumwe/engine/.github/workflows/ci.yml`, and
   compares the dispatched digest when one was sent;
3. runs `php tools/sync-engine.php ARCHIVE --release vX.Y.Z --commit SHA --expected-sha256 HEX`,
   which installs the archive under `vendor/engine` unchanged, writes
   `resources/engine-lock.json` (schema `kumwe-embedded-engine/v2`: repository, version,
   release, commit, archive name and SHA-256, every file digest) and
   `php_kumwe_engine_build.h`, and sets the same version in `php_kumwe_engine.h` and
   `resources/compatibility/v1.json`;
4. commits `Embed Engine vX.Y.Z` to the default branch as `Lemuel <lemuel@vdm.to>`
   (override with repository variables `KUMWE_RELEASE_AUTHOR_NAME` and
   `KUMWE_RELEASE_AUTHOR_EMAIL`), refreshes the handoff digests, and starts the `Native
   binding candidate` workflow on that commit. If the release is already embedded nothing
   is committed, but the quality workflow is still started while `vX.Y.Z` has no published
   GitHub release, so a failed, cancelled or interrupted run is retried on the next sync.

`php tools/verify-engine.php` (also run by `configure`) checks the embedded tree against
the lock, the compiled handshake header against the lock, and the hard link between the
extension version, the compatibility manifest and the embedded Engine's declared version.

## `Native binding candidate` (`.github/workflows/ci.yml`)

Every push to the default branch, pull request and manual run executes all lanes:

| Lane | What it proves |
|---|---|
| `source-release-preparation` | toolchain policy, tooling regression suites, reproducible source bundle |
| `binding` | NTS PHP 8.5 build against the package-attributed distro host, complete embedded Engine CTest suite, PHPT corpus parity, patch-guard refusal, Valgrind lifecycle, diagnostic runtime capture |
| `binding-zts` | thread-safe PHP 8.5 build, PHPT corpus parity, consumer tuple and allocation lifecycle |
| `address-undefined-sanitizers` | instrumented PHP host and module under ASan/UBSan |
| `clean-pie` | PIE 1.4.10 install of the exact committed archive with networking disabled |
| `whole-boundary-benchmarks` | complete PHP-versus-native comparison with exact parity checks |

On the default branch, when every lane passed, the `release` job runs
`php tools/release-gate.php`, which decides what the run releases, in this order:

1. **A tag without a published release is completed first.** If an earlier run tagged a
   version and then failed before its GitHub release was published (or left a draft), this
   run releases that tag from the commit it identifies, with the current release tooling,
   and then starts a follow-up run so the tip of the default branch is evaluated afresh.
   Tags are never moved or deleted.
2. **An unreleased embedded Engine publishes nothing.** The binding is published only under
   the version of a published Engine release (`release` in the lock is not null; the
   extension version equals that Engine version, enforced by `verify-engine.php`).
3. **An unreleased declared version is released.** If tag `vX.Y.Z` does not exist, this
   commit is released as that version.
4. **A published version whose released source is unchanged releases nothing.** If the tag
   identifies another commit and the released-source identity (every exported path with its
   mode and content digest) is the same, the merge changed only export-ignored files.
5. **A binding-only change requests the next Engine release itself.** If the tag identifies
   another commit and released source changed, the run starts the Engine's `Native quality`
   workflow on its `main` with the `bump` input (repository secret
   `KUMWE_ENGINE_DISPATCH_TOKEN`), unless a newer Engine release is already published or such
   a run is already pending. The Engine declares and publishes the next patch and dispatches
   the sync back here, which embeds it and publishes this change under the new version.

The job checks out the release tooling from the commit that triggered the run and,
separately, the commit being released (`KUMWE_ROOT`), assembles the bundle with
`php tools/release-source.php prepare`, attests GitHub OIDC build provenance, creates the
annotated tag on the released commit, and publishes the GitHub release with notes from
`php tools/release-notes.php` (`tools/release-publish.sh`, naming the repository explicitly
on every `gh` call). Tags are never moved and assets are never replaced: a rerun verifies
existing bytes, uploads only what is missing and promotes a draft left by an interrupted
publish. Packagist is auto-updated from the repository, so the tag appears as a new version
of `kumwe/kumwe-engine` with both thread-safety modes marked supported.

Release assets: `kumwe-engine-php-source.tar.gz` (`git archive --prefix=kumwe-engine-php/`
piped through `gzip -n`, honouring `.gitattributes` export rules), `SHA256SUMS`,
`source.json`, `source.spdx.json`, `build-provenance.sigstore.json`. Verify a download with:

```sh
sha256sum --check SHA256SUMS
gh attestation verify kumwe-engine-php-source.tar.gz --repo kumwe/kumwe-engine
```

## Local verification

```sh
php tools/verify-toolchain.php      # PHP/C/C++/Shell only; no interpreter invocations
php tools/verify-engine.php         # embedded tree, handshake header and hard version link
php tools/release-source-test.php   # packaging, archive parsing and refusal cases
php tools/test-sync-engine.php      # embedding admission, replacement and refusal cases
php tools/test-release-gate.php     # every default-branch release decision and the released-source identity
php tools/release-source.php prepare ../binding-source-evidence
php tools/release-source.php verify ../binding-source-evidence --expected-commit "$(git rev-parse HEAD)"
```

To embed a specific published Engine release by hand, download its assets and run the
same `sync-engine.php` command the workflow uses; commit the result. To embed unreleased
Engine source for development, build the archive with the Engine's
`tools/release-bundle.sh` and omit `--release`; the lock then records `release: null`
and the release gate refuses to publish until a published release is embedded.

## Repository settings the pipeline relies on

These are configured once; the workflow reports which one is missing when it cannot proceed.

- `Engine sync` pushes the `Embed Engine vX.Y.Z` commit to the default branch and starts
  `ci.yml` with the workflow token. Branch protection or rulesets must allow that push (or
  list GitHub Actions as a bypass); without it no Engine release can be embedded.
- Repository secret `KUMWE_ENGINE_DISPATCH_TOKEN`: a fine-grained token with Actions: read
  and write on `kumwe/engine`, used only to request the next Engine patch release for a
  binding-only change. Without it that request fails with this instruction.
- `kumwe/engine` needs the repository secret `KUMWE_BINDING_DISPATCH_TOKEN` (fine-grained
  token, Contents: read and write on `kumwe/kumwe-engine`) to dispatch immediately; the
  six-hourly schedule covers the case where it is missing. One fine-grained token with
  Contents and Actions read/write on both repositories can serve as both secrets.
- Releases and tags are created with the workflow token; no personal token is used for them.

## Diagnostic PHP host provenance

The relocatable diagnostic fixture requires real installed-package attribution for its PHP
executable, shared extensions, loader and ELF dependencies. The pinned `setup-php` action's
PHP 8.5 binaries can come from an extracted php-builder cache and therefore cannot supply
a `dpkg-query` ownership record. The `binding` lane explicitly installs the Ubuntu PHP 8.5
CLI, development and extension packages before compilation, then verifies the CLI/header
version agreement, so the captured runtime is the same package-backed host that built and
tested the module. `php tools/test-diagnostic-attribution.php` covers ownership, multiarch
and refusal cases. The fixture is diagnostic-only.

## Whole-boundary measurements

`whole-boundary-benchmarks` downloads the verified PHP fixture and tested module, checks
the consumer tuple again, and runs `tools/benchmark-runtime.php` with the worker and
allocation probes under `tools/benchmark/` against the embedded `vendor/engine` corpora.
The unchanged PHP oracle is App `24ecf956423c18933e824b43cea1bfb9127a79a9` with SDK
`d0484b8733eaa57d076f567ffa5e997b9564b5fa`; its isolated dependencies are locked under
`tests/benchmark` and excluded from the source archive. The artifact retains all six
workload families at 1/32/256/4096 inputs, valid and hostile parity, allocation/RSS probes
and 1/2/4/8-process saturation, including slower native workloads. Correctness mismatches
fail CI; measurements never assert an automatic speedup or production capacity result.
