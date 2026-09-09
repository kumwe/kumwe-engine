# Release and candidate gates

All five native kernels are implemented and have owner-corpus replay, bounded ABI execution,
consumer builds and memory/security CI. `1.0.0` is the proposed first release;
ABI 1 is frozen and no publication is inferred from that source version. Implementation completion does
not establish release verification or App acceleration.

`resources/contracts.json` records exact published semantic-owner tags, commits and corpus paths.
The recorded corpus bytes match those immutable source commits. External release attestations
remain a separate requirement: publication and a matching hash alone do not establish that the
owner release passed every programme acceptance gate. Updates must retain an exact coordinate;
never use a moving branch, `latest`, or an unconstrained range for embedded native sources.

The current pending input is Reporting `v0.1.3`: its published source and unchanged
materialization corpus are recorded, but its original-archive clean consumer cannot resolve
the unregistered Access Control package. Its external attestation remains null, the report
module remains unverified, and stable-source preparation refuses publication. Registering
that dependency must be followed by actual independent verification before these flags change.

The separate Computation Phase 1A prerequisite uses the published portable-only
`v0.1.1` release at `fc9d049f8b675c8e19fd1672d49b5e206c9ad52a`. Its actual source archive,
API, capability, portable corpus and external attestation identities are recorded in
`resources/contracts.json`. Only the schema-valid durable independent receipt linked by that
matrix establishes admission. It must cover the original archive, complete canonical schemas,
package gates and a fresh offline authoritative consumer with no native extension or binding classes.
Earlier malformed or superseded receipts cannot close the prerequisite.
The later Computation native adapter successor still waits for verified Engine and extension
releases; its publication and App adoption are separate steps.

The release gates include the independently verified semantic release barrier, the frozen ABI compatibility fixture, a final supported-platform run, published representative whole-boundary performance
evidence, and signed source/artifact provenance. The retained benchmark evidence includes slower
native document, preparation and canonical workloads; a faster inner kernel cannot close that gap.
Repeat the complete PHP/Zend comparison on the final artifact and retain all results. No App cutover
or capacity claim follows from passing the native test suite.

An independent candidate cross-build must consume the exact Engine PR-head archive in
`kumwe/kumwe-engine` without build-time network retrieval. It checks Zend marshalling, module load,
ABI/capability/corpus agreement, lifecycle and the claimed PHP matrix. Its external
`ENGINE-CANDIDATE-ATTESTATION.yaml` binds both tested commits/trees and the archive digest; it must
remain outside both source trees. Any tested-input change invalidates that evidence. Human merge
and immutable Engine publication follow a passing current candidate gate. A separate verifier
then attests the published Engine release before the stable extension embeds it.

## Embedded distribution tooling

This directory is the binding's reviewed native source snapshot. Its lock in
`../../resources/engine-lock.json` records both the complete immutable upstream
inventory and the actual embedded files. Repository-specific upstream workflows,
duplicate publishers and development package managers are excluded from this
distribution. All native C/C++ tests, ABI fixtures, semantic corpora and licensed
dependency sources remain present.

Source packaging and publication are owned by the binding's PHP tools. From the
binding repository root:

```sh
php tools/release-source-test.php
php tools/release-native-test.php
php tools/release-source.php prepare ../binding-source-evidence
php tools/release-source.php verify ../binding-source-evidence --expected-commit "$(git rev-parse HEAD)"
```

See [binding release instructions](../../../docs/releasing.md) for deterministic
archives, SPDX inventory, stable-state refusal, exact CI identity, signed
provenance and immutable retries. This normalized candidate cannot establish
independent upstream release verification. Stable publication still requires the
semantic-owner, Engine and binding evidence described above.

Native validation uses CMake/CTest, `php tools/check-manifests.php BUILD_DIRECTORY`,
`php tools/generate-unicode-data.php --check`, and the retained native consumer,
symbol, architecture and fault-seed checks. A fresh exact-source build is required
after any tooling overlay change.
