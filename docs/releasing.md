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
