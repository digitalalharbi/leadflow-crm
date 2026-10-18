import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Skeleton, Tabs } from '@/components/ui'
import { PipelineFunnel, RankBars, RingProgress } from '@/components/visual'
import { platformColor } from '@/components/charts'
import { apiGet } from '@/lib/api'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import type { CampaignTotals } from '@/features/campaigns'

type Group = 'platform' | 'project' | 'company'

interface Data {
  group: Group
  rows: (CampaignTotals & { key: string; name: string | null })[]
  totals: CampaignTotals
  currency: string
}

/**
 * Marketing and sales on one page: what was spent, and what it became.
 *
 * Grouped the way the business is organised — platform, project, company — and
 * every group is the sum of its campaigns, so the holding's number and the
 * campaign's number can never disagree. Each group is a card: spend, leads,
 * how many were real, what a real one cost, and the return.
 */
export function MarketingSalesTab({ dateFrom, dateTo }: { dateFrom?: string; dateTo?: string }) {
  const { t, i18n } = useTranslation()
  const [group, setGroup] = useState<Group>('platform')

  const { data } = useQuery({
    queryKey: ['marketing-sales', group, dateFrom, dateTo],
    queryFn: () => apiGet<Data>('/reports/marketing-sales', { group, date_from: dateFrom, date_to: dateTo, days: dateFrom ? undefined : 30 }),
  })

  const money = (v: number | null) => formatMoneyCompact(v, data?.currency ?? 'SAR', i18n.language)
  const label = (row: Data['rows'][number]) =>
    group === 'platform' ? t(`platforms.${row.key}`, { defaultValue: row.key }) : row.name ?? t('marketingSales.unassigned')

  return (
    <div className="space-y-3" data-testid="marketing-sales">
      <Tabs
        testId="marketing-group"
        value={group}
        onChange={setGroup}
        items={(['platform', 'project', 'company'] as const).map((key) => ({ key, label: t(`marketingSales.by.${key}`) }))}
      />

      {!data ? (
        <Skeleton className="h-64" />
      ) : (
        <>
          <div className="grid gap-3 lg:grid-cols-12">
            <Card className="lg:col-span-5">
              <CardBody>
                <h2 className="t-card-title mb-3">{t('campaigns.quality')}</h2>
                <PipelineFunnel
                  steps={(['leads', 'valid', 'engaged', 'qualified', 'appointments', 'won'] as const).map((key) => ({ key, label: t(`campaigns.stage.${key}`), count: data.totals[key] }))}
                />
              </CardBody>
            </Card>
            <Card className="lg:col-span-7">
              <CardBody>
                <h2 className="t-card-title mb-3">{t('marketingSales.qualifiedBy')}</h2>
                <RankBars
                  limit={6}
                  items={data.rows.map((row) => ({ key: row.key, label: label(row), value: row.qualified, note: row.qualified_rate === null ? undefined : `${row.qualified_rate}%` }))}
                />
              </CardBody>
            </Card>
          </div>

          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="marketing-groups">
            {data.rows.map((row) => (
              <Card key={row.key} data-testid="marketing-group-card">
                <CardBody className="space-y-3">
                  <div className="flex items-center gap-2">
                    {group === 'platform' && <span className="size-2.5 rounded-full" style={{ background: platformColor(row.key) }} aria-hidden />}
                    <p className="min-w-0 flex-1 truncate text-[0.875rem] font-semibold" dir="auto">{label(row)}</p>
                    <RingProgress size={36} thickness={4} tone="good" value={row.qualified_rate ?? 0}>
                      <span className="text-[0.625rem] font-bold tabular-nums">{row.qualified_rate === null ? '—' : `${Math.round(row.qualified_rate)}%`}</span>
                    </RingProgress>
                  </div>
                  <dl className="grid grid-cols-3 gap-y-2 text-center">
                    {[
                      ['spend', money(row.spend)],
                      ['leads', formatNumber(row.leads, i18n.language)],
                      ['won', formatNumber(row.won, i18n.language)],
                      ['cpl', money(row.cpl)],
                      ['cpql', money(row.cpql)],
                      ['roas', row.roas === null ? '—' : `${row.roas}x`],
                    ].map(([key, value]) => (
                      <div key={key} className="min-w-0">
                        <dd className="truncate text-[0.875rem] font-semibold tabular-nums" dir="ltr">{value}</dd>
                        <dt className="truncate text-[0.625rem] text-[var(--color-ink-subtle)]">{t(`campaigns.figure.${key}`)}</dt>
                      </div>
                    ))}
                  </dl>
                </CardBody>
              </Card>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
