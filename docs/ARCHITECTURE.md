# Architecture

## Principle

Every decision here was taken against one question: *does this make the product
ship sooner and stay maintainable?* Where a more sophisticated option existed,
it was taken only when it paid for itself inside the MVP.

## Shape

```
┌──────────────────────────────────────────────────────────────┐
│  React 19 SPA (Vite, TypeScript, Tailwind)                   │
│  • Branding resolved at runtime from the API                 │
│  • TanStack Query owns server state; Zustand owns UI state   │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTPS, Bearer token (Sanctum)
┌───────────────────────────▼──────────────────────────────────┐
│  Nginx → php-fpm → Laravel 12 (API only)                     │
│                                                              │
│  Route → Middleware → Form Request → Controller (thin)       │
│        → Service / Action → Model (global tenant scope)      │
│        → API Resource                                        │
└───────┬───────────────────────────────┬──────────────────────┘
        │                               │
┌───────▼─────────┐           ┌─────────▼──────────┐
│  PostgreSQL 16  │           │  Redis 7           │
│  source of truth│           │  cache, queue      │
└─────────────────┘           └─────────┬──────────┘
                                        │
                              ┌─────────▼──────────┐
                              │ Queue worker       │
                              │ Horizon            │
                              │ Scheduler          │
                              └────────────────────┘
```

## Layers

**Controllers are thin.** They authorise, hand off, and shape a response. No
controller contains business logic.

**Services hold the logic.** `LeadIngestionService` is the clearest example: it
is the only code path that writes a lead, whether that lead came from a webhook,
a CSV row, a Google Sheet or a rep typing it in. That single door is what makes
de-duplication, attribution and assignment behave identically everywhere.

**Repositories were not introduced.** Eloquent is already a data-access layer.
Wrapping it in a second one would have added files without removing a problem.

**DTOs are used where a shape crosses a boundary** — `IncomingLead` and
`IngestionResult`. Elsewhere, arrays and models are fine.

## Decisions and their trade-offs

### Single database with `tenant_id`, not a database per tenant

A database per tenant gives stronger isolation but makes migrations, backups,
connection pooling and cross-tenant reporting dramatically harder. For an MVP
that must onboard a customer in seconds, a shared schema with a enforced-by-default
global scope is the right trade. The scope plus policies plus an explicit test
suite (`TenantIsolationTest`) carry the weight.

**The cost:** isolation is now a code property, not an infrastructure property.
That is why it is tested as its own CI step, and why `TenantScope` falls back to
`tenant_id = -1` rather than "no filter" when no tenant is bound — a missing
context returns nothing instead of everything.

### Sequential primary keys, UUIDs on the surface

Integer keys keep joins and indexes fast. Every API addresses records by UUID, so
sequential ids are never exposed and cannot be enumerated.

### Token auth, not sessions

Sanctum tokens suit a SPA that may later be a mobile app. One token per device,
so signing in on a second machine does not kill the first session; changing a
password revokes every token except the current one.

### Polling, not WebSockets

Notification volume in this product is low — a few events per rep per day. A
60-second poll costs one cheap query and removes an entire piece of
infrastructure. `NotificationController` is the only thing that would change if
real-time is needed later.

### Timestamps are stored and returned in UTC

An earlier version set PHP's timezone per tenant inside the middleware. That
shifted stored values and would have leaked between jobs in a long-running queue
worker. The SPA formats in the tenant's timezone, which is served in the branding
payload.

### Branding is data, not configuration

The React app contains no product name, no colour, no logo. It reads
`/api/v1/branding` — which is public, because the login screen has to render a
customer's identity before anyone has signed in. That one endpoint is what makes
the product sellable as someone else's.

## Where it goes next

The structure anticipates, without building:

* **Real-time** — swap the notification poll for broadcasting.
* **Custom domains** — tenant resolution already goes through one function.
* **Billing** — `plan`, `subscription_status`, `trial_ends_at` and
  `subscription_ends_at` already exist and are already enforced by middleware.
* **A new lead source** — implement `LeadProviderInterface`; nothing downstream
  changes.
* **Database-per-tenant** — `TenantContext` is the only place that would need to
  know, because nothing else resolves a tenant on its own.
