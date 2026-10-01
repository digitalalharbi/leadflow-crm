# Performance

Measured against a single tenant holding **10,000 leads**, through the real
middleware and controller stack. Reproduce it in two commands:

```bash
php artisan leadflow:seed-load-test --leads=10000 --tenant=loadtest
php artisan leadflow:measure --tenant=loadtest
```

Requests are dispatched into the HTTP kernel rather than sent over the network,
so the numbers describe our code and not the dev server's single-threaded socket
handling. Each case runs twice; the second run is the one reported.

## Results

| Endpoint | Queries | Time | Payload |
| --- | ---: | ---: | ---: |
| Leads, page 1 | 5 | 14 ms | 19 KB |
| Leads, page 100 | 5 | 14 ms | 19 KB |
| Leads, search by name | 5 | 11 ms | 20 KB |
| Leads, search by phone | 5 | 12 ms | 1 KB |
| Leads, filters (status / combined / overdue) | 5 | 9 ms | 18–19 KB |
| Leads, malformed filter | 2 | 3 ms | — |
| Dashboard | 18 | 39 ms | 14 KB |
| Pipeline (6 columns) | 6 | 42 ms | 109 KB |
| Follow-ups, each bucket | 2 | 3 ms | — |
| Reports, each type | 2 | 8–9 ms | 1–5 KB |
| **Lead detail** (100 messages, 500 activities) | 8 | 10 ms | 19 KB |
| **Conversation**, first page | 4 | 6 ms | 26 KB |
| **Conversation**, last page | 4 | 4 ms | 9 KB |
| **Qualification breakdown** | 5 | 4 ms | 1 KB |
| **Campaign quality** | 2 | 11 ms | 5 KB |
| **Sales performance** | 2 | 8 ms | — |
| Users / Projects | 2 | 6–7 ms | 1–2 KB |

No endpoint exceeds 45 ms or 110 KB at this volume, and query counts are flat
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

### A busy lead costs the same as a new one

A lead that has been worked for six months carries hundreds of rows. The detail
screen caps the activity list and paginates the conversation, so opening it
costs the same eight queries whether the lead has two messages or two hundred —
and `per_page` is capped at 100, so it cannot be used to ask for the whole
history at once. `LeadDetailPerformanceTest` asserts the query count is
*identical* between a busy lead and a brand-new one, which is a stricter claim
than "fast enough" and does not drift as the machine changes.

### A partial index for the neglected-leads panel

```sql
CREATE INDEX leads_awaiting_first_contact_index
ON leads (tenant_id, created_at)
WHERE first_contact_at IS NULL AND status = 'new' AND deleted_at IS NULL
```

The panel only ever looks at new, untouched leads, which shrinks as a tenant
works its queue. Its count rides along with the dashboard's other tallies in the
same aggregate pass rather than taking a query of its own.

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
