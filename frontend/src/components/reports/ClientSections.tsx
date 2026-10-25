import type { TFunction } from 'i18next'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { Donut, RankBars } from '@/components/visual'
import { platformColor } from '@/components/charts'
import type { ChannelRow, SharedReportData } from '@/features/reportShares'
import { findingText } from '@/lib/findings'
import { cn } from '@/lib/cn'
import type { ReportCtx } from './ReportSections'

/**
 * The sections a client reads after the funnel: where the leads came from and what they cost, what sales did with them,
 * how good they were, what was lost, per project and unit, through the tracking hub, what came in, and what needs a
 * person. Each shows only what the server sent — a figure that was not sent is not drawn, and a figure that is null is
 * “not available”, never a zero.
 */

function NA() {
  const { t } = useTranslation()

  return <span className="text-[var(--color-ink-subtle)]">{t('rsec.unavailable')}</span>
}

const channelLabel = (t: TFunction, key: string) => t(`rsec.channel.${key}`, { defaultValue: t(`platforms.${key}`, { defaultValue: key }) })

function Table({ head, children, testId }: { head: React.ReactNode; children: React.ReactNode; testId?: string }) {
  return (
    <div className="scroll-x">
      <table className="w-full border-collapse text-[0.875rem]" data-testid={testId}>
        <thead><tr className="text-[var(--color-ink-muted)]">{head}</tr></thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  )
}

function Stat({ label, value, testId }: { label: string; value: React.ReactNode; testId?: string }) {
  return (
    <div className="rounded-xl bg-[var(--color-surface-muted)] p-2 text-center" data-testid={testId}>
      <p className="t-metric" dir="ltr">{value}</p>
      <p className="t-caption">{label}</p>
    </div>
  )
}

/** Platform-reported and Mohtam-attributed numbers, in two blocks that are never merged. */
export function MarketingSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.marketing

  if (!d) return null

  const reported = d.platform_reported
  const spend = reported.some((r) => 'spend' in r)
  const money = ctx.data.marketing?.mohtam_attributed.some((r) => 'cpl' in r) ?? false
  const earnings = d.mohtam_attributed.some((r) => 'revenue' in r)
  const cellN = (v: number | null | undefined) => (v == null ? <NA /> : ctx.num(v))

  return (
    <Card data-testid="shared-marketing"><CardBody className="space-y-5">
      <h2 className="t-card-title">{t('rsec.marketing.title')}</h2>

      {!d.has_ad_data && <p className="t-caption rounded-lg bg-[var(--color-surface-muted)] p-3" data-testid="shared-marketing-noads">{t('rsec.marketing.noAdAccount')}</p>}

      {reported.length > 0 && (
        <section data-testid="shared-marketing-reported">
          <h3 className="t-small font-semibold">{t('rsec.marketing.reported')}</h3>
          <p className="t-caption mb-2">{t('rsec.marketing.reportedHint')}</p>
          <Table testId="shared-reported-table" head={<><th className="p-1.5 text-start">{t('rsec.col.platform')}</th>{spend && <th>{t('rsec.col.spend')}</th>}<th>{t('rsec.col.impressions')}</th><th>{t('rsec.col.clicks')}</th><th>{t('rsec.col.ctr')}</th>{spend && <th>{t('rsec.col.cpc')}</th>}</>}>
            {reported.map((r) => (
              <tr key={r.key} className="border-t border-[var(--color-line)] text-center tabular-nums">
                <td className="p-1.5 text-start font-medium"><span aria-hidden className="me-2 inline-block size-2.5 rounded-full" style={{ background: platformColor(r.key) }} />{channelLabel(t, r.key)}</td>
                {spend && <td dir="ltr">{r.spend == null ? <NA /> : ctx.money(r.spend)}</td>}
                <td dir="ltr">{cellN(r.impressions)}</td><td dir="ltr">{cellN(r.clicks)}</td><td dir="ltr">{r.ctr == null ? <NA /> : `${r.ctr}%`}</td>
                {spend && <td dir="ltr">{r.cpc == null ? <NA /> : ctx.money(r.cpc)}</td>}
              </tr>
            ))}
          </Table>
        </section>
      )}

      {d.mohtam_attributed.length > 0 && (
        <section data-testid="shared-marketing-attributed">
          <h3 className="t-small font-semibold">{t('rsec.marketing.attributed')}</h3>
          <p className="t-caption mb-2">{t('rsec.marketing.attributedHint')}</p>
          <Table testId="shared-attributed-table" head={<>
            <th className="p-1.5 text-start">{t('rsec.col.source')}</th><th>{t('rsec.col.leads')}</th><th>{t('rsec.col.qualified')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th><th>{t('rsec.col.won')}</th>
            {money && <><th>{t('rsec.col.cpl')}</th><th>{t('rsec.col.cac')}</th></>}{earnings && <th>{t('rsec.col.revenue')}</th>}{money && earnings && <th>{t('rsec.col.roas')}</th>}
          </>}>
            {d.mohtam_attributed.map((r) => (
              <tr key={r.key} className="border-t border-[var(--color-line)] text-center tabular-nums">
                <td className="p-1.5 text-start font-medium">{channelLabel(t, r.key)}</td>
                <td>{ctx.num(r.leads)}</td><td>{ctx.num(r.qualified)}</td><td>{ctx.num(r.interested)}</td><td>{ctx.num(r.visits)}</td><td>{ctx.num(r.reservations)}</td><td>{ctx.num(r.won)}</td>
                {money && <><td dir="ltr">{r.cpl == null ? <NA /> : ctx.money(r.cpl)}</td><td dir="ltr">{r.cac == null ? <NA /> : ctx.money(r.cac)}</td></>}
                {earnings && <td dir="ltr">{r.revenue == null ? <NA /> : ctx.money(r.revenue)}</td>}
                {money && earnings && <td dir="ltr">{r.roas == null ? <NA /> : `${r.roas}x`}</td>}
              </tr>
            ))}
          </Table>
        </section>
      )}
    </CardBody></Card>
  )
}

export function SourcesSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const rows: ChannelRow[] = ctx.data.sources?.rows ?? []

  return (
    <Card data-testid="shared-sources"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.sources.title')}</h2>
      {rows.length === 0 ? <p className="t-caption">{t('rsec.sources.empty')}</p> : (
        <>
          <Donut size={120} thickness={14} legend="side" centerLabel={t('rsec.col.leads')} segments={rows.map((r) => ({ key: r.key, label: channelLabel(t, r.key), value: r.leads, color: platformColor(r.key) }))} />
          <div className="mt-3">
            <Table testId="shared-sources-table" head={<><th className="p-1.5 text-start">{t('rsec.col.source')}</th><th>{t('rsec.col.leads')}</th><th>{t('rsec.col.qualified')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th><th>{t('rsec.col.won')}</th><th>{t('rsec.col.visitRate')}</th>{rows.some((r) => r.revenue != null) && <th>{t('rsec.col.revenue')}</th>}</>}>
              {rows.map((r) => (
                <tr key={r.key} className="border-t border-[var(--color-line)] text-center tabular-nums">
                  <td className="p-1.5 text-start font-medium">{channelLabel(t, r.key)}</td>
                  <td>{ctx.num(r.leads)}</td><td>{ctx.num(r.qualified)}</td><td>{ctx.num(r.interested)}</td><td>{ctx.num(r.visits)}</td><td>{ctx.num(r.reservations)}</td><td>{ctx.num(r.won)}</td>
                  <td dir="ltr">{r.visit_rate == null ? <NA /> : `${r.visit_rate}%`}</td>
                  {rows.some((x) => x.revenue != null) && <td dir="ltr">{r.revenue == null ? <NA /> : ctx.money(r.revenue)}</td>}
                </tr>
              ))}
            </Table>
          </div>
        </>
      )}
    </CardBody></Card>
  )
}

export function SalesSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.sales

  if (!d) return null

  const items: [string, React.ReactNode][] = [
    ['received', ctx.num(d.received)], ['assigned', ctx.num(d.assigned)], ['unassigned', ctx.num(d.unassigned)], ['contacted', ctx.num(d.contacted)], ['notContacted', ctx.num(d.not_contacted)],
    ['qualified', ctx.num(d.qualified)], ['interested', ctx.num(d.interested)], ['followUps', ctx.num(d.follow_ups)], ['followUpsDone', ctx.num(d.follow_ups_done)], ['overdue', ctx.num(d.overdue_follow_ups)],
    ['appointments', ctx.num(d.appointments)], ['visits', ctx.num(d.visits)], ['reservations', ctx.num(d.reservations)], ['won', ctx.num(d.won)], ['lost', ctx.num(d.lost)],
  ]

  return (
    <Card data-testid="shared-sales"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.sales.title')}</h2>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        {items.map(([key, value]) => <Stat key={key} label={t(`rsec.sales.${key}`)} value={value} testId={`sales-${key}`} />)}
        {d.revenue != null && <Stat label={t('rsec.sales.revenue')} value={ctx.money(d.revenue)} testId="sales-revenue" />}
        <Stat label={t('rsec.sales.response')} value={d.avg_first_response_minutes == null ? <NA /> : t('rsec.sales.responseValue', { m: d.avg_first_response_minutes })} testId="sales-response" />
        <Stat label={`${t('rsec.sales.slaBreaches')} · ${t('rsec.sales.slaTarget', { m: d.sla_target_minutes })}`} value={ctx.num(d.sla_breaches)} testId="sales-sla" />
      </div>
    </CardBody></Card>
  )
}

function Group({ title, rows, name }: { title: string; rows: { key: string; count: number }[]; name?: (key: string) => string }) {
  if (rows.length === 0) return null

  return (
    <div>
      <h3 className="t-small mb-1 font-semibold">{title}</h3>
      <RankBars limit={5} items={rows.map((r) => ({ key: r.key, label: name ? name(r.key) : r.key, value: r.count }))} />
    </div>
  )
}

export function LeadQualitySection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.lead_quality

  if (!d) return null

  const any = d.interest || d.budget.length || d.purchase_timeline.length || d.financing.length || d.unit_preference.length || d.project_interest.length || d.total > 0

  return (
    <Card data-testid="shared-quality"><CardBody className="space-y-4">
      <h2 className="t-card-title">{t('rsec.quality.title')}</h2>
      {!any ? <p className="t-caption">{t('rsec.quality.empty')}</p> : (
        <>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {d.interest && (['high', 'medium', 'low'] as const).map((k) => <Stat key={k} label={t(`rsec.quality.${k}`)} value={ctx.num(d.interest![k])} testId={`quality-${k}`} />)}
            <Stat label={t('rsec.quality.qualified')} value={ctx.num(d.qualified)} testId="quality-qualified" />
            <Stat label={t('rsec.quality.unqualified')} value={ctx.num(d.unqualified)} testId="quality-unqualified" />
            <Stat label={t('rsec.quality.lost')} value={ctx.num(d.lost)} testId="quality-lost" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Group title={t('rsec.quality.budget')} rows={d.budget} name={(k) => t(`rsec.quality.budgetKey.${k}`, { defaultValue: k })} />
            <Group title={t('rsec.quality.timeline')} rows={d.purchase_timeline} />
            <Group title={t('rsec.quality.financing')} rows={d.financing} />
            <Group title={t('rsec.quality.unitPreference')} rows={d.unit_preference} />
            <Group title={t('rsec.quality.projectInterest')} rows={d.project_interest.map((p) => ({ key: p.name, count: p.count }))} />
          </div>
        </>
      )}
    </CardBody></Card>
  )
}

export function LossSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.loss

  if (!d) return null

  return (
    <Card data-testid="shared-loss"><CardBody className="space-y-3">
      <h2 className="t-card-title">{t('rsec.loss.title')}</h2>
      {d.lost === 0 ? <p className="t-caption">{t('rsec.loss.empty')}</p> : (
        <>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <Stat label={t('rsec.loss.lost')} value={ctx.num(d.lost)} testId="loss-count" />
            <Stat label={t('rsec.loss.rate')} value={d.rate == null ? <NA /> : `${d.rate}%`} testId="loss-rate" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <h3 className="t-small mb-1 font-semibold">{t('rsec.loss.reasons')}</h3>
              {d.reasons.length === 0 ? <p className="t-caption">{t('rsec.loss.noReasons')}</p> : <RankBars limit={6} items={d.reasons.map((r) => ({ key: r.label, label: r.label, value: r.count }))} />}
            </div>
            <div>
              <h3 className="t-small mb-1 font-semibold">{t('rsec.loss.stage')}</h3>
              <RankBars limit={4} items={(['early', 'interested', 'visit', 'reservation'] as const).map((k) => ({ key: k, label: t(`rsec.loss.stages.${k}`), value: d.stage[k] }))} />
            </div>
          </div>
        </>
      )}
    </CardBody></Card>
  )
}

export function ProjectsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const rows = ctx.data.projects ?? []
  const money = rows.some((r) => 'spend' in r)
  const earnings = rows.some((r) => r.revenue != null)

  return (
    <Card data-testid="shared-projects"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.projects.title')}</h2>
      {rows.length === 0 ? <p className="t-caption">{t('rsec.projects.empty')}</p> : (
        <Table testId="shared-projects-table" head={<>
          <th className="p-1.5 text-start">{t('rsec.projects.title')}</th><th>{t('rsec.col.leads')}</th><th>{t('rsec.projects.sourcesCount')}</th>{money && <th>{t('rsec.col.spend')}</th>}<th>{t('rsec.col.qualified')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th><th>{t('rsec.col.won')}</th>{earnings && <th>{t('rsec.col.revenue')}</th>}<th>{t('rsec.projects.conversion')}</th><th>{t('rsec.projects.units')}</th>
        </>}>
          {rows.map((r) => (
            <tr key={r.name} className="border-t border-[var(--color-line)] text-center tabular-nums" data-testid="shared-project-row">
              <td className="max-w-[12rem] truncate p-1.5 text-start font-medium">{r.name}</td>
              <td>{ctx.num(r.leads)}</td><td>{ctx.num(r.sources)}</td>{money && <td dir="ltr">{r.spend == null ? <NA /> : ctx.money(r.spend)}</td>}
              <td>{ctx.num(r.qualified)}</td><td>{ctx.num(r.interested)}</td><td>{ctx.num(r.visits)}</td><td>{ctx.num(r.reservations)}</td><td>{ctx.num(r.won)}</td>
              {earnings && <td dir="ltr">{r.revenue == null ? <NA /> : ctx.money(r.revenue)}</td>}
              <td dir="ltr">{r.conversion_rate == null ? <NA /> : `${r.conversion_rate}%`}</td>
              <td dir="ltr">{r.units ? `${r.units.available}/${r.units.reserved}/${r.units.sold}` : <NA />}</td>
            </tr>
          ))}
        </Table>
      )}
      {rows.some((r) => r.units) && <p className="t-caption mt-2">{t('rsec.projects.available')} / {t('rsec.projects.reserved')} / {t('rsec.projects.sold')}</p>}
    </CardBody></Card>
  )
}

export function UnitsSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.units

  if (!d) return null

  const price = d.rows.some((r) => r.price != null)
  const earnings = d.rows.some((r) => r.revenue != null)

  return (
    <Card data-testid="shared-units"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.units.title')}</h2>
      {d.rows.length === 0 ? <p className="t-caption">{t('rsec.units.empty')}</p> : (
        <Table testId="shared-units-table" head={<>
          <th className="p-1.5 text-start">{t('rsec.units.unit')}</th><th>{t('rsec.col.interested')}</th><th>{t('rsec.col.visits')}</th><th>{t('rsec.col.reservations')}</th><th>{t('rsec.col.won')}</th>{earnings && <th>{t('rsec.col.revenue')}</th>}{price && <th>{t('rsec.units.price')}</th>}<th>{t('rsec.units.availability')}</th>
        </>}>
          {d.rows.map((r) => (
            <tr key={r.unit} className="border-t border-[var(--color-line)] text-center tabular-nums" data-testid="shared-unit-row">
              <td className="p-1.5 text-start font-medium" dir="ltr">{r.unit}</td>
              <td>{ctx.num(r.interested)}</td><td>{ctx.num(r.visits)}</td><td>{ctx.num(r.reservations)}</td><td>{ctx.num(r.sold)}</td>
              {earnings && <td dir="ltr">{r.revenue == null ? <NA /> : ctx.money(r.revenue)}</td>}
              {price && <td dir="ltr">{r.price == null ? <NA /> : ctx.money(r.price)}</td>}
              <td>{t(`rsec.units.state.${r.availability}`, { defaultValue: r.availability })}</td>
            </tr>
          ))}
        </Table>
      )}
    </CardBody></Card>
  )
}

/** Traffic → Interest → Lead → Sales → Revenue. */
export function TrackingHubSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.tracking_hub

  if (!d) return null

  const items: [string, number][] = [
    ['linkViews', d.link_views], ['landingViews', d.landing_views], ['formViews', d.form_views], ['formStarts', d.form_starts], ['formSubmissions', d.form_submissions],
    ['leads', d.leads], ['qualified', d.qualified], ['interested', d.interested], ['visits', d.visits], ['reservations', d.reservations],
  ]

  return (
    <Card data-testid="shared-trackinghub"><CardBody>
      <h2 className="t-card-title">{t('rsec.trackingHub.title')}</h2>
      <p className="t-caption mb-3">{t('rsec.trackingHub.chain')}</p>
      {!d.available ? <p className="t-caption">{t('rsec.trackingHub.empty')}</p> : (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
          {items.map(([key, value]) => <Stat key={key} label={t(`rsec.trackingHub.${key}`)} value={ctx.num(value)} testId={`hub-${key}`} />)}
          {d.revenue != null && <Stat label={t('rsec.trackingHub.revenue')} value={ctx.money(d.revenue)} testId="hub-revenue" />}
        </div>
      )}
    </CardBody></Card>
  )
}

export function RevenueSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const d = ctx.data.revenue

  if (!d) return null

  return (
    <Card data-testid="shared-revenue"><CardBody className="space-y-3">
      <h2 className="t-card-title">{t('rsec.revenue.title')}</h2>
      {d.deals === 0 ? <p className="t-caption" data-testid="shared-revenue-empty">{t('rsec.revenue.empty')}</p> : (
        <>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <Stat label={t('rsec.revenue.deals')} value={ctx.num(d.deals)} testId="revenue-deals" />
            <Stat label={t('rsec.revenue.total')} value={d.total == null ? <NA /> : ctx.money(d.total)} testId="revenue-total" />
            <Stat label={t('rsec.revenue.average')} value={d.average_deal == null ? <NA /> : ctx.money(d.average_deal)} testId="revenue-average" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {d.by_source.length > 0 && <div><h3 className="t-small mb-1 font-semibold">{t('rsec.revenue.bySource')}</h3><RankBars limit={6} items={d.by_source.map((r) => ({ key: r.key, label: channelLabel(t, r.key), value: r.revenue, note: <span dir="ltr">{ctx.money(r.revenue)}</span> }))} /></div>}
            {d.by_project.length > 0 && <div><h3 className="t-small mb-1 font-semibold">{t('rsec.revenue.byProject')}</h3><RankBars limit={6} items={d.by_project.map((r) => ({ key: r.name, label: r.name, value: r.revenue, note: <span dir="ltr">{ctx.money(r.revenue)}</span> }))} /></div>}
          </div>
        </>
      )}
    </CardBody></Card>
  )
}

const SEVERITY_TONE: Record<string, string> = { critical: 'border-[var(--color-danger)]', warning: 'border-[var(--color-warning)]', info: 'border-[var(--color-line)]' }

/** يحتاج انتباهك — a short list of what a person should look at. A client copy carries counts only, no way into the product. */
export function AttentionSection({ ctx }: { ctx: ReportCtx }) {
  const { t } = useTranslation()
  const items = ctx.data.attention ?? []

  return (
    <Card data-testid="shared-attention"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.attention.title')}</h2>
      {items.length === 0 ? <p className="t-caption" data-testid="shared-attention-empty">{t('rsec.attention.empty')}</p> : (
        <ul className="space-y-2">
          {items.map((f, i) => (
            <li key={`${f.key}-${i}`} className={cn('rounded-xl border-s-4 bg-[var(--color-surface-muted)] px-3 py-2 text-[0.9375rem]', SEVERITY_TONE[f.severity ?? 'info'])} data-testid="shared-attention-item" data-key={f.key}>
              {findingText(t, 'finding', f)}
            </li>
          ))}
        </ul>
      )}
    </CardBody></Card>
  )
}

/** What the team wrote for the reader. Read-only. */
export function NotesSection({ data }: { data: SharedReportData }) {
  const { t } = useTranslation()
  const notes = data.notes ?? []

  if (notes.length === 0) return null

  return (
    <Card data-testid="shared-notes"><CardBody>
      <h2 className="t-card-title mb-3">{t('rsec.notes.title')}</h2>
      <dl className="space-y-3">
        {notes.map((n) => (
          <div key={n.kind}>
            <dt className="t-small font-semibold">{t(`rsec.notes.${n.kind}`)}</dt>
            {/* Plain text, wrapped as written — never markup. */}
            <dd className="whitespace-pre-wrap text-[0.9375rem]" dir="auto">{n.body}</dd>
          </div>
        ))}
      </dl>
    </CardBody></Card>
  )
}
