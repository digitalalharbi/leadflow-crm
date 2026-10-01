# Testing

```bash
# Backend — 226 tests
cd backend
createdb leadflow_testing
php artisan test
./vendor/bin/pint --test

# Frontend — 32 unit tests
cd frontend
npm run lint && npm run typecheck && npm run test && npm run build

# Browser — 74 Playwright tests (needs the API and the SPA running)
npm run test:e2e
```

## Why PostgreSQL in tests

The suite runs against PostgreSQL, not SQLite. The application uses `jsonb`,
`ilike` and aggregate `FILTER (WHERE …)`. A SQLite suite would pass while
production broke — the opposite of what a test is for.

## Backend coverage

| Suite | Tests | What it proves |
| --- | --- | --- |
| `TenantIsolationTest` | 18 | A tenant cannot reach another tenant's data by any route |
| `WebhookReadinessTest` | 24 | The public endpoint under every condition a real ad platform produces |
| `LeadManagementTest` | 23 | Lead lifecycle, role visibility, duplicate handling, export |
| `LeadIngestionTest` | 19 | The public webhook accepts real leads and refuses everything else |
| `SecurityHardeningTest` | 17 | Regressions for every finding in both security reviews |
| `AuthenticationTest` | 12 | Login, lockout, token revocation, suspension, expiry |
| `CsvImportTest` | 8 | Upload, mapping, duplicates, failures, file cleanup |
| `CrossTenantInjectionTest` | 8 | A foreign key from an untrusted payload can never reach another tenant's row |
| `FollowUpTest` | 8 | Buckets — disjoint and exhaustive — completion, visibility |
| `SuperAdminTest` | 8 | Tenant provisioning, suspension, platform stats |
| `WhiteLabelTest` | 8 | Branding is public, isolated, and leaks nothing operational |
| `PhoneNormalizerTest` | 6 (20 cases) | Every Saudi format collapses to one key |
| `LeadSearchTest` | 6 (10 cases) | Name, email and phone search, in Arabic and in Arabic-Indic digits |
| `AssignmentIntegrityTest` | 5 | Only someone who may reassign may choose an owner, and only an active colleague |
| `ExportSafetyTest` | 4 (9 cases) | A formula submitted through the webhook cannot execute in the buyer's spreadsheet |
| `LeadScorerTest` | 4 | Scoring and temperature thresholds |
| `DemoEndpointTest` | 4 | The demo lead generator cannot exist in production |
| `MalformedInputTest` | 3 (21 cases) | A bad identifier is a 404, on every route and filter |

### The test that matters most

```php
public function test_reading_another_tenants_lead_by_uuid_returns_404(): void
{
    // The attacker knows the exact UUID. It must still be invisible.
    $this->actingAs($this->a['admin'])
        ->getJson("/api/v1/leads/{$this->leadB->uuid}")
        ->assertNotFound()
        ->assertJsonMissing(['full_name' => 'Beta Lead']);
}
```

It runs as its own CI step so that if it ever fails, the reason is unmistakable.

## Frontend coverage

| Suite | Tests | What it proves |
| --- | --- | --- |
| `format.test.ts` | 12 | WhatsApp links, currency, budget ranges — including that an unfilled `{{project}}` is never sent to a customer |
| `auth.test.ts` | 8 | Token lifecycle and every role gate |
| `useApiError.test.tsx` | 5 | Statuses become human messages; a raw SQL error never reaches the UI |
| `Badge.test.tsx` | 3 | Every stage and temperature renders with the right label |
| `LeadFormModal.test.tsx` | 4 | Submit, duplicate notice, field validation, role visibility |

## Bugs these tests caught

Worth recording, because they are the argument for writing them:

1. **Modal focus theft** — `Modal`'s focus effect depended on an inline
   `onClose`, so it re-ran on every render and pulled focus out of the field
   being typed in. Typing broke after one character in every dialog, in the real
   browser. Caught by `LeadFormModal.test.tsx`.
2. **Stale form state** — every form used `setForm({ ...form })`, which spreads a
   closure React batching can leave stale. Switched to the functional updater.
3. **Branding resolved to null before login** — `TenantBranding` carried the
   tenant read scope, but the login screen has no tenant context, so the customer's
   product name silently fell back to their company name. Caught by
   `AuthenticationTest`.
4. **Timezone mutation** — the middleware called `date_default_timezone_set()`
   per request, shifting stored timestamps and leaking between queue jobs. Caught
   by `FollowUpTest`.
5. **Orphan users** — the demo seeder created sales reps with `tenant_id = NULL`,
   the super-admin shape. Now impossible: the model refuses it.
6. **Round-robin stealing manual leads** — a rep's hand-entered lead was being
   reassigned to someone else. Caught during the browser walkthrough; now covered
   by a test.
7. **Dashboard filters returned `0`** — `uuid as id` collided with Eloquent's
   integer cast on the primary key, so filtering silently did nothing.
8. **Cross-tenant project leak (critical)** — found in the pre-release security
   review; see the table in `SECURITY.md`. Eight tests now cover every entry
   point an internal foreign key could arrive through.
9. **Nine further security defects** from the same review, each with a
   regression test in `SecurityHardeningTest`.

## Browser coverage

74 Playwright tests across 11 specs, run against the real API and the real SPA.

| Spec | Tests | What it proves |
| --- | --- | --- |
| `leads.spec.ts` | 12 | List, search, filters, pagination, creation, opening a lead |
| `dashboard.spec.ts` | 8 | Every headline number loads, and each one links to the list it names |
| `auth.spec.ts` | 7 | Sign in, refuse bad credentials, survive a reload, sign out |
| `follow-ups.spec.ts` | 7 | Today, upcoming and overdue buckets, and completing one |
| `white-label.spec.ts` | 7 | A second tenant renders its own name, logo and colour |
| `tenant-isolation.spec.ts` | 7 | One tenant cannot reach another's data, in the browser or through the API |
| `activities.spec.ts` | 6 | Notes, calls, the WhatsApp link, and the status-change timeline |
| `lead-workflow.spec.ts` | 6 | New → Contacted → Qualified → Appointment → Won, and Lost with a reason |
| `mobile.spec.ts` | 5 | The drawer, the stacked list and working a lead on a Pixel 7 |
| `pipeline.spec.ts` | 4 | Drag between columns, and the move reaching the backend and the timeline |
| `tenant-onboarding.spec.ts` | 5 | A new client is created from the platform screen and signs in, with no code change |

The fixtures come from a dedicated seeder (`leadflow:seed-e2e`), not the demo
data: a suite that depends on data someone may reseed is a suite that will go red
for no reason. Specs sign in by injecting an API token rather than driving the
login form — except the auth spec, which is about the form.

## CI

Every suite runs on each push and pull request to `main` and `develop`: backend
on PHP 8.3 and 8.4, frontend lint/type-check/unit/build, and the Playwright suite
against PostgreSQL and Redis services. A failure in any of them blocks the merge
and the deploy.

## Not covered

Honest gaps:

* No load testing beyond the 10,000-lead performance pass in
  [PERFORMANCE.md](PERFORMANCE.md) — no concurrency testing.
* Provider `fetch()` methods are tested with mocks only, since live credentials
  are not available.
* The E2E suite runs one worker. It is deterministic, but it is not fast.
