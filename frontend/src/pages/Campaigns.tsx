import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, BadgeDollarSign, Ban, CircleDollarSign, Megaphone, RefreshCw, Images, Rocket, Scale, Target, TrendingUp, Users,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, EmptyState, ErrorState, PageHeader, Select, Skeleton } from '@/components/ui'
import { AreaTrend, KpiCard, PipelineFunnel, RankBars, RingProgress } from '@/components/visual'
import { TONE_COLOR, TONE_TINT } from '@/components/visual/tones'
import { platformColor } from '@/components/charts'
import { CampaignCard } from '@/components/campaigns/CampaignCard'
import { ProposalInbox } from '@/components/campaigns/ProposalInbox'
import { AgentsPanel } from '@/components/campaigns/AgentsPanel'
import { ClientRulesDrawer } from '@/components/campaigns/ClientRulesDrawer'
import {
  campaignInsights, spendTrend, syncCampaigns, useCampaignPlatforms, useCampaigns, type CampaignsData, type Insight, type InsightKey,
} from '@/features/campaigns'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const INSIGHT_ICON: Record<InsightKey, LucideIcon> = {
  no_qualified: Ban,
  forms_not_customers: AlertTriangle,
  costly_lead: CircleDollarSign,
  scale: Rocket,
  unpriced: BadgeDollarSign,
}

/**
 * The campaign command center.
 *
 * What is happening (cost, leads, quality, return), what needs attention (the
 * campaigns buying forms rather than customers), and what to do next — before a
 * single campaign is opened. Campaigns are cards, not rows: each card's bar says
 * how much of what it bought was real.
 */
export function CampaignsPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [days, setDays] = useState(30)
  const [platform, setPlatform] = useState('')
  const [rulesOpen, setRulesOpen] = useState(false)

  const { data, isLoading, isError, error, refetch } = useCampaigns(days, platform)
  const platforms = useCampaignPlatforms()

  const sync = useMutation({
    mutationFn: syncCampaigns,
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      void queryClient.invalidateQueries({ queryKey: ['campaign-platforms'] })
      toast(result.errors.length > 0 ? result.errors[0].message : t('campaigns.synced', { count: result.campaigns }), result.errors.length > 0 ? 'error' : 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const currency = data?.currency ?? 'SAR'
  const money = (value: number | null | undefined) => formatMoneyCompact(value ?? null, currency, i18n.language)
  const anyConnected = platforms.data?.platforms.some((p) => p.connected) ?? false

  return (
    <>
      <PageHeader
        title={t('campaigns.title')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')} data-testid="campaigns-range">
              {[7, 30, 90].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
            </Select>
            <Select value={platform} onChange={(e) => setPlatform(e.target.value)} className="filter-pill min-w-32" aria-label={t('leads.platform')} data-testid="campaigns-platform">
              <option value="">{t('campaigns.allPlatforms')}</option>
              {(platforms.data?.platforms ?? []).map((p) => <option key={p.key} value={p.key}>{t(`platforms.${p.key}`, { defaultValue: p.key })}</option>)}
            </Select>
            <Link to="/creatives" data-testid="open-creatives">
              <Button variant="outline" icon={<Images className="size-4" />}>{t('creatives.title')}</Button>
            </Link>
            <Button variant="ghost" icon={<Scale className="size-4" />} onClick={() => setRulesOpen(true)} data-testid="open-client-rules">
              {t('rules.open')}
            </Button>
            {anyConnected && (
              <Button variant="outline" icon={<RefreshCw className="size-4" />} loading={sync.isPending} onClick={() => sync.mutate()} data-testid="campaigns-sync">
                {t('campaigns.sync')}
              </Button>
            )}
          </div>
        }
      />

      {/* Which platforms are wired, honestly: built and connected, built and waiting, or not built yet. */}
      <div className="mb-3 flex flex-wrap gap-2" data-testid="platform-strip">
        {(platforms.data?.platforms ?? []).map((p) => (
          <span
            key={p.key}
            data-testid={`platform-${p.key}`}
            data-state={p.connected ? 'connected' : p.built ? 'available' : 'coming'}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[0.75rem] font-medium',
              p.connected ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
            )}
            title={p.last_error ?? undefined}
          >
            <span className={cn('size-2 rounded-full', !p.connected && 'opacity-40')} style={{ background: platformColor(p.key) }} aria-hidden />
            {t(`platforms.${p.key}`, { defaultValue: p.key })}
            <span className="text-[0.6875rem] text-[var(--color-ink-subtle)]">
              {p.connected ? t('campaigns.platformState.connected', { count: p.accounts }) : p.built ? t('campaigns.platformState.available') : t('campaigns.platformState.coming')}
              {p.connected && ` · ${t(`integrationState.${p.state}`)}`}
            </span>
          </span>
        ))}
      </div>

      {isError ? (
        <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
      ) : isLoading || !data ? (
        <div className="space-y-3" aria-busy="true">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-28" />)}</div>
          <Skeleton className="h-64" />
        </div>
      ) : data.campaigns.length === 0 ? (
        <Card>
          <CardBody>
            <EmptyState
              icon={<Megaphone className="size-6" />}
              title={t('campaigns.emptyTitle')}
              body={t('campaigns.emptyBody')}
              actions={<Link to="/integrations"><Button variant="outline">{t('campaigns.connect')}</Button></Link>}
            />
          </CardBody>
        </Card>
      ) : (
        <Overview data={data} money={money} />
      )}

      <ClientRulesDrawer open={rulesOpen} onClose={() => setRulesOpen(false)} currency={currency} />
    </>
  )
}

function Overview({ data, money }: { data: CampaignsData; money: (v: number | null | undefined) => string }) {
  const { t, i18n } = useTranslation()
  const { totals } = data
  const insights = campaignInsights(data.campaigns, totals).slice(0, 6)
  const trend = spendTrend(data.campaigns, data.from, data.to)
  const hasSpend = totals.spend !== null

  return (
    <div className="space-y-3" data-testid="campaigns-overview">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <KpiCard label={t('campaigns.spend')} icon={CircleDollarSign} value={money(totals.spend)} valueTestId="ck-spend" />
        <KpiCard label={t('campaigns.leads')} icon={Users} tone="neutral" value={formatNumber(totals.leads, i18n.language)} valueTestId="ck-leads" />
        <KpiCard
          label={t('campaigns.qualifiedRate')}
          icon={Target}
          tone="good"
          valueTestId="ck-qualified"
          value={
            <span className="flex items-center gap-2" dir="ltr">
              <RingProgress size={40} thickness={5} tone="good" value={totals.qualified_rate ?? 0}>{' '}</RingProgress>
              {totals.qualified_rate === null ? '—' : `${totals.qualified_rate}%`}
            </span>
          }
        />
        <KpiCard label={t('campaigns.cpl')} icon={BadgeDollarSign} tone="neutral" value={money(totals.cpl)} valueTestId="ck-cpl" />
        <KpiCard label={t('campaigns.cpql')} icon={Target} tone="brand" value={money(totals.cpql)} valueTestId="ck-cpql" />
        <KpiCard
          label={t('campaigns.roas')}
          icon={TrendingUp}
          tone="good"
          valueTestId="ck-roas"
          value={totals.roas === null ? '—' : <span dir="ltr">{totals.roas}x</span>}
        />
      </div>

      <div className="grid gap-3 lg:grid-cols-12">
        <Card className="lg:col-span-5">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('campaigns.quality')}</h2>
            {/* Not "how many leads" but how many survived each step — the platform cannot show this. */}
            <PipelineFunnel
              testId="campaign-funnel"
              steps={(['leads', 'valid', 'engaged', 'qualified', 'appointments', 'won'] as const).map((key) => ({
                key,
                label: t(`campaigns.stage.${key}`),
                count: totals[key],
              }))}
            />
          </CardBody>
        </Card>

        <Card className="lg:col-span-4">
          <CardBody>
            <h2 className="t-card-title mb-1">{t('campaigns.spendTrend')}</h2>
            <p className="mb-3 text-[1.375rem] font-bold tabular-nums" dir="ltr">{money(totals.spend)}</p>
            {hasSpend ? (
              <AreaTrend
                testId="spend-trend"
                height={120}
                formatValue={(v) => money(v)}
                points={trend.map((p) => ({
                  label: new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' }).format(new Date(p.date)),
                  value: p.value,
                }))}
              />
            ) : (
              <p className="py-8 text-center text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('campaigns.noSpend')}</p>
            )}
          </CardBody>
        </Card>

        <Card className="lg:col-span-3">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('campaigns.ranking')}</h2>
            {/* Ranked by qualified leads, not by leads: volume flatters the cheapest traffic. */}
            <RankBars
              testId="campaign-ranking"
              limit={5}
              items={data.campaigns.map((c) => ({
                key: c.id,
                label: c.name,
                value: c.qualified,
                note: c.qualified_rate === null ? undefined : `${c.qualified_rate}%`,
                href: `/campaigns/${c.id}`,
              }))}
            />
          </CardBody>
        </Card>
      </div>

      {/* What agents and people want changed, waiting for a decision. */}

      <ProposalInbox currency={data.currency} />


      {/* The agents: who ran, what they saw. They advise; the inbox above is where it becomes a change. */}

      <AgentsPanel />


      {insights.length > 0 && (
        <section data-testid="campaign-insights">
          <h2 className="t-card-title mb-2 px-1">{t('campaigns.attention')}</h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {insights.map((insight) => <InsightCard key={`${insight.key}-${insight.campaign.id}`} insight={insight} />)}
          </div>
        </section>
      )}

      <section>
        <h2 className="t-card-title mb-2 px-1">{t('campaigns.all')}</h2>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="campaign-grid">
          {data.campaigns.map((campaign) => <CampaignCard key={campaign.id} campaign={campaign} currency={data.currency} />)}
        </div>
      </section>
    </div>
  )
}

function InsightCard({ insight }: { insight: Insight }) {
  const { t } = useTranslation()
  const Icon = INSIGHT_ICON[insight.key]
  const c = insight.campaign

  return (
    <Link
      to={`/campaigns/${c.id}`}
      data-testid={`insight-${insight.key}`}
      className="flex items-center gap-3 rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-raised)]"
    >
      <span className="grid size-10 shrink-0 place-items-center rounded-full" style={{ background: TONE_TINT[insight.tone], color: TONE_COLOR[insight.tone] }} aria-hidden>
        <Icon className="size-5" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[0.8125rem] font-semibold">{t(`campaigns.insight.${insight.key}`)}</span>
        <span className="block truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="auto">
          {c.name} · {t('campaigns.insightFigures', { leads: c.leads, rate: c.qualified_rate ?? 0 })}
        </span>
      </span>
    </Link>
  )
}
