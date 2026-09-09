# Allocation and request ownership

MINIT registers two immutable class definitions and handlers. No process-wide Engine cache or request data exists. Runtime objects are ordinary Zend request allocations; the custom free handler destroys its plan hash before standard object destruction. RINIT/RSHUTDOWN need no separate resource registry because the Zend object store owns every Runtime, including exception and request-shutdown paths. No persistent state survives requests.

| Allocation | Owner | Transfer / release | Bound |
|---|---|---|---|
| Directly marshalled JSON request | Zend request | Borrowed only for the synchronous C call; smart_str_free | 64 levels, 262,144 nodes, 16 MiB conservative pre-encoding budget |
| Native immutable plan | One Runtime | Stored only after successful compile/describe; Engine plan_release on error, explicit release or object destruction | 64 plans and aggregate 16 MiB encoded source |
| Native result/descriptor | Calling method | Engine buffer_release exactly once, including PHP bailout during decoding | 16 MiB ordinary result; 64 MiB canonical framed envelope/result |
| Native cancellation | Calling execute method | Requested before synchronous call; released immediately afterwards | One optional token per call |
| Canonical framed byte buffer | Zend request | Direct raw value/key transport; no intermediate zval graph or retained caller pointers | 65 levels plus sentinel, 262,144 nodes, 32 MiB raw strings/keys, 64 MiB wire envelope |

The canonical Engine applies the semantic limits (default depth 64, nodes 100,000, output 8 MiB, input 16 MiB) and determines stable finding precedence. The larger binding envelope is solely a bound on framed representation overhead. Opaque program/document JSON is parsed inside Engine so PHP does not round or coerce native numeric input.

A random 128-bit plan ID has no pointer or allocator meaning and is checked only against its owning instance. Explicit `release($planId)` deletes that entry and refunds its exact source-byte charge; repeated or foreign release fails before mutating ownership. The caller decides plan lifetime; there is no hidden eviction of a still-live plan. Cloning and serialization are prohibited. There is no persistent cache, borrowed zval lifetime, thread sharing or callback into PHP. PHP 8.5 NTS and ZTS are both admitted: class entries and handlers are registered once at module startup and never mutated afterwards, every Runtime and plan is request-local, and the Engine C ABI is reentrant.

PHPT exercises hostile values, cyclic/shared references, owner isolation, cancellation, capacity, corpus parity and partial refusals. Valgrind runs 100 allocate/compile/execute/refuse/destroy owners and 7000 explicit plan releases with Zend's allocator disabled. CI evidence establishes only its recorded tuple; sanitizer/fuzz jobs record their actual execution, while wider platforms and release verification remain separate publication gates.

KEB1/KER2 slices carry opaque input and result JSON without redundant outer escaping. The binding
retains the same conservative admission and public result arrays; the Engine charges equivalent
logical JSON input/output budgets. PHP JSON decoding receives an owned NUL-terminated slice
because its scanner requires termination beyond the explicit byte length. The temporary slice
and native response are released on normal return, parser refusal and Zend bailout.

With compiled `result_format: "opaque"`, framed payloads stay in their original Engine-owned
JSON representation until copied into the returned `result_json` string. Portable findings are
still decoded; the unused `result` PHP tree is omitted. The default retains both representations.
All nodes are attached to the owned return value before subsequent child allocation, and the same
native-buffer bailout guard applies. Original logical input/output limits remain charged by Engine
in either mode; a smaller PHP representation cannot admit a previously oversized response.
