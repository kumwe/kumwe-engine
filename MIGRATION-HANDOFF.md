# Candidate Zend cross-build handoff

Change set: NRM-2026-043. Roadmap impact: None; enabling native binding infrastructure. Draft PR: https://github.com/kumwe/kumwe-engine/pull/1. Phase: candidate cross-build. State: package-implemented candidate; no release or App integration claim.

The PIE package `kumwe/kumwe-engine` installs module `kumwe_engine`, exposed to Composer as `ext-kumwe_engine`. Actual native classes are exclusively `Kumwe\Engine\Runtime` and `Kumwe\Engine\Exception\BindingFailure`; stubs cannot autoload. `resources/api/v1.json` defines the public surface. The native reference package retains its historical portable baseline; it does not implement these classes.

Engine source identity is recorded in `resources/engine-lock.json`. This candidate must not merge or publish as stable extension Phase 1: that task requires an immutable Engine release and successful independent release attestation, then a new exact embedded-source lock and final-head extension gates. The current committed source snapshot is a permitted development cross-build, not a release substitute.

CI builds PHP 8.5 NTS Linux x86_64, executes PHPT/canonical corpus parity and Valgrind lifetime checks, and installs the exact source archive through PIE with networking disabled. Source digests and generated arginfo must match. Final evidence is the actual workflow at the final PR head; no local compile is claimed when development headers are unavailable.

After independently verified publication, extension Phase 2 provisions the exact PIE artifact and checks the complete extension/Engine ABI, capability, corpus, build and source tuple before Composer validation. It only owns platform provisioning/readiness. Computation Phase 2 exclusively owns App business-runtime cutover and removal of old App implementations/tests, after provisioning is human-merged and green. App retains all database, authorization, transaction, reference resolution and delivery authority.

Rollback restores the entire last-known-good image, extension, Engine, Composer lock and configuration tuple. It never selects a PHP implementation or replaces a loaded shared object in place.
