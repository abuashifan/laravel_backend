# Shared

Shared contains cross-module infrastructure that is intentionally available to multiple modules. Phase M1 only establishes ownership boundaries; existing shared implementations remain in their current namespaces.

Cross-module behavior should move here only when it is truly shared infrastructure such as API responses, audit logging, document numbering, source document helpers, transaction lifecycle rules, tenant context, or generic support utilities.
