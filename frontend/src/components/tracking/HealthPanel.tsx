import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, CheckCircle2, CircleSlash, Copy as CopyIcon, FileWarning, PlugZap, Radio, ShieldAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Skeleton } from '@/components/ui'
import { platformColor } from '@/components/charts'
import { AreaTrend, RingProgress } from '@/components/visual'
import { fetchTrackingHealth, type HealthRecommendation, type HealthStatus, type PlatformHealth } from '@/features/tracking'
import { formatNumber, formatRelative } from '@/lib/format'
import { cn } from '@/lib/cn'

const STATUS: Record<HealthStatus, { tone: 'good' | 'warn' | 'bad' | 'neutral'; Icon: typeof CheckCircle2 }> = {
  healthy: { tone: 'good', Icon: CheckCircle2 },
  attention: { tone: 'warn', Icon: AlertTriangle },
  risk: { tone: 'bad', Icon: ShieldAlert },
  idle: { tone: 'neutral', Icon: CircleSlash },
}

const TONE_VAR = { good: 'var(--color-success)', warn: 'var(--color-warning)', bad: 'var(--color-danger)', neutral: 'var(--color-ink-subtle)' }
const PLATFORM_COLOR_KEY: Record<string, string> = { meta: 'meta', tiktok: 'tiktok', snapchat: 'snapchat', google_ads: 'google_ads', x: 'x', linkedin: 'linkedin', landing_page: 'website' }

const DAYS = [7, 14, 30] as const

/**
 * Is tracking working? Eight platforms, each one card: a word, a ring for how much of what it
 * sends is accepted, when it last spoke, what it refused, and whether sales are getting back to it.
 * The sentences on top are what to do about it.
 */
export function HealthPanel({ onGo }: { onGo: (tab: 'links' | 'attribution') => void }) {
  const { t, i18n } = useTranslation()
  const [days, setDays] = useState<(typeof DAYS)[number]>(7)

  const { data, isLoading } = useQuery({ queryKey: ['tracking-health', days], queryFn: () => fetchTrackingHealth(days), placeholderData: (p) => p, refetchInterval: 120_000 })

  const name = (p: string) => t(`tracking.platforms.${p}`)
  const sentence = (r: HealthRecommendation) => t(`tracking.rec.${r.key}`, { ...r.params, platform: r.platform ? name(r.platform) : '' })
  const summary = data?.summary

  return (
    <div className="space-y-3" data-testid="health-panel">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-3" data-testid="health-summary">
          <Stat icon={PlugZap} testId="hs-connected" label={t('tracking.health.connected')} value={summary ? `${summary.connected}/${summary.platforms}` : '—'} />
          <Stat icon={Radio} testId="hs-receiving" label={t('tracking.health.receiving')} value={summary ? `${summary.receiving}/${summary.platforms}` : '—'} />
          <Stat icon={ShieldAlert} testId="hs-risk" label={t('tracking.health.atRisk')} value={summary ? String(summary.at_risk) : '—'} tone={summary && summary.at_risk > 0 ? 'bad' : 'good'} />
          <Stat icon={FileWarning} testId="hs-unattributed" label={t('tracking.unattributedRing')} value={summary?.unattributed_percent == null ? '—' : `${summary.unattributed_percent}%`} tone={summary?.unattributed_percent != null && summary.unattributed_percent >= 10 ? 'warn' : 'good'} />
        </div>
        <div role="group" aria-label={t('tracking.health.window')} className="inline-flex gap-1 rounded-full bg-[var(--color-chrome-fill)] p-1">
          {DAYS.map((d) => (
            <button key={d} type="button" aria-pressed={days === d} data-testid={`health-days-${d}`} onClick={() => setDays(d)}
              className={cn('pressable rounded-full px-3 py-1 text-[0.9375rem] font-semibold', days === d ? 'bg-[var(--color-surface)] shadow-[var(--shadow-card)]' : 'text-[var(--color-ink-muted)]')}>
              {t('dashboard.lastDays', { count: d })}
            </button>
          ))}
        </div>
      </div>

      {data && data.recommendations.length > 0 && (
        <ul className="grid gap-2 md:grid-cols-2" data-testid="health-recommendations">
          {data.recommendations.map((r, i) => (
            <li key={`${r.key}-${r.platform}-${i}`} data-severity={r.severity} data-key={r.key}
              className={cn('flex items-center gap-3 rounded-[var(--radius-card)] border-s-4 bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]', r.severity === 'critical' ? 'border-s-[var(--color-danger)]' : 'border-s-[var(--color-warning)]')}>
              {r.severity === 'critical' ? <ShieldAlert className="size-5 shrink-0 text-[var(--color-danger)]" aria-hidden /> : <AlertTriangle className="size-5 shrink-0 text-[var(--color-warning)]" aria-hidden />}
              <span className="min-w-0 flex-1 text-[1rem] font-semibold">{sentence(r)}</span>
              {r.to !== 'health' && (
                <button type="button" onClick={() => onGo(r.to as 'links' | 'attribution')} className="pressable grid size-8 place-items-center rounded-full bg-[var(--color-surface-muted)]" aria-label={t(`tracking.tabs.${r.to}`)}>
                  <ArrowLeft className="size-4" aria-hidden />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {isLoading && !data ? <Skeleton className="h-72" /> : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="health-platforms">
          {data?.platforms.map((p) => <PlatformCard key={p.platform} p={p} name={name(p.platform)} lang={i18n.language} />)}
        </ul>
      )}
    </div>
  )
}

function Stat({ icon: Icon, label, value, testId, tone = 'neutral' }: { icon: typeof PlugZap; label: string; value: string; testId: string; tone?: 'good' | 'warn' | 'bad' | 'neutral' }) {
  return (
    <div className="flex items-center gap-2 rounded-full bg-[var(--color-surface)] py-1 ps-1 pe-3 shadow-[var(--shadow-card)]" data-testid={testId}>
      <span className="grid size-8 place-items-center rounded-full" style={{ background: `color-mix(in srgb, ${TONE_VAR[tone]} 14%, transparent)`, color: TONE_VAR[tone] }}><Icon className="size-4" aria-hidden /></span>
      <span className="text-[1.125rem] font-extrabold tabular-nums" data-testid={`${testId}-value`}>{value}</span>
      <span className="text-[0.875rem] text-[var(--color-ink-muted)]">{label}</span>
    </div>
  )
}

function PlatformCard({ p, name, lang }: { p: PlatformHealth; name: string; lang: string }) {
  const { t } = useTranslation()
  const { tone, Icon } = STATUS[p.status]
  const color = platformColor(PLATFORM_COLOR_KEY[p.platform] ?? null)
  const rate = p.success_rate

  return (
    <li data-testid={`health-${p.platform}`} data-status={p.status} className="rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)]">
      <div className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-2 text-[1.0625rem] font-extrabold"><span className="size-3 rounded-full" style={{ background: color }} aria-hidden />{name}</span>
        <span className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[0.875rem] font-bold" style={{ background: `color-mix(in srgb, ${TONE_VAR[tone]} 14%, transparent)`, color: TONE_VAR[tone] }} data-testid={`health-${p.platform}-status`}>
          <Icon className="size-3.5" aria-hidden />{t(`tracking.health.status.${p.status}`)}
        </span>
      </div>

      <div className="mt-2 flex items-center gap-3">
        <RingProgress value={rate ?? 0} size={64} thickness={8} tone={rate === null ? 'neutral' : rate >= 95 ? 'good' : rate >= 80 ? 'warn' : 'bad'} label={t('tracking.health.success')} testId={`health-${p.platform}-ring`}>
          <span className="text-[0.9375rem] font-extrabold tabular-nums" data-testid={`health-${p.platform}-rate`}>{rate === null ? '—' : `${Math.round(rate)}%`}</span>
        </RingProgress>
        <div className="min-w-0 flex-1 text-[0.9375rem]">
          <p className="font-semibold" data-testid={`health-${p.platform}-events`}>{formatNumber(p.events, lang)} {t('tracking.health.events')}</p>
          <p className="text-[var(--color-ink-subtle)]" data-testid={`health-${p.platform}-last`}>{p.last_event_at ? formatRelative(p.last_event_at, lang) : t('tracking.health.never')}</p>
        </div>
      </div>

      <div className="mt-2" data-testid={`health-${p.platform}-trend`}>
        <AreaTrend height={44} points={p.trend.map((value, i) => ({ label: String(i + 1), value }))} />
      </div>

      <ul className="mt-2 flex flex-wrap gap-1.5 text-[0.8125rem] font-semibold">
        {p.rejected > 0 && <Chip tone="bad" testId={`health-${p.platform}-rejected`}>{t('tracking.health.rejected', { count: p.rejected })}</Chip>}
        {p.duplicates > 0 && <Chip tone="warn" testId={`health-${p.platform}-duplicates`}>{t('tracking.health.duplicates', { count: p.duplicates })}</Chip>}
        {(p.missing_utm_percent ?? 0) > 0 && <Chip tone="warn" testId={`health-${p.platform}-utm`}>{t('tracking.health.noUtm', { percent: p.missing_utm_percent })}</Chip>}
        {(p.unattributed_percent ?? 0) > 0 && <Chip tone="warn" testId={`health-${p.platform}-unattributed`}>{t('tracking.health.unattributed', { percent: p.unattributed_percent })}</Chip>}
      </ul>

      {p.offline && (
        <p className="mt-2 flex items-center gap-1.5 text-[0.875rem] text-[var(--color-ink-muted)]" data-testid={`health-${p.platform}-offline`} data-state={p.offline.state}>
          <CopyIcon className="size-3.5" aria-hidden />
          <span>{t('tracking.health.offline')}: {t(`integrationState.${p.offline.state}`, { defaultValue: p.offline.state })}</span>
          {p.offline.won_waiting > 0 && <span className="font-bold text-[var(--color-danger)]">· {t('tracking.health.wonWaiting', { count: p.offline.won_waiting })}</span>}
        </p>
      )}
    </li>
  )
}

function Chip({ tone, children, testId }: { tone: 'bad' | 'warn'; children: React.ReactNode; testId: string }) {
  return <li data-testid={testId} className="rounded-full px-2 py-0.5" style={{ background: `color-mix(in srgb, ${TONE_VAR[tone]} 14%, transparent)`, color: TONE_VAR[tone] }}>{children}</li>
}
