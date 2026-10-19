import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Skeleton, Tabs } from '@/components/ui'
import { PipelineFunnel, RankBars } from '@/components/visual'
import { platformColor } from '@/components/charts'
import { apiGet } from '@/lib/api'
import { formatMoneyCompact, formatNumber } from '@/lib/format'

type Level = 'source' | 'platform' | 'campaign' | 'ad_set' | 'ad' | 'creative'

interface Row {
  key: string
  label: string
  platform: string | null
  spend: number | null
  leads: number
  valid: number
  contactable: number
  engaged: number
  qualified: number
  appointments: number
  won: number
  revenue: number
  cpl: number | null
  cpql: number | null
  cac: number | null
  roas: number | null
  qualified_rate: number | null
  valid_rate: number | null
  contactable_rate: number | null
  engaged_rate: number | null
  appointment_rate: number | null
  won_rate: number | null
  revenue_per_lead: number | null
  /** One number to rank by, 0–100. */
  index: number | null
}

const COLUMNS = ['spend', 'leads', 'valid', 'contactable', 'engaged', 'qualified', 'appointments', 'won', 'revenue', 'revenue_per_lead', 'cpl', 'cpql', 'cac', 'roas'] as const

/** The funnel as shares of the leads that arrived: each is a column of the ranking. */
const RATES = ['valid_rate', 'contactable_rate', 'engaged_rate', 'qualified_rate', 'appointment_rate', 'won_rate'] as const

/**
 * Platform → campaign → ad set → ad → creative: the whole chain at one level.
 *
 * The picture first — how many survived each step, and who brought the most
 * real customers — then the table, for the figures. Every level adds up to the
 * same totals, so drilling down never changes the story.
 */
export function QualityTab({ dateFrom, dateTo }: { dateFrom?: string; dateTo?: string }) {
  const { t, i18n } = useTranslation()
  const [level, setLevel] = useState<Level>('platform')

  const { data } = useQuery({
    queryKey: ['quality-report', level, dateFrom, dateTo],
    queryFn: () => apiGet<{ rows: Row[]; currency: string }>('/reports/quality', { level, date_from: dateFrom, date_to: dateTo, days: dateFrom ? undefined : 30 }),
  })

  const money = (v: number | null) => formatMoneyCompact(v, data?.currency ?? 'SAR', i18n.language)
  const label = (r: Row) => (level === 'platform' || level === 'source' ? t(`platforms.${r.key}`, { defaultValue: r.key }) : r.label)
  const sum = (k: keyof Row) => (data?.rows ?? []).reduce((a, r) => a + (Number(r[k]) || 0), 0)

  const cell = (r: Row, c: (typeof COLUMNS)[number]) => {
    const v = r[c]
    if (['spend', 'revenue', 'revenue_per_lead', 'cpl', 'cpql', 'cac'].includes(c)) return money(v as number | null)
    if (c === 'roas') return v === null ? '—' : `${v}x`
    return formatNumber(v as number, i18n.language)
  }

  return (
    <div className="space-y-3" data-testid="quality-report">
      <Tabs
        testId="quality-level"
        value={level}
        onChange={setLevel}
        items={(['source', 'platform', 'campaign', 'ad_set', 'ad', 'creative'] as const).map((k) => ({ key: k, label: t(`qualityReport.level.${k}`) }))}
      />

      {!data ? (
        <Skeleton className="h-72" />
      ) : (
        <>
          <div className="grid gap-3 lg:grid-cols-12">
            <Card className="lg:col-span-5">
              <CardBody>
                <h2 className="t-card-title mb-3">{t('campaigns.quality')}</h2>
                <PipelineFunnel steps={(['leads', 'valid', 'engaged', 'qualified', 'appointments', 'won'] as const).map((k) => ({ key: k, label: t(`campaigns.stage.${k}`), count: sum(k) }))} />
              </CardBody>
            </Card>
            <Card className="lg:col-span-7">
              <CardBody>
                <h2 className="t-card-title mb-3">{t('qualityReport.realCustomers')}</h2>
                <RankBars limit={6} items={data.rows.map((r) => ({ key: r.key, label: label(r), value: r.qualified, note: r.qualified_rate === null ? undefined : `${r.qualified_rate}%` }))} />
              </CardBody>
            </Card>
          </div>

          <QualityRanking rows={data.rows} label={label} money={money} />

          <Card>
            <div className="max-h-[28rem] overflow-auto" data-testid="quality-table">
              <table className="w-full text-[0.875rem]">
                <thead className="sticky top-0 bg-[var(--color-surface)]">
                  <tr className="text-[var(--color-ink-subtle)]">
                    <th className="px-3 py-2 text-start font-medium">{t(`qualityReport.level.${level}`)}</th>
                    {COLUMNS.map((c) => <th key={c} className="px-2 py-2 text-end font-medium whitespace-nowrap">{t(`qualityReport.col.${c}`)}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {data.rows.map((r) => (
                    <tr key={r.key} className="border-t border-[var(--color-line)]">
                      <td className="max-w-56 px-3 py-1.5">
                        <span className="flex items-center gap-1.5">
                          <span className="size-2 shrink-0 rounded-full" style={{ background: platformColor(r.platform ?? '') }} aria-hidden />
                          <span className="truncate" dir="auto">{label(r)}</span>
                        </span>
                      </td>
                      {COLUMNS.map((c) => <td key={c} className="px-2 py-1.5 text-end tabular-nums whitespace-nowrap" dir="ltr">{cell(r, c)}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}

/**
 * Who brings the best leads, best first: a bar for the index, and under it the
 * shape of the funnel — what share were valid, reachable, engaged, qualified,
 * booked and won — as a row of cells shaded by how high each is.
 */
function QualityRanking({ rows, label, money }: { rows: Row[]; label: (r: Row) => string; money: (v: number | null) => string }) {
  const { t } = useTranslation()
  const ranked = rows.filter((r) => r.index !== null && r.leads > 0).sort((a, b) => (b.index ?? 0) - (a.index ?? 0)).slice(0, 8)

  if (ranked.length === 0) return null

  return (
    <Card data-testid="quality-ranking">
      <CardBody>
        <h2 className="t-card-title mb-3">{t('qualityReport.ranking')}</h2>
        <ol className="space-y-3">
          {ranked.map((r, i) => (
            <li key={r.key} data-testid="quality-rank" className="grid gap-1.5">
              <div className="flex items-center gap-3">
                <span className="grid size-6 shrink-0 place-items-center rounded-full bg-[var(--color-surface-sunken)] text-[0.875rem] font-bold tabular-nums">{i + 1}</span>
                <span className="min-w-0 flex-1 truncate text-[1rem] font-semibold" dir="auto">{label(r)}</span>
                <span className="text-[0.875rem] tabular-nums text-[var(--color-ink-muted)]" dir="ltr">
                  {t('qualityReport.perLead')} {money(r.revenue_per_lead)} · {t('qualityReport.col.cpql')} {money(r.cpql)}
                </span>
                <span className="w-9 text-end text-[1.125rem] font-bold tabular-nums" data-testid="quality-index">{r.index}</span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]">
                <div className="h-full rounded-full bg-[var(--brand)]" style={{ width: `${Math.min(100, r.index ?? 0)}%` }} />
              </div>
              <div className="grid grid-cols-3 gap-1 sm:grid-cols-6">
                {RATES.map((key) => {
                  const value = r[key]

                  return (
                    <span
                      key={key}
                      data-rate={key}
                      className="rounded-md px-1.5 py-1 text-center text-[0.8125rem] font-semibold tabular-nums"
                      style={{ background: `color-mix(in srgb, var(--brand) ${Math.round(Math.min(100, (value ?? 0)) * 0.6)}%, var(--color-surface-sunken))` }}
                      title={t(`qualityReport.rate.${key}`)}
                    >
                      <span className="block text-[0.75rem] font-medium opacity-80">{t(`qualityReport.rate.${key}`)}</span>
                      {value === null ? '—' : `${value}%`}
                    </span>
                  )
                })}
              </div>
            </li>
          ))}
        </ol>
      </CardBody>
    </Card>
  )
}
