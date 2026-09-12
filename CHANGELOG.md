# Changelog

## Unreleased

- Standardize linked package, quality, PHP/platform and license badges and document the current Core contract.
- Replace the obsolete migration handoff with a release record and preserve atomic Engine sync and manifest verification against its new path.
- Keep the published Engine tuple unchanged until the automated sync embeds the next verified release; binding and Engine versions remain linked.

- Refresh the compatibility manifest digest when Engine sync changes its version, before
  committing the new source tuple. Verify every handoff public manifest hash during source
  qualification and cover digest refresh and missing-declaration refusal in the sync suite.

- Encode the migration handoff's release explanation as a YAML scalar so its colon and
  continuation remain one string and independent release verification can parse the full
  handoff. The released 1.0.1 source and embedded Engine identity are unchanged.

- Make the release pipeline complete without people. The release gate
  (`tools/release-gate.php`) completes any tag whose GitHub release is missing from the
  commit the tag identifies (with the current tooling, then re-evaluates the tip in a
  follow-up run), publishes nothing when only export-ignored files changed, and requests the
  next Engine patch release itself for a binding-only change (repository secret
  `KUMWE_ENGINE_DISPATCH_TOKEN`) instead of asking someone to start the Engine workflow.
  Tags are never moved or deleted; a draft left by an interrupted publish is promoted, never
  recreated. The Engine sync retries the quality workflow while the embedded version has no
  published release. `tools/test-release-gate.php` covers every decision.
- Fix the release publisher: `gh` now names the repository on every call and runs from the
  checkout, so publishing no longer depends on the working directory.
- Hard-link the extension version to the embedded Engine release. The new `Engine sync`
  workflow embeds every published `kumwe/engine` release byte for byte (checksum and GitHub
  OIDC provenance verified), commits the embedding to the default branch and starts the
  quality workflow; a green run on the default branch publishes tag `vX.Y.Z` with the
  reproducible source archive, SPDX inventory, checksums and build provenance. The former
  external-attestation publisher and the transformed tooling snapshot are removed;
  `resources/engine-lock.json` is now schema v2 with the release tag, version, commit,
  archive name and digest and every embedded file digest.
- Support thread-safe PHP (ZTS) alongside NTS: the module keeps no cross-thread state, the
  build record reports the actual thread model, `composer.json` declares `support-zts`, and
  CI builds and tests the module against a thread-safe PHP 8.5.
- Own the whole-boundary benchmark worker and allocation probes under `tools/benchmark/`
  and measure against the embedded `vendor/engine` corpora; no Engine checkout is needed.

- Use PHP CLI for source packaging, immutable publication checks, Engine embedding,
  diagnostic capture and benchmark orchestration, retaining native C/C++ execution.
  Port the regression suites and Unicode generator, preserve safe diagnostic
  activation with a C++ helper, and run complete native and binding gates in CI.
- Superseded: the reviewed embedded tooling snapshot and its overlay refresh are
  replaced by the byte-exact embedding of the published Engine archive described in the
  first entry above; the source policy still rejects unsupported implementation languages.

- Prepare the proposed 1.0.0 binding against frozen Engine ABI 1 without changing
  its PHP methods, framing, ownership or corpus semantics. (The candidate publication
  barriers this entry introduced are superseded by the hard-linked release described
  in the first entry above.)
- Bind all qualification checkouts to the exact source head, complete the v2
  extension handoff, and pin PIE 1.4.10 to its official PHAR SHA256 while retaining
  the actual network-isolated install/build tuple as external evidence.

- Replay the full Engine-owned whole-call benchmark against each tested candidate
  module and its verified diagnostic PHP host. Retain exact source identities,
  every parity/refusal result, slower profiles, allocations and saturation evidence.
  Freeze the unchanged PHP oracle and its dependencies outside source/PIE exports.

- Build and capture the diagnostic PHP host from actual Ubuntu packages so every
  captured executable, extension and ELF dependency retains strict package attribution.
  Add package-origin refusal regressions; keep diagnostic byte/closure verification
  and the exact relocated consumer tuple gate. Roadmap impact: None (CI provenance fix).

- NRM-2026-043: implement the candidate Zend Runtime and BindingFailure, with bounded native
  compile/execute/cancellation, typed canonical transport and exact source/build identity.
- NRM-2026-044: add `Runtime::release(string $planId): void` so a long-lived owner can reclaim
  individual plan slots and the exact encoded-source budget. Foreign, malformed and already
  released IDs fail without changing live plans; object destruction releases remaining plans.
- Marshal admitted arrays and canonical value/key tags directly into bounded transport bytes,
  removing copied PHP object/tag trees while keeping algorithms and semantic validation in Engine.
- Cover slot and byte-budget reuse, released-ID refusal and repeated lifecycle cleanup in PHPT;
  retain all formula, document, report, decimal and canonical owner-corpus acceptance suites.
- Pin CI actions to exact reviewed commits. Candidate only: immutable Engine verification,
  stable extension publication, full-path performance acceptance and App cutover remain separate.

- Replace canonical tagged-JSON expansion and opaque compiled-batch escaping with bounded Engine
  binary frames; retain original public PHP results, exact logical limits and fallback JSON input.
- Add full framed-batch parity/refusal PHPT and safe NUL-terminated PHP JSON slice decoding.
- Embed Engine's fused UTF-8/JSON byte accounting and immutable result quote-size reuse;
  the PHP API and its original logical input/output budgets are unchanged. Performance
  evidence must identify the exact built module; the counting optimization alone is no
  basis for a stable release or an acceleration completion claim.
- Add explicit compiled result_format "opaque" for callers consuming only result_json,
  avoiding an unused decoded PHP value. Omission or "both" preserves the original API.
  Advertise the binding-owned capability and replay compiled owner corpora in both modes;
  retain identical correlation, findings, opaque bytes, logical budgets and refusals.
