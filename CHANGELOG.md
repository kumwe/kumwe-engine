# Changelog

## Unreleased

- NRM-2026-043: implement the candidate Zend Runtime and BindingFailure, with bounded native
  compile/execute/cancellation, typed canonical transport and exact source/build identity.
- NRM-2026-044: add `Runtime::release(string $planId): void` so a long-lived owner can reclaim
  individual plan slots and the exact encoded-source budget. Foreign, malformed and already
  released IDs fail without changing live plans; object destruction releases remaining plans.
- Marshal admitted arrays and canonical value/key tags directly into bounded JSON bytes,
  removing copied PHP object/tag trees while keeping algorithms and semantic validation in Engine.
- Cover slot and byte-budget reuse, released-ID refusal and repeated lifecycle cleanup in PHPT;
  retain all formula, document, report, decimal and canonical owner-corpus acceptance suites.
- Pin CI actions to exact reviewed commits. Candidate only: immutable Engine verification,
  stable extension publication, full-path performance acceptance and App cutover remain separate.

- Replace canonical tagged-JSON expansion and opaque compiled-batch escaping with bounded Engine
  binary frames; retain original public PHP results, exact logical limits and fallback JSON input.
- Add full framed-batch parity/refusal PHPT and safe NUL-terminated PHP JSON slice decoding.
