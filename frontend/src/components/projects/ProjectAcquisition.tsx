import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, EmptyState, ErrorState, Select, Skeleton } from '@/components/ui'
import { FunnelBars } from '@/components/tracking/FunnelBars'
import { RealEstateFunnel } from '@/components/visual'
import { HealthBadge } from '@/components/tracking/HealthChecks'
import { ReadinessBadge } from '@/components/tracking/DestinationsPanel'
import { LinkAnalyticsDrawer } from '@/components/tracking/LinkAnalyticsDrawer'
import { LinkWizard } from '@/components/tracking/LinkWizard'
import { QrDialog } from '@/components/tracking/QrDialog'
import { fetchAcquisition } from '@/features/hub'
import type { TrackingLink } from '@/features/tracking'
import { formatMoneyCompact, formatNumber, formatRelative } from '@/lib/format'
import { can, useAuth } from '@/stores/auth'

/**
 * Where this project's customers come from, and whether the tracking can be trusted: the links, the forms, the funnel from the
 * first click, the sources, the campaigns, what just happened, who gets the credit — and what is broken. Every number is one the
 * rest of the product already uses, put side by side; money only for those who may see money.
 */
export function ProjectAcquisition({ projectId }: { projectId: string }) {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const canCreate = can.has(user, 'tracking.create')
  const [days, setDays] = useState(30)
  const [wizard, setWizard] = useState(false)
  const [analytics, setAnalytics] = useState<string | null>(null)
  const [qr, setQr] = useState<TrackingLink | null>(null)
  const { data, isLoading, isError, refetch } = useQuery({ queryKey: ['project-acquisition', projectId, days], queryFn: () => fetchAcquisition(projectId, days) })

  if (isLoading) return <Skeleton className="h-72" />
  if (isError || !data) return <ErrorState onRetry={() => void refetch()} />

  const d = data.diagnostics
  const sources = data.sources
  const money = sources.channels.some((c) => c.revenue !== null)
  const broken = d.links.warning + d.links.critical

  return (
    <div className="space-y-4" data-testid="project-acquisition">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')} data-testid="acquisition-range">
          {[7, 30, 90].map((n) => <option key={n} value={n}>{t('dashboard.lastDays', { count: n })}</option>)}
        </Select>
        {canCreate && <Button icon={<Plus className="size-4" />} onClick={() => setWizard(true)} data-testid="acquisition-new-link">{t('tracking.new')}</Button>}
      </div>

      <Card data-testid="acquisition-diagnostics">
        <CardBody className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="font-extrabold">{t('hub.acquisition.diagnostics')}</h3>
            {(['healthy', 'warning', 'critical'] as const).map((s) => <span key={s} className="inline-flex items-center gap-1" data-testid={`diag-${s}`}><HealthBadge status={s} /><strong className="tabular-nums">{d.links[s]}</strong></span>)}
          </div>
          {broken === 0 && data.links.length > 0 && <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.acquisition.allGood')}</p>}
          {d.issues.length > 0 && (
            <ul className="space-y-1" data-testid="acquisition-issues">
              {d.issues.map((i, n) => (
                <li key={n} className="text-[0.9375rem]" data-state={i.state}>
                  <button type="button" className="pressable text-start underline-offset-2 hover:underline" onClick={() => setAnalytics(i.link_id)}>
                    <strong>{i.link}</strong> — {t(`hub.health.check.${i.check}`)}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {d.destinations.length > 0 && (
            <ul className="flex flex-wrap gap-2 pt-1" data-testid="acquisition-destinations">
              {d.destinations.map((x) => <li key={x.id} className="inline-flex items-center gap-1.5 text-[0.875rem]"><span className="font-semibold">{x.name}</span><ReadinessBadge readiness={x.readiness} enabled={x.is_enabled} /></li>)}
            </ul>
          )}
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardBody>
          {data.real_estate_funnel && (
            <div className="mb-4" data-testid="acquisition-re-funnel">
              <h3 className="mb-2 font-extrabold">{t('reFunnel.title')}</h3>
              <RealEstateFunnel funnel={data.real_estate_funnel} testId="re-funnel" />
            </div>
          )}
          <h3 className="mb-2 font-extrabold">{t('hub.funnel.title')}</h3>
          <FunnelBars rows={data.funnel} testId="acquisition-funnel" />
        </CardBody></Card>

        <Card data-testid="acquisition-sources"><CardBody>
          <h3 className="mb-2 font-extrabold">{t('hub.acquisition.sources')}</h3>
          {sources.channels.length === 0 ? <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.acquisition.noSources')}</p> : (
            <ul className="divide-y divide-[var(--color-border)]">
              {sources.channels.map((c) => (
                <li key={c.channel} className="flex items-center justify-between gap-2 py-1.5 text-[0.9375rem]" data-testid={`source-${c.channel}`}>
                  <span className="font-semibold">{t(`tracking.channels.${c.channel as 'meta'}`, { defaultValue: c.channel })}</span>
                  <span className="tabular-nums text-[var(--color-ink-muted)]">
                    {formatNumber(c.clicks, i18n.language)} → <strong className="text-[var(--color-ink)]">{formatNumber(c.leads, i18n.language)}</strong> → {formatNumber(c.won, i18n.language)}
                    {money && c.revenue !== null && <span className="ms-2">{formatMoneyCompact(c.revenue, 'SAR', i18n.language)}</span>}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <p className={sources.unattributed > 0 ? 'mt-2 text-[0.9375rem] font-semibold text-[var(--color-warning)]' : 't-caption mt-2 text-[var(--color-ink-subtle)]'} data-testid="acquisition-unattributed">
            {t('hub.acquisition.unattributed', { n: sources.unattributed })}
          </p>
        </CardBody></Card>
      </div>

      <Card><CardBody>
        <div className="mb-2 flex items-center justify-between"><h3 className="font-extrabold">{t('hub.acquisition.links')}</h3><Link to="/tracking" className="t-caption text-[var(--brand-text)] underline">{t('hub.acquisition.allLinks')}</Link></div>
        {data.links.length === 0 ? (
          <EmptyState title={t('hub.acquisition.noLinksTitle')} body={t('hub.acquisition.noLinksBody')} />
        ) : (
          <ul className="divide-y divide-[var(--color-border)]" data-testid="acquisition-links">
            {data.links.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-2 py-2" data-testid={`acq-link-${l.token}`}>
                <button type="button" className="pressable min-w-0 text-start" onClick={() => setAnalytics(l.id)}>
                  <span className="block truncate font-semibold">{l.name}</span>
                  <span className="block text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`tracking.channels.${l.channel as 'meta'}`, { defaultValue: l.channel })} · {formatNumber(Number(l.stats.clicks ?? 0), i18n.language)} → {formatNumber(Number(l.stats.leads ?? 0), i18n.language)}</span>
                </button>
                {l.health && <HealthBadge status={l.health} testId={`acq-health-${l.token}`} />}
              </li>
            ))}
          </ul>
        )}
      </CardBody></Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card data-testid="acquisition-forms"><CardBody>
          <h3 className="mb-2 font-extrabold">{t('hub.acquisition.forms')}</h3>
          {data.forms.length === 0 ? <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.acquisition.noForms')}</p> : (
            <ul className="divide-y divide-[var(--color-border)]">
              {data.forms.map((f) => <li key={f.id} className="flex justify-between gap-2 py-1.5 text-[0.9375rem]"><span className="truncate font-semibold">{f.name}</span><span className="tabular-nums text-[var(--color-ink-muted)]">{t('hub.acquisition.submissions', { n: f.submissions })}</span></li>)}
            </ul>
          )}
        </CardBody></Card>

        <Card data-testid="acquisition-campaigns"><CardBody>
          <h3 className="mb-2 font-extrabold">{t('hub.acquisition.campaigns')}</h3>
          {data.campaigns.length === 0 ? <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.acquisition.noCampaigns')}</p> : (
            <ul className="divide-y divide-[var(--color-border)]">
              {data.campaigns.map((c) => <li key={c.id} className="flex justify-between gap-2 py-1.5 text-[0.9375rem]"><span className="truncate font-semibold">{c.name}</span><span className="tabular-nums text-[var(--color-ink-muted)]">{formatNumber(c.leads, i18n.language)} · {formatNumber(c.won, i18n.language)}{c.revenue !== null && ` · ${formatMoneyCompact(c.revenue, 'SAR', i18n.language)}`}</span></li>)}
            </ul>
          )}
        </CardBody></Card>
      </div>

      <Card data-testid="acquisition-events"><CardBody>
        <h3 className="mb-2 font-extrabold">{t('hub.analytics.recent')}</h3>
        {data.events.length === 0 ? <p className="t-caption text-[var(--color-ink-subtle)]">{t('hub.analytics.noEvents')}</p> : (
          <ul className="space-y-1">
            {data.events.map((e, i) => (
              <li key={i} className="flex justify-between gap-2 text-[0.9375rem]"><span><span dir="ltr" className="font-mono text-[0.875rem]">{e.event}</span>{e.link && <span className="ms-2 text-[var(--color-ink-subtle)]">{e.link}</span>}</span><span className="shrink-0 text-[var(--color-ink-subtle)]">{formatRelative(e.at, i18n.language)}</span></li>
            ))}
          </ul>
        )}
      </CardBody></Card>

      <LinkWizard open={wizard} onClose={() => setWizard(false)} onAnalytics={(l) => { setWizard(false); setAnalytics(l.id) }} onQr={setQr} />
      <LinkAnalyticsDrawer linkId={analytics} onClose={() => setAnalytics(null)} />
      <QrDialog link={qr} onClose={() => setQr(null)} />
    </div>
  )
}
