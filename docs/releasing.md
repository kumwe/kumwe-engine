# Source releases and candidate verification

The binding source package statically includes one exact Engine source closure.
`resources/engine-lock.json` identifies its commit, archive digest and every embedded
file. `resources/compatibility/v1.json` currently marks both the binding and its
publication status as candidate. Source packaging must preserve those facts.

`tools/release-source.py` prepares and verifies a deterministic source evidence bundle
without downloading, tagging, publishing, signing or claiming a release. Git, gzip and
Python 3.12+ are source-tooling dependencies. The installed extension still builds from
its committed C/C++ sources with the declared PHP/CMake compiler toolchain and no network.

Run from a clean committed checkout, with a new evidence directory outside the repository:

```sh
python3 tools/release-source-test.py
python3 tools/release-source.py prepare ../binding-source-evidence
python3 tools/release-source.py verify ../binding-source-evidence \
  --expected-commit "$(git rev-parse HEAD)"
```

The bundle contains the committed `kumwe-engine-php-source.tar.gz`, full per-file SPDX
inventory `source.spdx.json`, `source.json`, `source.provenance.json` and `SHA256SUMS`.
The source record binds the exact binding commit/tree to the archive and public manifests,
plus the embedded Engine source commit/archive, ABI and compatibility identities. The
SPDX inventory records the binding and the separate embedded Engine package and all
exported files, retaining their upstream license notices. The provenance file is an
unsigned in-toto source-assembly statement; it explicitly claims neither a compiled
artifact nor release attestation. All checksums use relative artifact names.

Preparation builds the archive twice and requires identical bytes. Verification
regenerates the archive, inventory and metadata from the independently supplied commit,
checks complete source closure, and rejects even rehashed metadata that invents a release
claim. It refuses missing/unrecorded artifacts, archive links/traversal, build residues,
credential-like files, dirty tracked inputs and embedded Engine file/corpus/ABI drift.
An optional `--expected-sha256` binds an independently approved source archive digest.
An optional `--tag` checks an existing exact tag without creating or changing it.
Its version must equal the declared binding version, with only the optional `v` prefix ignored;
a same-commit tag with another version is refused even without `--require-stable`.

The actual release archive must be consumed by the existing clean PIE, phpize/configure,
PHPT, sanitizer, lifecycle and compatibility gates. Its single top-level directory can
be removed with `tar --strip-components=1` when extracting into a fresh build directory.
Network access stays disabled during consumer installation. Stubs are documentation;
no Composer runtime hook provisions the module.

For stable source checks, add `--require-stable`. It refuses today's candidate/version,
publication-disabled metadata, unfrozen Engine ABI and unverified semantic/Engine inputs.
It also applies the embedded Engine's portable-only Computation Phase 1A prerequisite:
that release must precede stable Engine publication, independently of the later native
adapter candidate. The exact baseline version/tag/commit, archive/API/capability/corpus
SHA256s, released runtime requirements without a native dependency, absence of native
bindings, and external attestation reference must be recorded in the embedded contract
matrix as `release-verified`. Missing or unresolved facts refuse stable preparation.
The current published Computation `v0.2.0`/`v0.2.1` packages and `0.3.0` adapter candidate
require the native extension and cannot supply that baseline. Re-embedding a reviewed
candidate does not establish a missing portable release. The source record and unsigned
provenance preserve this prerequisite, including null/unresolved facts.
Candidate CI verifies packaging without that option. A stable-source check does not
independently verify an external release attestation or grant publication authority.

The stable binding stage begins only after the immutable Engine release is independently
verified. Re-embed that exact unmodified release, update its lock and reviewed compatibility
metadata, and repeat the full supported PHP 8.5 NTS/Linux x86_64 build/install matrix.
Broader platforms and ZTS need their own passing evidence before support claims change.
Human review and the maintainer release process then publish the PIE-installable source
package with checksums, signed/verifiable source and artifact provenance, license inventory
and advisories. Actual compiler/PHP/Zend/flags and binary hashes come from the final build
identity and platform evidence, not this source-only inventory.

Keep independently signed Engine candidate and release attestations and the binding
release-verification record outside the tested source trees. Verify the published tag,
archive, complete handshake, manifests and signed provenance in a separate verification
session before App provisioning or Computation runtime adoption. No source-tool command
creates those attestations, freezes an ABI or performs App integration.

## Diagnostic PHP host provenance

The relocatable diagnostic fixture requires real installed-package attribution for
its PHP executable, shared extensions, loader and ELF dependencies. The pinned
`setup-php` action's PHP 8.5 binaries can come from an extracted php-builder cache
and therefore cannot supply a `dpkg-query` ownership record. The binding CI lane
explicitly installs/reinstalls the Ubuntu PHP 8.5 CLI, development and extension
packages before compilation, then verifies the CLI/header version agreement.
The captured runtime is consequently the same package-backed host that built and
tested the module. Unknown origins continue to fail closed; assigning a guessed
package name to cached bytes would not establish package provenance.

`python3 tools/test-diagnostic-attribution.py` covers package/multiarch ownership,
merged-/usr path aliases and refusal of unowned builder binaries, mismatched paths,
diversion-only responses and malformed owners. The existing diagnostic self-test
and hosted capture/relocation/module-tuple checks remain mandatory. This fixture
is diagnostic-only and does not provide stable release or artifact attestation.

## Whole-boundary candidate measurements

The dependent `whole-boundary-benchmarks` CI job downloads this run's verified PHP
fixture and tested module, checks the exact consumer tuple again, and replays the
Engine-owned comparison harness. It checks out the exact embedded Engine commit
and verifies its archive and every source digest against the binding lock. The
unchanged PHP oracle is App `24ecf956423c18933e824b43cea1bfb9127a79a9` with SDK
`d0484b8733eaa57d076f567ffa5e997b9564b5fa`; its isolated dependencies are locked under
`tests/benchmark` and excluded from source/PIE distribution. No App change or
integration is made.

The external `whole-boundary-performance` artifact retains all six workload
families at 1/32/256/4096 inputs, 30 measured samples, warm/cold plans, valid and
hostile parity, allocation/RSS probes and 1/2/4/8-process synthetic saturation.
It records the actual module, host, source and corpus identities, including
slower native workloads. Correctness/refusal mismatches fail CI; measurements
never assert an automatic speedup or production capacity result. Stable native
qualification still requires review of the exact candidate's representative
whole-call results and the independently verified release prerequisites.

Both benchmark workers use the same finite 1GiB PHP memory budget so the widest
4096-document oracle can complete its final JSON serialization. Native execution
limits remain part of the measured and tested contract. PHP fatals are retained
in the benchmark artifact's worker stderr logs.

## Immutable source publication

`Native source release` follows a successful `Native binding candidate` run on
the exact current default-branch commit. A dispatch may name that successful run.
`tools/release-native.py` requires every source, binding, sanitizer, offline PIE
and whole-boundary lane to pass and reruns the source gate with `--require-stable`.
It refuses candidate identities, stale or skipped CI, and unverified Engine inputs.

GitHub OIDC signs all five source evidence files. The publisher verifies their
repository, workflow, default-branch ref, exact commit and hosted build identity
before creating an immutable version tag and draft. Retries compare existing
assets without overwriting them, upload missing files, verify all six final assets
and recheck the branch before publication. `build-provenance.sigstore.json` is the
sixth asset. `python3 tools/release-native-test.py` tests refusal and retry cases.

Source provenance does not certify an unbuilt module. The final binary's complete
PHP/Zend/compiler/Engine tuple and independent published-release attestation remain
separate requirements; the publisher never invents that verification.
