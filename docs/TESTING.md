# Testing

```bash
# Backend — 150 tests
cd backend
createdb leadflow_testing
php artisan test
./vendor/bin/pint --test

# Frontend — 32 tests
cd frontend
npm run lint && npm run typecheck && npm run test && npm run build
```

## Why PostgreSQL in tests

The suite runs against PostgreSQL, not SQLite. The application uses `jsonb`,
`ilike` and aggregate `FILTER (WHERE …)`. A SQLite suite would pass while
production broke — the opposite of what a test is for.

## Backend coverage

| Suite | Tests | What it proves |
| --- | --- | --- |
| `TenantIsolationTest` | 18 | A tenant cannot reach another tenant's data by any route |
| `LeadIngestionTest` | 19 | The public webhook accepts real leads and refuses everything else |
| `LeadManagementTest` | 23 | Lead lifecycle, role visibility, duplicate handling, export |
| `AuthenticationTest` | 12 | Login, lockout, token revocation, suspension, expiry |
| `CsvImportTest` | 8 | Upload, mapping, duplicates, failures, file cleanup |
| `SuperAdminTest` | 8 | Tenant provisioning, suspension, platform stats |
| `WhiteLabelTest` | 8 | Branding is public, isolated, and leaks nothing operational |
| `FollowUpTest` | 7 | Buckets, completion, visibility |
| `PhoneNormalizerTest` | 20 | Every Saudi format collapses to one key |
| `LeadScorerTest` | 4 | Scoring and temperature thresholds |
| `CrossTenantInjectionTest` | 8 | A foreign key from an untrusted payload can never reach another tenant's row |
| `SecurityHardeningTest` | 15 | Regressions for every finding in the pre-release security review |

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

## CI

Both suites run on every push and pull request to `main` and `develop`. The
backend matrix covers PHP 8.3 and 8.4. A failing lint, type check, test or build
blocks the deploy.

## Not covered

Honest gaps:

* No automated browser E2E. The flows were verified manually in a real browser
  (login → dashboard → leads → detail → status → note → pipeline drag → mobile),
  but there is no Playwright suite yet. That is the first thing to add.
* No load testing.
* Provider `fetch()` methods are tested with mocks only, since live credentials
  are not available.
