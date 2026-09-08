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
