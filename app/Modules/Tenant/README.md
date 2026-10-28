# Tenant Module

Purpose: Document tenant boundary concerns. Runtime tenant context lives in `app/Shared/Tenant`.

The former `/tenant-context-test` diagnostic route is no longer registered in the application.
Tenant middleware is exercised through a test-only route in `TenantIsolationTest`.

Dependency notes: Tenant context is shared infrastructure. Modules should not duplicate tenant resolution or `X-Company-ID` behavior.
