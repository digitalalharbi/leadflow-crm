# Performance

Measured against a single tenant holding **10,000 leads**, through the real
middleware and controller stack (`php artisan leadflow:seed-load-test`).

## Results

| Endpoint | Queries | Time | Payload |
| --- | ---: | ---: | ---: |
| Leads, page 1 | 5 | 15 ms | 18 KB |
| Leads, page 100 | 5 | 17 ms | 18 KB |
| Leads, search by name | 5 | 17 ms | 18 KB |
| Leads, search by phone | 5 | 18 ms | 1 KB |
| Leads, status filter | 5 | 15 ms | 18 KB |
| Leads, combined filters | 5 | 34 ms | 18 KB |
| Leads, overdue | 5 | 37 ms | 18 KB |
| Dashboard | 15 | 39–60 ms | 10 KB |
| Pipeline (6 columns) | 6 | 90 ms | 104 KB |
| Follow-ups, overdue | 2 | 4 ms | — |
| Follow-up counts | 4 | 4 ms | — |
| Reports (each type) | 2 | 6–16 ms | 1–5 KB |
| Users / Projects | 2 | 11–13 ms | 1 KB |

No endpoint exceeds 120 ms or 110 KB at this volume, and query counts are flat
with respect to the number of leads — the only counts that scale are with the
number of *columns* or *charts* on a screen, which are fixed.

## What was changed, and why

### Phone search stopped scanning text columns — 209 ms → 18 ms

A term made of digits was matched against `full_name`, `email` **and** `phone`
with leading-wildcard `ILIKE`, which no index can serve. Nobody searching a phone
number wants it matched against names, so a numeric term now goes straight to the
indexed `(tenant_id, phone_normalized)` lookup, with a `LIKE` on the raw column as
a fallback for a half-remembered number. Arabic-Indic digits are converted first,
so `٠٥١٢٣٤٥٦٧٨` takes the same path as `0512345678`.

### Name and email search became index-backed — 73 ms → 17 ms

Added GIN trigram indexes on `full_name` and `email`. `pg_trgm` needs privileges
some managed PostgreSQL plans withhold, so the migration degrades to the previous
behaviour and logs a warning rather than failing a deploy.

### The dashboard does fewer passes — 19 queries → 15

Four separate `COUNT`s over the same filtered set (hot, unassigned, new today,
appointments) became one aggregate `FILTER` pass, and the three follow-up bucket
counts became one. This is the most-requested endpoint in the product, and the
set it counts grows with the tenant.

### A partial index for the overdue views

```sql
CREATE INDEX leads_open_follow_up_index ON leads (tenant_id, next_follow_up_at)
WHERE next_follow_up_at IS NOT NULL AND status NOT IN ('won','lost') AND deleted_at IS NULL
```

The overdue list only ever looks at open leads with a follow-up, which is a small
fraction of the table.

### The pipeline sends half the bytes — 208 KB → 104 KB

A column shows about six cards before it scrolls, so fetching fifty per column was
sending 200 KB to render a fraction of it. The default is now 25; each column
still reports its true total, so nothing is hidden.

The board had also been issuing a count plus a fetch plus two eager loads **per
column** — roughly 24 queries. It is now one windowed query plus one grouped
count, so adding a stage would not add queries.

## Guardrails that keep it this way

* `Model::preventLazyLoading()` outside production, so a missing eager load fails
  loudly in development instead of quietly costing hundreds of queries later.
* Server-side pagination everywhere, capped at 100 per page.
* `LeadQueryBuilder` allow-lists sortable columns; no user input reaches `DB::raw`.
* A test asserts the pipeline's query count does not grow with the number of
  stages, and another asserts a numeric search never touches the name columns.

## Reproducing

```bash
cd backend
php artisan leadflow:seed-load-test --leads=10000 --tenant=loadtest
```

The command refuses to run in production, and the tenant it fills must already
exist.

## Deliberately not done

PostgreSQL is comfortably sufficient at this scale and well beyond it. No search
engine, no read replica, no caching layer — each would add an operational burden
the numbers above do not justify. Revisit when a single tenant passes roughly
100k leads or the dashboard moves past ~200 ms.
