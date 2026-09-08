# Security

Report suspected vulnerabilities privately through the repository's GitHub security advisory channel. Include the extension/Engine source tuple, PHP build tuple and a minimal non-sensitive reproducer. Do not include customer payloads or secrets.

Kumwe repository maintainers own advisory triage, coordinated disclosure and security patch releases.
The newest published 1.x source release on PHP 8.5 NTS/Linux x86_64 is the supported line;
consumers must use its latest patch. Development snapshots, superseded patches, ZTS and
unqualified platforms are unsupported. A security update rebuilds the complete PHP/extension/Engine
image tuple and passes the native, corpus, sanitizer and offline-install gates. It never replaces a
loaded module in place. No response time or support duration is promised by this policy.

Runtime failures expose bounded status codes without payload contents or compiler-dependent
diagnostics. Security-sensitive ownership and semantic admission remain in their designated App
and Engine owners. Embedded PCRE2, Unicode and owner corpora retain their exact Engine source and
advisory records; each binding release requires independent verification of that Engine release.
