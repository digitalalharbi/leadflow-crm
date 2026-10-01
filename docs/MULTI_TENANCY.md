# Multi-tenancy

A user of tenant A must never read, modify, or confirm the existence of tenant
B's data. Four independent mechanisms enforce that, so no single mistake is
enough to break it.

## 1. Tenant context

`App\Support\TenantContext` is a request-scoped singleton holding the active
tenant. It is set in exactly one place: `ResolveTenantContext` middleware, from
the **authenticated user**.

A client cannot select a tenant. Not by header, not by body, not by query string.

```php
$tenant = $user->tenant()->first();   // the only source
$this->context->set($tenant);
```

## 2. Global scope

`BelongsToTenant` adds `TenantScope` to every model holding customer data:

```php
$builder->where($table.'.tenant_id', $tenantId ?? -1);
```

The `?? -1` matters. With no tenant bound, the query returns **nothing** rather
than everything. A missing context can never widen a result set.

## 3. Write-side guards

The same trait:

* stamps `tenant_id` on create from the active context;
* **throws** if a tenant-scoped model is created with no context;
* **throws** if `tenant_id` is ever changed on an existing row.

`User` adds one more: only a super admin may exist without a tenant. An orphan
account would be unreachable by any policy, so the model refuses to create one.

## 4. Policies

Policies run on top of the scope and add the role layer:

```php
public function view(User $user, Lead $lead): bool
{
    if (! $user->belongsToTenant($lead->tenant_id)) {
        return false;                       // belt
    }

    return $user->seesAllLeads() || $lead->assigned_to === $user->id;   // braces
}
```

The tenant check is redundant while the scope is in place — which is the point.
It is the thing that still holds if someone later writes a query without it.

## Roles

| Role | Tenant | Sees |
| --- | --- | --- |
| `super_admin` | none | The platform console only. No tenant workspace at all. |
| `admin` | one | Everything in their tenant, including settings and credentials |
| `manager` | one | All leads, reports, assignment. Not settings or integrations |
| `sales` | one | Only leads assigned to them |

## Deliberate escape hatches

Two operations legitimately span tenants:

```php
$context->withoutScope(fn () => Tenant::all());        // super admin reads
$context->withTenant($tenant, fn () => /* ... */);     // jobs, ingestion, seeding
```

Both are explicit, both are narrow, and `withoutScope` appears only in
super-admin controllers and the webhook endpoint (which must find a tenant from
an endpoint key before any context exists).

## Subdomains

`client1.example.com` resolves to the tenant with slug `client1`. This is used
for **branding on the login screen only** — never for authorisation. After login
the tenant comes from the account, so landing on the wrong subdomain shows the
wrong logo, not the wrong data.

Reserved slugs (`www`, `api`, `app`, `admin`, `staging`, …) can never be taken.

## Adding a tenant-scoped table

1. Add `$table->foreignId('tenant_id')->constrained()->cascadeOnDelete();`
2. Index it together with whatever you filter on: `['tenant_id', 'status']`
3. Add the `BelongsToTenant` trait to the model
4. Write a policy
5. Add a case to `TenantIsolationTest`

Step 5 is not optional. It is the test that proves the other four worked.

## Verifying it

```bash
cd backend && php artisan test --filter=TenantIsolationTest
```

18 tests, including the one that matters most: a user who knows another tenant's
exact lead UUID gets a 404, with no data in the body.
