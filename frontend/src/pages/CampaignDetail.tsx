import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Film, Image as ImageIcon, LayoutGrid, Type, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, ErrorState, PageHeader, Select, Skeleton } from '@/components/ui'
import { AreaTrend, Donut, PipelineFunnel, StatusPill, type DonutSegment, type StatusKey } from '@/components/visual'
import { CLASSIFICATION_COLOR, CLASSIFICATION_ORDER } from '@/components/visual/classification'
import { platformColor } from '@/components/charts'
import { useCampaign, type CampaignAd, type CampaignRow } from '@/features/campaigns'
import { CampaignActions } from '@/components/campaigns/CampaignActions'
import { ProposalInbox } from '@/components/campaigns/ProposalInbox'
import { useApiError } from '@/hooks/useApiError'
import { formatMoneyCompact, formatNumber } from '@/lib/format'

const STATUS: Record<CampaignRow['status'], StatusKey | null> = { active: 'active', paused: 'paused', ended: 'completed', unknown: null }

/**
 * One campaign: what it cost, what it brought, which ad is carrying it.
 *
 * Ten numbers on top because those are the ten a media buyer asks about, and
 * each is computed from both halves — platform spend over CRM outcomes. Then
 * the funnel, the quality of what arrived and the days, then the ads as
 * previews ranked by qualified leads.
 */
export function CampaignDetailPage() {
  const { id = '' } = useParams()
  const { t, i18n } = useTranslation()
  const translateError = useApiError()
  const [days, setDays] = useState(30)
  const { data, isLoading, isError, error, refetch } = useCampaign(id, days)
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  if (isError) return <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
  if (isLoading || !data) {
    return (
      <div className="space-y-3" aria-busy="true">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-40" />
        <Skeleton className="h-64" />
      </div>
    )
  }

  const c = data.campaign
  const money = (v: number | null) => formatMoneyCompact(v, c.currency ?? data.currency, i18n.language)
  const status = STATUS[c.status]
  const dateLabel = (iso: string) =>
    new Intl.DateTimeFormat(i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en-US', { day: 'numeric', month: 'short' }).format(new Date(iso))

  const figures: [string, string][] = [
    ['spend', money(c.spend)],
    ['revenue', money(c.revenue || null)],
    ['roas', c.roas === null ? '—' : `${c.roas}x`],
    ['leads', formatNumber(c.leads, i18n.language)],
    ['qualified', formatNumber(c.qualified, i18n.language)],
    ['won', formatNumber(c.won, i18n.language)],
    ['cpl', money(c.cpl)],
    ['cpql', money(c.cpql)],
    ['cpa', money(c.cpa)],
    ['revenuePerLead', money(c.revenue_per_lead)],
  ]

  const quality: DonutSegment[] = CLASSIFICATION_ORDER.map((key) => ({
    key,
    label: t(`classification.${key}`),
    value: data.classification.find((x) => x.key === key)?.count ?? 0,
    color: CLASSIFICATION_COLOR[key],
  }))

  // Every day of the window, so a quiet day is a low point and not a gap.
  const byDay = new Map(data.leads_daily.map((d) => [d.date, d.count]))
  const daily: { label: string; value: number }[] = []
  for (let d = new Date(`${data.from}T00:00:00Z`); d <= new Date(`${data.to}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + 1)) {
    const key = d.toISOString().slice(0, 10)
    daily.push({ label: dateLabel(key), value: byDay.get(key) ?? 0 })
  }

  return (
    <div className="space-y-3" data-testid="campaign-detail">
      <Link to="/campaigns" className="inline-flex items-center gap-1.5 text-[0.8125rem] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">
        <Back className="size-4" aria-hidden />
        {t('campaigns.title')}
      </Link>

      <PageHeader
        title={c.name}
        subtitle={[t(`platforms.${c.platform}`, { defaultValue: c.platform }), c.project?.name, c.account].filter(Boolean).join(' · ')}
        actions={
          <div className="flex items-center gap-2">
            {status && <StatusPill status={status} />}
            <CampaignActions campaign={c} />
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')}>
              {[7, 30, 90].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
            </Select>
            <Link to={`/leads?campaign_name=${encodeURIComponent(c.name)}`}>
              <Button variant="outline" icon={<Users className="size-4" />}>{t('campaigns.openLeads')}</Button>
            </Link>
          </div>
        }
      />

      <Card>
        <dl className="grid grid-cols-2 divide-[var(--color-line)] sm:grid-cols-5" data-testid="campaign-figures">
          {figures.map(([key, value]) => (
            <div key={key} className="px-4 py-3" data-testid={`cf-${key}`}>
              <dd className="text-[1.25rem] font-bold leading-tight tabular-nums" dir="ltr">{value}</dd>
              <dt className="text-[0.6875rem] text-[var(--color-ink-muted)]">{t(`campaigns.figure.${key}`)}</dt>
            </div>
          ))}
        </dl>
      </Card>

      <div className="grid gap-3 lg:grid-cols-12">
        <Card className="lg:col-span-5">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('campaigns.quality')}</h2>
            <PipelineFunnel
              testId="campaign-detail-funnel"
              steps={(['leads', 'valid', 'engaged', 'qualified', 'appointments', 'won'] as const).map((key) => ({ key, label: t(`campaigns.stage.${key}`), count: c[key] }))}
            />
          </CardBody>
        </Card>
        <Card className="lg:col-span-3">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('pipelineBoard.quality')}</h2>
            <Donut size={120} thickness={14} legend="below" centerValue={formatNumber(c.leads, i18n.language)} segments={quality} testId="campaign-quality-donut" />
          </CardBody>
        </Card>
        <Card className="lg:col-span-4">
          <CardBody>
            <h2 className="t-card-title mb-3">{t('campaigns.leadsPerDay')}</h2>
            <AreaTrend points={daily} height={150} testId="campaign-leads-trend" />
          </CardBody>
        </Card>
      </div>

      <ProposalInbox campaignId={c.id} currency={c.currency ?? data.currency} />

      <section>
        <h2 className="t-card-title mb-2 px-1">{t('campaigns.ads')}</h2>
        {data.ads.length === 0 ? (
          <Card><CardBody><p className="py-6 text-center text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('campaigns.noAds')}</p></CardBody></Card>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="ad-grid">
            {data.ads.map((ad) => <AdCard key={ad.id} ad={ad} money={money} platform={c.platform} />)}
          </div>
        )}
      </section>
    </div>
  )
}

const FORMAT_ICON = { video: Film, image: ImageIcon, carousel: LayoutGrid } as const

function AdCard({ ad, money, platform }: { ad: CampaignAd; money: (v: number | null) => string; platform: string }) {
  const { t, i18n } = useTranslation()
  const FormatIcon = FORMAT_ICON[(ad.creative?.format ?? '') as keyof typeof FORMAT_ICON] ?? Type
  const status = STATUS[ad.status]
  const total = Math.max(1, ad.leads)

  return (
    <article className="overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] shadow-[var(--shadow-card)]" data-testid="ad-card">
      {/* The creative itself where the platform gave a picture of it; its kind where it did not. */}
      <div className="relative grid h-32 place-items-center bg-[var(--color-chrome-fill)]">
        {ad.creative?.thumbnail_url ? (
          <img src={ad.creative.thumbnail_url} alt="" className="size-full object-cover" loading="lazy" referrerPolicy="no-referrer" />
        ) : (
          <FormatIcon className="size-8 text-[var(--color-ink-subtle)]" aria-hidden />
        )}
        <span className="absolute start-2 top-2 size-2.5 rounded-full ring-2 ring-[var(--color-surface)]" style={{ background: platformColor(platform) }} aria-hidden />
        {status && <span className="absolute end-2 top-2"><StatusPill status={status} iconOnly /></span>}
      </div>
      <div className="space-y-2 p-3">
        <p className="truncate text-[0.8125rem] font-semibold" dir="auto">{ad.name}</p>
        {ad.creative?.headline && <p className="truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="auto">{ad.creative.headline}</p>}
        <div className="flex h-1.5 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" aria-hidden>
          <span style={{ width: `${(ad.qualified / total) * 100}%`, background: 'var(--color-status-qualified)' }} />
          <span style={{ width: `${(ad.cold / total) * 100}%`, background: 'var(--color-status-invalid)', opacity: 0.45, marginInlineStart: 'auto' }} />
        </div>
        <dl className="grid grid-cols-4 gap-1 text-center">
          {[
            ['leads', formatNumber(ad.leads, i18n.language)],
            ['qualifiedShort', formatNumber(ad.qualified, i18n.language)],
            ['ctr', ad.ctr === null ? '—' : `${ad.ctr}%`],
            ['cpql', money(ad.cpql)],
          ].map(([key, value]) => (
            <div key={key} className="min-w-0">
              <dd className="truncate text-[0.8125rem] font-semibold tabular-nums" dir="ltr">{value}</dd>
              <dt className="truncate text-[0.625rem] text-[var(--color-ink-subtle)]">{t(`campaigns.${key}`)}</dt>
            </div>
          ))}
        </dl>
      </div>
    </article>
  )
}
