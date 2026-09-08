# Changelog

## Unreleased

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
