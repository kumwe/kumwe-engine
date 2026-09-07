# Allocation and request ownership

MINIT registers two immutable class definitions and handlers. No process-wide Engine cache or request data exists. Runtime objects are ordinary Zend request allocations; the custom free handler destroys its plan hash before standard object destruction. RINIT/RSHUTDOWN need no separate resource registry because the Zend object store owns every Runtime, including exception and request-shutdown paths. No persistent state survives requests.

| Allocation | Owner | Transfer / release | Bound |
|---|---|---|---|
| Sanitized JSON tree and encoded request | Zend request | Borrowed only for the synchronous C call; zval destructors / smart_str_free | 64 levels, 262,144 nodes, 16 MiB conservative pre-encoding budget |
| Native immutable plan | One Runtime | Stored only after successful compile/describe; Engine plan_release on error or object destruction | 64 plans and aggregate 16 MiB encoded source |
| Native result/descriptor | Calling method | Engine buffer_release exactly once, including PHP bailout during decoding | 16 MiB ordinary result; 64 MiB canonical tagged envelope/result |
| Native cancellation | Calling execute method | Requested before synchronous call; released immediately afterwards | One optional token per call |
| Canonical tagged tree | Zend request | Detached raw value/key transport; no borrowed caller pointers retained | 65 levels plus sentinel, 262,144 nodes, 32 MiB raw strings/keys, 64 MiB JSON |

The canonical Engine applies the semantic limits (default depth 64, nodes 100,000, output 8 MiB, input 16 MiB) and determines stable finding precedence. The larger binding envelope is solely a bound on tagged representation overhead. Opaque program/document JSON is parsed inside Engine so PHP does not round or coerce native numeric input.

A random 128-bit plan ID has no pointer or allocator meaning and is checked only against its owning instance. Cloning and serialization are prohibited. There is no persistent cache, borrowed zval lifetime, thread sharing or callback into PHP. Only PHP 8.5 NTS is admitted in this candidate.

PHPT exercises hostile values, cyclic/shared references, owner isolation, cancellation, capacity, corpus parity and partial refusals. Valgrind runs 100 allocate/compile/execute/refuse/destroy cycles with Zend's allocator disabled. CI evidence establishes only its recorded tuple; wider platform/sanitizer/fuzz and release verification remain separately required before publication.
