import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Info, Play } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Drawer, Skeleton } from '@/components/ui'
import { Delta, KpiCard } from '@/components/visual'
import { platformColor } from '@/components/charts'
import { apiGet } from '@/lib/api'
import { runRoutine } from '@/features/agents'
import type { CampaignTotals } from '@/features/campaigns'
import { formatDate, formatMoneyCompact, formatNumber } from '@/lib/format'

interface ReportListItem { id: string; period_from: string; period_to: string; created_at: string | null; totals: CampaignTotals | null }
interface ReportRow { id: string; name: string; platform: string; spend: number | null; leads: number; qualified: number; won: number; cpql: number | null; qualified_rate: number | null }
interface ReportFull {
  id: string
  period_from: string
  period_to: string
  data: {
    totals: CampaignTotals
    previous: CampaignTotals
    by_platform: { platform: string; spend: number | null; leads: number; qualified: number; won: number }[]
    best: ReportRow[]
    worst: ReportRow[]
    notes: string[]
  }
}

/**
 * The reports the Reports Agent wrote, as they were written.
 *
 * A list of weeks; opening one shows the figures against the week before, the
 * platforms side by side, the best and the costliest campaigns, and — always —
 * the notes that say where every number came from.
 */
export function WeeklyReportsTab() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState<string | null>(null)

  const list = useQuery({ queryKey: ['agent-reports'], queryFn: () => apiGet<{ reports: ReportListItem[] }>('/agent-reports') })
  const report = useQuery({ queryKey: ['agent-report', open], queryFn: () => apiGet<{ report: ReportFull }>(`/agent-reports/${open}`), enabled: open !== null })

  const generate = useMutation({
    mutationFn: () => runRoutine('weekly_report'),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['agent-reports'] }),
  })

  const money = (v: number | null) => formatMoneyCompact(v, 'SAR', i18n.language)
  const period = (r: { period_from: string; period_to: string }) => `${formatDate(r.period_from, i18n.language)} – ${formatDate(r.period_to, i18n.language)}`

  return (
    <div className="space-y-3" data-testid="weekly-reports">
      <div className="flex justify-end">
        <Button variant="outline" icon={<Play className="size-4" />} loading={generate.isPending} onClick={() => generate.mutate()} data-testid="generate-report">
          {t('weekly.generate')}
        </Button>
      </div>

      {!list.data ? (
        <Skeleton className="h-40" />
      ) : list.data.reports.length === 0 ? (
        <Card><CardBody><p className="py-8 text-center text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('weekly.none')}</p></CardBody></Card>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {list.data.reports.map((r) => (
            <button key={r.id} type="button" onClick={() => setOpen(r.id)} className="text-start" data-testid="weekly-report-card">
              <Card className="h-full transition-shadow hover:shadow-[var(--shadow-raised)]">
                <CardBody className="space-y-3">
                  <p className="flex items-center gap-2 text-[0.8125rem] font-semibold"><FileText className="size-4 text-[var(--brand)]" aria-hidden />{period(r)}</p>
                  {r.totals && (
                    <dl className="grid grid-cols-3 text-center">
                      {[['leads', r.totals.leads], ['qualified', r.totals.qualified], ['won', r.totals.won]].map(([k, v]) => (
                        <div key={k as string}>
                          <dd className="text-[1.125rem] font-bold tabular-nums">{formatNumber(v as number, i18n.language)}</dd>
                          <dt className="text-[0.625rem] text-[var(--color-ink-subtle)]">{t(`campaigns.figure.${k}`)}</dt>
                        </div>
                      ))}
                    </dl>
                  )}
                </CardBody>
              </Card>
            </button>
          ))}
        </div>
      )}

      <Drawer open={open !== null} onClose={() => setOpen(null)} title={report.data ? period(report.data.report) : t('weekly.title')} size="lg" testId="weekly-report-drawer">
        {report.data && (() => {
          const d = report.data.report.data
          return (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <KpiCard label={t('campaigns.figure.leads')} icon={FileText} value={formatNumber(d.totals.leads, i18n.language)} delta={<Delta value={d.totals.leads} previous={d.previous.leads} />} />
                <KpiCard label={t('campaigns.figure.qualified')} icon={FileText} tone="good" value={formatNumber(d.totals.qualified, i18n.language)} delta={<Delta value={d.totals.qualified} previous={d.previous.qualified} />} />
                <KpiCard label={t('campaigns.figure.spend')} icon={FileText} tone="neutral" value={money(d.totals.spend)} />
                <KpiCard label={t('campaigns.figure.cpql')} icon={FileText} tone="brand" value={money(d.totals.cpql)} />
              </div>

              <section>
                <h3 className="t-card-title mb-2">{t('weekly.platforms')}</h3>
                <ul className="space-y-1.5">
                  {d.by_platform.map((p) => (
                    <li key={p.platform} className="flex items-center gap-2 text-[0.8125rem]">
                      <span className="size-2.5 rounded-full" style={{ background: platformColor(p.platform) }} aria-hidden />
                      <span className="flex-1">{t(`platforms.${p.platform}`, { defaultValue: p.platform })}</span>
                      <span className="tabular-nums text-[var(--color-ink-muted)]" dir="ltr">{p.qualified}/{p.leads} · {money(p.spend)}</span>
                    </li>
                  ))}
                </ul>
              </section>

              {(['best', 'worst'] as const).map((k) => d[k].length > 0 && (
                <section key={k}>
                  <h3 className="t-card-title mb-2">{t(`weekly.${k}`)}</h3>
                  <ul className="space-y-1.5">
                    {d[k].map((c) => (
                      <li key={c.id} className="flex items-center gap-2 text-[0.8125rem]">
                        <span className="size-2 rounded-full" style={{ background: platformColor(c.platform) }} aria-hidden />
                        <span className="min-w-0 flex-1 truncate" dir="auto">{c.name}</span>
                        <span className="tabular-nums text-[var(--color-ink-muted)]" dir="ltr">{k === 'best' ? `${c.qualified} · ${c.qualified_rate ?? 0}%` : money(c.cpql)}</span>
                      </li>
                    ))}
                  </ul>
                </section>
              ))}

              <section className="rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-3" data-testid="report-notes">
                <h3 className="mb-1.5 flex items-center gap-1.5 text-[0.75rem] font-semibold"><Info className="size-3.5" aria-hidden />{t('weekly.notes')}</h3>
                <ul className="list-disc space-y-1 ps-4 text-[0.75rem] text-[var(--color-ink-muted)]">
                  {d.notes.map((n) => <li key={n}>{n}</li>)}
                </ul>
              </section>
            </div>
          )
        })()}
      </Drawer>
    </div>
  )
}
