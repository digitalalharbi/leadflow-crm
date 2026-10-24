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
  attribution?: { synced_campaigns: number; campaign_basis_available: boolean }
  source_attribution?: {
    basis: 'lead_source'
    rows: { source: string; leads: number; qualified: number; interested: number; visit: number; reservation: number; won: number; revenue: number }[]
    totals: Record<'leads' | 'qualified' | 'interested' | 'visit' | 'reservation' | 'won' | 'revenue', number>
  }
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
          {/*
            Two attributions, each named. By source: every lead by the platform it came from — what any workspace has.
            By synced campaign: needs connected ad accounts. Without them, the campaign figures are not zeros; they are absent.
          */}
          {data.source_attribution && (
            <Card data-testid="source-attribution">
              <CardBody>
                <h2 className="t-card-title">{t('marketingSales.sourceTitle')}</h2>
                <p className="t-caption mb-3">{t('marketingSales.sourceHint')}</p>
                {data.source_attribution.rows.length === 0 ? (
                  <p className="t-caption">{t('marketingSales.noLeads')}</p>
                ) : (
                  <ul className="divide-y divide-[var(--color-line)]">
                    {data.source_attribution.rows.map((row) => (
                      <li key={row.source} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-[0.9375rem]" data-testid={`source-row-${row.source}`}>
                        <span className="flex min-w-32 items-center gap-2 font-medium">
                          {row.source !== 'unknown' && <span className="size-2.5 rounded-full" style={{ background: platformColor(row.source) }} aria-hidden />}
                          {row.source === 'unknown' ? t('marketingSales.unknownSource') : t(`platforms.${row.source}`, { defaultValue: row.source })}
                        </span>
                        {(['leads', 'qualified', 'interested', 'visit', 'won'] as const).map((key) => (
                          <span key={key} className="tabular-nums"><span className="t-caption">{t(`manager.reFunnel.${key}`)} </span>{formatNumber(row[key], i18n.language)}</span>
                        ))}
                        <span className="tabular-nums" dir="ltr">{money(row.revenue)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardBody>
            </Card>
          )}

          <h2 className="t-card-title pt-2" data-testid="campaign-attribution-title">{t('marketingSales.campaignTitle')}</h2>
          {data.attribution && !data.attribution.campaign_basis_available ? (
            <Card data-testid="no-synced-campaigns"><CardBody><p className="text-[0.9375rem]">{t('marketingSales.noSyncedCampaigns')}</p></CardBody></Card>
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
                    <p className="min-w-0 flex-1 truncate text-[1rem] font-semibold" dir="auto">{label(row)}</p>
                    <RingProgress size={36} thickness={4} tone="good" value={row.qualified_rate ?? 0}>
                      <span className="text-[0.8125rem] font-bold tabular-nums">{row.qualified_rate === null ? '—' : `${Math.round(row.qualified_rate)}%`}</span>
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
                        <dd className="truncate text-[1rem] font-semibold tabular-nums" dir="ltr">{value}</dd>
                        <dt className="truncate text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`campaigns.figure.${key}`)}</dt>
                      </div>
                    ))}
                  </dl>
                </CardBody>
              </Card>
            ))}
          </div>
          </>
          )}
        </>
      )}
    </div>
  )
}
