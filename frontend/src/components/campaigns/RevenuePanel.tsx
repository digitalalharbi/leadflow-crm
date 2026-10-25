import { useState } from 'react'
import { Link } from 'react-router-dom'
import { BadgeDollarSign, CircleDollarSign, Coins, Eye, KeyRound, Sparkles, Target, TrendingUp, UserCheck, Users } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, EmptyState, ErrorState, Skeleton, Table, TableWrap, Td, Th, Tr } from '@/components/ui'
import { AreaTrend, Delta, Donut, KpiCard, PipelineFunnel, RankBars, RealEstateFunnel } from '@/components/visual'
import type { Tone } from '@/components/visual/tones'
import { platformColor } from '@/components/charts'
import { useCampaignRevenue, type CampaignRevenueData, type CampaignRow, type RevenueStep } from '@/features/campaigns'
import { formatCompact, formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import { can, useAuth } from '@/stores/auth'

const ROLE_RAMP = ['var(--brand)', 'var(--color-info)', 'var(--color-warning)', 'var(--color-success)', 'var(--color-temp-hot)', 'var(--color-status-appointment)']

const TREND_KEYS = ['revenue', 'spend', 'leads', 'interested', 'visits', 'reservations', 'won'] as const
type TrendKey = (typeof TREND_KEYS)[number]

const RANKINGS = ['roas', 'cpl', 'reservations', 'wasted'] as const
type Ranking = (typeof RANKINGS)[number]

/**
 * From the riyal spent to the riyal earned.
 *
 * Ten figures with their change against the period before, the funnel with what each step cost,
 * a trend you can switch between spend and every step, who is ahead, where each campaign's
 * funnel narrows (the heatmap), and the table to open any campaign from. One question, answered
 * from the top: is the money coming back?
 */
export function RevenuePanel({ days, platform }: { days: number; platform: string }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading, isError, refetch } = useCampaignRevenue(days, platform)

  if (isError) return <Card><ErrorState onRetry={() => void refetch()} /></Card>

  if (isLoading || !data) {
    return (
      <div className="space-y-3" aria-busy="true">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">{Array.from({ length: 10 }).map((_, i) => <Skeleton key={i} className="h-24" />)}</div>
        <Skeleton className="h-64" />
      </div>
    )
  }

  if (data.campaigns.length === 0) {
    return <Card><CardBody><EmptyState title={t('campaigns.emptyTitle')} body={t('campaigns.emptyBody')} /></CardBody></Card>
  }

  return (
    <div className="space-y-3" data-testid="revenue-panel">
      <Kpis data={data} />

      <div className="grid items-start gap-3 lg:grid-cols-12">
        <FunnelCard data={data} className="lg:col-span-5" />
        <TrendCard data={data} className="lg:col-span-7" />
      </div>

      <div className="grid items-start gap-3 lg:grid-cols-12">
        <MixCard data={data} className="lg:col-span-5" />
        <RankingCard data={data} className="lg:col-span-7" />
      </div>

      <HeatmapCard data={data} />
      <CampaignTable rows={data.campaigns} currency={data.currency} lang={i18n.language} />
    </div>
  )
}

function useMoney(currency: string) {
  const { i18n } = useTranslation()

  return (value: number | null | undefined) => formatMoneyCompact(value ?? null, currency, i18n.language)
}

/** The ten figures a campaign manager is judged on, each with its change against the period before. */
function Kpis({ data }: { data: CampaignRevenueData }) {
  const { t, i18n } = useTranslation()
  const money = useMoney(data.currency)
  const user = useAuth((state) => state.user)
  const c = data.comparison
  const num = (v: number | null | undefined) => formatNumber(v ?? 0, i18n.language)

  // `cost` is lower-is-better: its delta is inverted, so a fall is green.
  const cards: Array<{ key: string; icon: LucideIcon; tone?: Tone; value: string; cost?: boolean; sub?: string | null }> = [
    { key: 'spend', icon: CircleDollarSign, value: money(data.totals.spend), cost: true },
    { key: 'leads', icon: Users, tone: 'neutral', value: num(data.totals.leads), sub: data.totals.cpl !== null ? `${t('campaigns.cpl')} ${money(data.totals.cpl)}` : null },
    { key: 'interested', icon: Sparkles, tone: 'neutral', value: num(data.totals.interested), sub: data.totals.cost_per_interested !== null ? money(data.totals.cost_per_interested) : null },
    { key: 'visits', icon: Eye, tone: 'neutral', value: num(data.totals.visits), sub: data.totals.cost_per_visit !== null ? money(data.totals.cost_per_visit) : null },
    { key: 'reservations', icon: KeyRound, tone: 'neutral', value: num(data.totals.reservations), sub: data.totals.cost_per_reservation !== null ? money(data.totals.cost_per_reservation) : null },
    { key: 'won', icon: UserCheck, tone: 'good', value: num(data.totals.won), sub: data.totals.cac !== null ? `${t('revenue.kpi.cac')} ${money(data.totals.cac)}` : null },
    { key: 'revenue', icon: Coins, tone: 'good', value: money(data.totals.revenue) },
    { key: 'roas', icon: TrendingUp, tone: 'good', value: data.totals.roas === null ? '—' : `${data.totals.roas}x` },
    { key: 'cac', icon: Target, value: money(data.totals.cac), cost: true },
    { key: 'cpl', icon: BadgeDollarSign, value: money(data.totals.cpl), cost: true },
  ]

  // What a person may not see is not drawn as a dash: the card is not there.
  const visible = cards.filter((card) => (['spend', 'cpl', 'cac', 'roas'].includes(card.key) ? can.has(user, 'spend.view') : card.key === 'revenue' ? can.has(user, 'revenue.view') : true))

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-5" data-testid="revenue-kpis">
      {visible.map(({ key, icon, tone, value, cost, sub }) => {
        const entry = c[key]

        return (
          <KpiCard
            key={key}
            testId={`rk-${key}`}
            valueTestId={`rk-${key}-value`}
            label={t(`revenue.kpi.${key}`)}
            icon={icon}
            tone={tone}
            value={<span dir="ltr">{value}</span>}
            delta={entry && entry.current !== null && entry.previous !== null ? <Delta value={Number(entry.current)} previous={Number(entry.previous)} invert={cost} /> : undefined}
          >
            {sub && <p className="t-caption mt-1 tabular-nums" dir="ltr">{sub}</p>}
          </KpiCard>
        )
      })}
    </div>
  )
}

function FunnelCard({ data, className }: { data: CampaignRevenueData; className?: string }) {
  const { t } = useTranslation()
  const money = useMoney(data.currency)

  return (
    <Card className={className} data-testid="revenue-funnel-card">
      <CardBody>
        <h2 className="t-card-title mb-3">{data.real_estate_funnel ? t('reFunnel.title') : t('revenue.funnel')}</h2>
        {data.real_estate_funnel && <RealEstateFunnel funnel={data.real_estate_funnel} currency={data.currency} testId="revenue-re-funnel" />}
        {/* The share of the step before that never made it here — where the money leaks. */}
        {!data.real_estate_funnel && <PipelineFunnel
          testId="revenue-funnel"
          steps={data.funnel.map((step) => ({
            key: step.key,
            label: t(`revenue.step.${step.key}`),
            count: step.count,
            costPer: step.cost !== null ? money(step.cost) : null,
          }))}
        />}
        {!data.real_estate_funnel && <ul className="mt-2 flex flex-wrap gap-x-3 gap-y-0.5" data-testid="revenue-funnel-lost">
          {data.funnel.filter((step) => step.lost !== null && step.lost > 0).map((step) => (
            <li key={step.key} className="t-caption tabular-nums">
              <span className="font-semibold text-[var(--color-danger)]" dir="ltr">−{step.lost}%</span> {t('revenue.beforeStep', { step: t(`revenue.step.${step.key}`) })}
            </li>
          ))}
        </ul>}
      </CardBody>
    </Card>
  )
}

/** One measure at a time over one axis: switching the chip is how two scales are never mixed. */
function TrendCard({ data, className }: { data: CampaignRevenueData; className?: string }) {
  const { t, i18n } = useTranslation()
  const money = useMoney(data.currency)
  const [key, setKey] = useState<TrendKey>('revenue')
  const isMoney = key === 'revenue' || key === 'spend'
  const total = data.trend.reduce((sum, p) => sum + p[key], 0)
  const day = new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' })

  return (
    <Card className={className} data-testid="revenue-trend-card">
      <CardBody>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <h2 className="t-card-title">{t('revenue.trend')}</h2>
          <div role="group" aria-label={t('revenue.trend')} className="flex flex-wrap gap-1">
            {TREND_KEYS.map((k) => (
              <button
                key={k}
                type="button"
                aria-pressed={key === k}
                data-testid={`trend-${k}`}
                onClick={() => setKey(k)}
                className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', key === k ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}
              >
                {t(`revenue.kpi.${k}`)}
              </button>
            ))}
          </div>
        </div>
        <p className="mb-2 text-[1.375rem] font-bold tabular-nums" dir="ltr" data-testid="revenue-trend-total">{isMoney ? money(total) : formatNumber(total, i18n.language)}</p>
        <AreaTrend
          testId="revenue-trend"
          height={150}
          axes
          valueLabel={t(`revenue.kpi.${key}`)}
          formatValue={(v) => (isMoney ? money(v) : formatNumber(v, i18n.language))}
          points={data.trend.map((p) => ({ label: day.format(new Date(p.date)), value: p[key] }))}
        />
        <p className="t-caption mt-1">{t('revenue.cohortNote')}</p>
      </CardBody>
    </Card>
  )
}

/** Where the leads, the spend and the revenue came from — two rings, read side by side. */
function MixCard({ data, className }: { data: CampaignRevenueData; className?: string }) {
  const { t, i18n } = useTranslation()

  return (
    <Card className={className} data-testid="revenue-mix">
      <CardBody>
        <h2 className="t-card-title mb-3">{t('revenue.mix')}</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <p className="t-caption mb-1">{t('revenue.leadsByPlatform')}</p>
            <Donut
              testId="mix-platforms"
              size={120}
              thickness={14}
              legend="below"
              centerLabel={t('revenue.kpi.leads')}
              segments={data.mix.platforms.map((p) => ({ key: p.key, label: t(`platforms.${p.key}`, { defaultValue: p.key }), value: p.leads, color: platformColor(p.key) }))}
            />
          </div>
          <div>
            <p className="t-caption mb-1">{t('revenue.revenueByCampaign')}</p>
            <Donut
              testId="mix-revenue"
              size={120}
              thickness={14}
              legend="below"
              centerValue={formatCompact(data.totals.revenue, i18n.language)}
              centerLabel={t('revenue.kpi.revenue')}
              segments={data.mix.revenue_by_campaign.map((r, i) => ({ key: r.key, label: r.label ?? t('common.others'), value: r.value, color: ROLE_RAMP[i % ROLE_RAMP.length] }))}
            />
          </div>
        </div>
        <p className="t-caption mt-2 tabular-nums" dir="ltr">{formatNumber(data.mix.platforms.reduce((s, p) => s + p.leads, 0), i18n.language)} {t('revenue.kpi.leads')}</p>
      </CardBody>
    </Card>
  )
}

/** Who is ahead on the four questions that decide where the next riyal goes. */
function RankingCard({ data, className }: { data: CampaignRevenueData; className?: string }) {
  const { t } = useTranslation()
  const money = useMoney(data.currency)
  const [which, setWhich] = useState<Ranking>('roas')
  const rows = data.rankings[which]

  return (
    <Card className={className} data-testid="revenue-ranking">
      <CardBody>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="t-card-title">{t('revenue.ranking')}</h2>
          <div role="group" className="flex flex-wrap gap-1">
            {RANKINGS.map((r) => (
              <button
                key={r}
                type="button"
                aria-pressed={which === r}
                data-testid={`ranking-${r}`}
                onClick={() => setWhich(r)}
                className={cn('pressable rounded-full px-2.5 py-0.5 text-[0.875rem] font-semibold', which === r ? 'bg-[var(--brand)] text-[var(--brand-ink)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]')}
              >
                {t(`revenue.rank.${r}`)}
              </button>
            ))}
          </div>
        </div>

        {rows.length === 0 ? (
          <p className="py-6 text-center text-[0.9375rem] text-[var(--color-ink-subtle)]" data-testid="ranking-empty">{t(`revenue.rankEmpty.${which}`)}</p>
        ) : (
          <RankBars
            testId="ranking-bars"
            limit={3}
            color={which === 'wasted' ? 'var(--color-danger)' : 'var(--brand)'}
            items={rows.map((row) => ({
              key: row.id,
              label: row.name,
              // Lowest CPL is best: the bar is its inverse so the leader is still the longest.
              value: which === 'roas' ? row.roas ?? 0 : which === 'cpl' ? 1 / Math.max(0.01, row.cpl ?? 1) : which === 'reservations' ? row.reservations : row.spend ?? 0,
              note: (
                <span className="tabular-nums" dir="ltr">
                  {which === 'roas' && `${row.roas}x`}
                  {which === 'cpl' && money(row.cpl)}
                  {which === 'reservations' && `${row.reservations} · ${money(row.revenue)}`}
                  {which === 'wasted' && money(row.spend)}
                </span>
              ),
              href: `/campaigns/${row.id}`,
            }))}
          />
        )}
      </CardBody>
    </Card>
  )
}

/**
 * Campaigns × steps: the share of each campaign's leads that reached each step. A dark row that
 * goes pale at "visits" is a campaign whose leads are not coming to see anything.
 */
function HeatmapCard({ data }: { data: CampaignRevenueData }) {
  const { t } = useTranslation()
  const { steps, rows } = data.heatmap

  if (rows.length === 0) return null

  return (
    <Card data-testid="revenue-heatmap">
      <CardBody>
        <h2 className="t-card-title mb-3">{t('revenue.heatmap')}</h2>
        <div className="grid items-center gap-1" style={{ gridTemplateColumns: `minmax(8rem, 1.4fr) repeat(${steps.length}, minmax(3.5rem, 1fr))` }}>
          <span />
          {steps.map((step: RevenueStep) => <span key={step} className="t-caption text-center">{t(`revenue.step.${step}`)}</span>)}

          {rows.map((row) => (
            <HeatRow key={row.id} row={row} />
          ))}
        </div>
      </CardBody>
    </Card>
  )
}

function HeatRow({ row }: { row: CampaignRevenueData['heatmap']['rows'][number] }) {
  return (
    <>
      <Link to={`/campaigns/${row.id}`} className="flex min-w-0 items-center gap-1.5 text-[0.9375rem] font-medium hover:underline">
        <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: platformColor(row.platform) }} />
        <span className="truncate">{row.name}</span>
      </Link>
      {row.cells.map((value, index) => (
        <span
          key={index}
          data-testid="heat-cell"
          data-value={value}
          className="grid h-8 place-items-center rounded-lg text-[0.875rem] font-semibold tabular-nums"
          dir="ltr"
          style={{
            background: `color-mix(in srgb, var(--brand) ${Math.round(8 + Math.min(100, value) * 0.8)}%, transparent)`,
            color: value >= 55 ? 'var(--brand-ink)' : 'var(--color-ink)',
          }}
        >
          {value}%
        </span>
      ))}
    </>
  )
}

/** Every figure for every campaign; a row opens the campaign (its ads, its days, its feedback). */
function CampaignTable({ rows, currency, lang }: { rows: CampaignRow[]; currency: string; lang: string }) {
  const { t } = useTranslation()
  const money = (v: number | null) => formatMoneyCompact(v, currency, lang)
  const n = (v: number) => formatNumber(v, lang)

  return (
    <Card data-testid="revenue-table">
      <CardBody className="p-0">
        <TableWrap>
          <Table>
            <thead>
              <tr>
                <Th>{t('revenue.table.campaign')}</Th>
                <Th>{t('revenue.kpi.spend')}</Th>
                <Th>{t('revenue.kpi.leads')}</Th>
                <Th>{t('revenue.kpi.cpl')}</Th>
                <Th>{t('revenue.kpi.interested')}</Th>
                <Th>{t('revenue.kpi.visits')}</Th>
                <Th>{t('revenue.kpi.reservations')}</Th>
                <Th>{t('revenue.kpi.won')}</Th>
                <Th>{t('revenue.kpi.cac')}</Th>
                <Th>{t('revenue.kpi.revenue')}</Th>
                <Th>{t('revenue.kpi.roas')}</Th>
              </tr>
            </thead>
            <tbody>
              {rows.slice(0, 15).map((row) => (
                <Tr key={row.id} data-testid={`revenue-row-${row.id}`}>
                  <Td className="max-w-[14rem]">
                    <Link to={`/campaigns/${row.id}`} className="flex items-center gap-1.5 font-medium hover:underline" data-testid="revenue-row-link">
                      <span aria-hidden className="size-2 shrink-0 rounded-full" style={{ background: platformColor(row.platform) }} />
                      <span className="truncate">{row.name}</span>
                    </Link>
                  </Td>
                  <Td className="tabular-nums" dir="ltr">{money(row.spend)}</Td>
                  <Td className="tabular-nums">{n(row.leads)}</Td>
                  <Td className="tabular-nums" dir="ltr">{money(row.cpl)}</Td>
                  <Td className="tabular-nums">{n(row.interested)}</Td>
                  <Td className="tabular-nums">{n(row.visits)}</Td>
                  <Td className="tabular-nums">{n(row.reservations)}</Td>
                  <Td className="tabular-nums">{n(row.won)}</Td>
                  <Td className="tabular-nums" dir="ltr">{money(row.cac)}</Td>
                  <Td className="tabular-nums" dir="ltr">{money(row.revenue)}</Td>
                  <Td className={cn('tabular-nums font-semibold', row.roas !== null && row.roas < 1 && 'text-[var(--color-danger)]')} dir="ltr">{row.roas === null ? '—' : `${row.roas}x`}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </TableWrap>
      </CardBody>
    </Card>
  )
}
