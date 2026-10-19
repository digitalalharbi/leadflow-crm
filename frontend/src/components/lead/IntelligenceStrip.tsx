import { Mail, MessageCircle, Phone } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { MiniTrend } from '@/components/charts/MiniTrend'
import { ProgressBar, RingProgress, TONE_COLOR, type Tone } from '@/components/visual'
import { cn } from '@/lib/cn'
import type { IntelMetric, LeadIntelligence } from '@/features/leads'

const TONE: Record<IntelMetric['level'], Tone> = { high: 'good', medium: 'warn', low: 'bad' }

/**
 * Seven numbers, and no sentences: rings for the things that fill up, a badge
 * for how long it has been quiet, three icons for how the person can be
 * reached, a bar for how much is known, a sparkline for the last two weeks.
 * The one line beneath is what they actually did.
 */
export function IntelligenceStrip({ intelligence }: { intelligence: LeadIntelligence | undefined }) {
  const { t } = useTranslation()

  if (!intelligence) return null

  const { health, intent, engagement, response, staleness, contactability, completeness } = intelligence

  const ring = (key: string, metric: IntelMetric, suffix = '') => (
    <Tile key={key} label={t(`intel.${key}`)} testId={`intel-${key}`} hint={t(`intel.${key}Hint`)}>
      <RingProgress value={metric.value} size={52} thickness={6} tone={TONE[metric.level]} label={t(`intel.${key}`)}>
        <span className="text-[0.9375rem] font-bold tabular-nums">{metric.value}{suffix}</span>
      </RingProgress>
    </Tile>
  )

  return (
    <div data-testid="intelligence" className="mb-4">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
        <Tile label={t('intel.health')} testId="intel-health" hint={t('intel.healthHint')} emphasised>
          <RingProgress value={health.value} size={64} thickness={8} tone={TONE[health.level]} label={t('intel.health')}>
            <span className="text-[1.125rem] font-bold tabular-nums">{health.value}</span>
          </RingProgress>
        </Tile>

        {ring('intent', intent)}

        <Tile label={t('intel.engagement')} testId="intel-engagement" hint={t('intel.engagementHint')}>
          <RingProgress value={engagement.value} size={52} thickness={6} tone={TONE[engagement.level]} label={t('intel.engagement')}>
            <span className="text-[0.9375rem] font-bold tabular-nums">{engagement.value}</span>
          </RingProgress>
          {intelligence.trend && (
            <div className="mt-1" data-testid="intel-trend">
              <MiniTrend values={intelligence.trend} color={TONE_COLOR[TONE[engagement.level]]} label={t('intel.trend')} />
            </div>
          )}
        </Tile>

        {ring('response', response, '%')}

        <Tile label={t('intel.staleness')} testId="intel-staleness" hint={t('intel.stalenessHint')}>
          <span
            data-level={staleness.level}
            className={cn(
              'inline-flex items-center rounded-full px-3 py-1 text-[1rem] font-bold tabular-nums',
              staleness.level === 'low' && 'bg-[color-mix(in_srgb,var(--color-danger)_14%,transparent)] text-[var(--color-danger)]',
              staleness.level === 'medium' && 'bg-[color-mix(in_srgb,var(--color-warning)_16%,transparent)] text-[var(--color-warning)]',
              staleness.level === 'high' && 'bg-[color-mix(in_srgb,var(--color-success)_14%,transparent)] text-[var(--color-success)]',
            )}
          >
            {t('intel.days', { count: staleness.days })}
          </span>
        </Tile>

        <Tile label={t('intel.contactability')} testId="intel-contactability" hint={t('intel.contactabilityHint')}>
          <div className="flex items-center gap-2">
            {([['whatsapp', MessageCircle], ['email', Mail], ['call', Phone]] as const).map(([key, Icon]) => (
              <span
                key={key}
                data-channel={key}
                data-on={contactability.channels[key]}
                title={t(`intel.channel.${key}`)}
                className={cn(
                  'grid size-8 place-items-center rounded-full',
                  contactability.channels[key]
                    ? 'bg-[var(--brand-soft)] text-[var(--brand)]'
                    : 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-subtle)] line-through opacity-60',
                )}
              >
                <Icon className="size-4" aria-hidden />
              </span>
            ))}
          </div>
        </Tile>

        <Tile label={t('intel.completeness')} testId="intel-completeness" hint={t('intel.completenessHint')}>
          <div className="w-full px-2">
            <ProgressBar value={completeness.value} tone={TONE[completeness.level]} />
            <p className="mt-1 text-center text-[0.875rem] font-bold tabular-nums">{completeness.value}%</p>
          </div>
        </Tile>
      </div>

      {intelligence.reason && (
        <p className="mt-2 text-[1rem] font-semibold text-[var(--color-ink)]" data-testid="intel-reason">
          {intelligence.reason}
        </p>
      )}
    </div>
  )
}

function Tile({
  label, hint, testId, emphasised, children,
}: { label: string; hint?: string; testId: string; emphasised?: boolean; children: React.ReactNode }) {
  return (
    <div
      data-testid={testId}
      title={hint}
      className={cn(
        'flex min-h-[7rem] flex-col items-center justify-between gap-1 rounded-[var(--radius-card)] bg-[var(--color-surface)] px-2 py-2.5 shadow-[var(--shadow-card)]',
        emphasised && 'ring-1 ring-[var(--brand)]/30',
      )}
    >
      <div className="flex flex-1 flex-col items-center justify-center">{children}</div>
      <span className="text-center text-[0.875rem] font-semibold text-[var(--color-ink-muted)]">{label}</span>
    </div>
  )
}
