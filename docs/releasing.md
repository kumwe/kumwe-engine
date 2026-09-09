# Versioning, Engine synchronisation and releases

The extension version is hard-linked to the Engine: extension `vX.Y.Z` always embeds
Engine `vX.Y.Z`, byte for byte as published by `kumwe/engine`. Nothing here is versioned
by hand. Two workflows keep the link and publish releases.

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
   `KUMWE_RELEASE_AUTHOR_EMAIL`) and starts the `Native binding candidate` workflow on
   that commit. If the release is already embedded nothing is committed.

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
`php tools/release-gate.php`, which publishes only when all of the following hold:

- the embedded Engine is a published release (`release` in the lock is not null);
- the extension version equals that Engine version (enforced by `verify-engine.php`);
- tag `vX.Y.Z` does not exist yet. If it already identifies this commit there is nothing
  to do; if it identifies another commit the change is binding-only and ships with the
  next Engine release (cut one by running the Engine's `Native quality` workflow on
  `main`, which bumps the patch and dispatches the sync).

It then assembles the bundle with `php tools/release-source.php prepare`, attests GitHub
OIDC build provenance, creates the annotated tag on the tested commit, and publishes the
GitHub release with notes from `php tools/release-notes.php`
(`tools/release-publish.sh`). Tags are never moved and assets are never replaced.
Packagist is auto-updated from the repository, so the tag appears as a new version of
`kumwe/kumwe-engine` with both thread-safety modes marked supported.

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
php tools/release-source.php prepare ../binding-source-evidence
php tools/release-source.php verify ../binding-source-evidence --expected-commit "$(git rev-parse HEAD)"
```

To embed a specific published Engine release by hand, download its assets and run the
same `sync-engine.php` command the workflow uses; commit the result. To embed unreleased
Engine source for development, build the archive with the Engine's
`tools/release-bundle.sh` and omit `--release`; the lock then records `release: null`
and the release gate refuses to publish until a published release is embedded.

## Repository settings the pipeline relies on

- `Engine sync` pushes to the default branch and starts `ci.yml` with the workflow token.
  Branch protection must allow that push (or list GitHub Actions as a bypass).
- `kumwe/engine` needs the repository secret `KUMWE_BINDING_DISPATCH_TOKEN` (fine-grained
  token, Contents: read and write on `kumwe/kumwe-engine`) to dispatch immediately; the
  six-hourly schedule covers the case where it is missing.
- Releases and tags are created with the workflow token; no personal token is used.

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
