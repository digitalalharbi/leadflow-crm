import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Eye, MousePointerClick, Plus, Target, TrendingUp, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, EmptyState, ErrorState, PageHeader, Select, Skeleton, Tabs } from '@/components/ui'
import { KpiCard } from '@/components/visual'
import { AttributionPanel } from '@/components/tracking/AttributionPanel'
import { HealthPanel } from '@/components/tracking/HealthPanel'
import { LinkCard } from '@/components/tracking/LinkCard'
import { LinkDrawer } from '@/components/tracking/LinkDrawer'
import { QrCenter } from '@/components/tracking/QrCenter'
import { DestinationsPanel } from '@/components/tracking/DestinationsPanel'
import { FormsPanel } from '@/components/tracking/FormsPanel'
import { LinkAnalyticsDrawer } from '@/components/tracking/LinkAnalyticsDrawer'
import { LinkWizard } from '@/components/tracking/LinkWizard'
import { QrDialog } from '@/components/tracking/QrDialog'
import { fetchLinks, type TrackingLink } from '@/features/tracking'
import { formatMoneyCompact, formatNumber } from '@/lib/format'
import { can, entitled, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const TABS = ['links', 'forms', 'qr', 'health', 'attribution', 'destinations'] as const
type Tab = (typeof TABS)[number]

/**
 * Tracking: the links a workspace hands out, and who gets the credit for what they bring.
 *
 * One screen rather than three, because the questions are one question — "which of the things we
 * put out into the world made this customer?" — asked of links, of the leads they became, and of
 * the money those leads turned into.
 */
export function TrackingPage() {
  const { t, i18n } = useTranslation()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const canEdit = can.has(user, 'tracking.create')
  const canSee = can.has(user, 'tracking.view')
  // How platforms are doing and who gets the credit are analyses: they take the reports permission.
  const analytics = can.viewReports(user)
  // Forms are the landing pages' questions and destinations are where events go: the manager's side of tracking, not a broker's.
  const subscription = useAuth((s) => s.subscription)
  // And each tab is a feature the plan includes or does not; the server refuses the data of one it does not.
  const FEATURE: Record<Tab, Parameters<typeof entitled>[1]> = { links: 'tracking_hub', qr: 'tracking_hub', health: 'tracking_hub', forms: 'advanced_forms', attribution: 'attribution', destinations: 'api' }
  const hasLinks = entitled(subscription, 'tracking_hub')
  const tabs = TABS.filter((key) => entitled(subscription, FEATURE[key]) && (key === 'links' || key === 'qr' || (key === 'forms' ? analytics || canEdit : key === 'destinations' ? canEdit : analytics)))

  const [tab, setTab] = useState<Tab>(tabs[0] ?? 'links')
  const [days, setDays] = useState(30)
  const [openId, setOpenId] = useState<string | null>(null)
  const [drawer, setDrawer] = useState(false)
  const [qr, setQr] = useState<TrackingLink | null>(null)
  const [wizard, setWizard] = useState(false)
  const [analyticsId, setAnalyticsId] = useState<string | null>(null)

  const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['tracking-links', days], queryFn: () => fetchLinks(days), enabled: canSee && hasLinks })

  // Money and attribution: the people who may read reports, as in the navigation and the API.
  if (!canSee) return <Navigate to="/" replace />

  const links = data?.links ?? []
  const sum = (key: 'clicks' | 'visitors' | 'leads' | 'won' | 'revenue') => links.reduce((n, l) => n + (l.stats[key] ?? 0), 0)

  const copy = async (url: string) => {
    try {
      await navigator.clipboard.writeText(url)
      toast(t('tracking.copied'), 'success')
    } catch {
      toast(url, 'info')
    }
  }

  return (
    <>
      <PageHeader
        title={t('tracking.title')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))} className="filter-pill min-w-32" aria-label={t('common.date')} data-testid="tracking-range">
              {[7, 30, 90].map((d) => <option key={d} value={d}>{t('dashboard.lastDays', { count: d })}</option>)}
            </Select>
            {canEdit && (
              <Button icon={<Plus className="size-4" />} onClick={() => setWizard(true)} data-testid="new-link">{t('tracking.new')}</Button>
            )}
          </div>
        }
      />

      <Tabs testId="tracking-tabs" value={tab} onChange={setTab} items={tabs.map((key) => ({ key, label: t(`tracking.tabs.${key}`) }))} />

      <div className="mt-3 space-y-3">
        {tab === 'links' && (
          <>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" data-testid="tracking-kpis">
              <KpiCard testId="tk-clicks" valueTestId="tk-clicks-value" label={t('tracking.stat.clicks')} value={formatNumber(sum('clicks'), i18n.language)} icon={MousePointerClick} />
              <KpiCard testId="tk-visitors" valueTestId="tk-visitors-value" label={t('tracking.stat.visitors')} value={formatNumber(sum('visitors'), i18n.language)} icon={Eye} />
              <KpiCard testId="tk-leads" valueTestId="tk-leads-value" label={t('tracking.stat.leads')} value={formatNumber(sum('leads'), i18n.language)} icon={Users} />
              <KpiCard testId="tk-won" valueTestId="tk-won-value" label={t('tracking.stat.won')} value={formatNumber(sum('won'), i18n.language)} icon={Target} />
              {can.has(user, 'revenue.view') && <KpiCard testId="tk-revenue" valueTestId="tk-revenue-value" label={t('tracking.stat.revenue')} value={formatMoneyCompact(sum('revenue'), 'SAR', i18n.language)} icon={TrendingUp} />}
            </div>

            {isLoading ? <Skeleton className="h-48" /> : isError ? (
              <ErrorState message={error instanceof Error ? error.message : undefined} onRetry={() => void refetch()} />
            ) : links.length === 0 ? (
              <EmptyState title={t('tracking.emptyTitle')} body={t('tracking.emptyBody')} />
            ) : (
              <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="link-list">
                {links.map((link) => (
                  <LinkCard key={link.id} link={link} onOpen={() => { setOpenId(link.id); setDrawer(true) }} onCopy={() => void copy(link.url)} onQr={() => setQr(link)} onAnalytics={() => setAnalyticsId(link.id)} />
                ))}
              </ul>
            )}
          </>
        )}

        {tab === 'forms' && <FormsPanel canEdit={can.has(user, 'tracking.create')} />}
        {tab === 'destinations' && <DestinationsPanel canEdit={can.has(user, 'tracking.create')} />}
        {tab === 'qr' && (isLoading ? <Skeleton className="h-48" /> : <QrCenter links={links} onOpen={(id) => { setOpenId(id); setDrawer(true) }} />)}

        {tab === 'health' && <HealthPanel onGo={setTab} />}
        {tab === 'attribution' && <AttributionPanel days={days} />}
      </div>

      <QrDialog link={qr} onClose={() => setQr(null)} />
      <LinkWizard open={wizard} onClose={() => setWizard(false)} onAnalytics={(l) => { setWizard(false); setAnalyticsId(l.id) }} onQr={setQr} />
      <LinkAnalyticsDrawer linkId={analyticsId} onClose={() => setAnalyticsId(null)} />
      <LinkDrawer open={drawer} linkId={openId} canEdit={canEdit} onClose={() => setDrawer(false)} />
    </>
  )
}
