import { useQuery } from '@tanstack/react-query'
import { Bot, Eye, Inbox, MessageCircle, Send, UserRound } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody } from '@/components/ui'
import { RingProgress } from '@/components/visual'
import { CLASSIFICATION_COLOR } from '@/components/visual/classification'
import { fetchLeadMessages } from '@/features/conversations'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'
import type { Lead } from '@/types/api'

/**
 * Why this lead, in three facts and a ring: how serious (score), what kind
 * (classification and its reason), and where it came from (platform and
 * campaign). The sentence a rep would otherwise reconstruct from four cards.
 */
export function WhyThisLead({ lead, signals = [] }: { lead: Lead; signals?: string[] }) {
  const { t } = useTranslation()
  const tone = lead.score >= 70 ? 'good' : lead.score >= 40 ? 'warn' : 'neutral'

  return (
    <Card data-testid="why-this-lead">
      <CardBody className="space-y-3">
        <h2 className="t-card-title">{t('workspace.why.title')}</h2>
        <div className="flex items-center gap-3">
          <RingProgress value={lead.score} size={64} thickness={7} tone={tone} label={t('leads.score')}>
            <span className="text-[1.125rem] font-extrabold tabular-nums">{lead.score}</span>
          </RingProgress>
          <div className="min-w-0 space-y-1">
            {lead.classification && (
              <p className="flex items-center gap-1.5 text-[0.875rem] font-semibold">
                <span className="size-2.5 rounded-full" style={{ background: CLASSIFICATION_COLOR[lead.classification.key] }} aria-hidden />
                {t(`classification.${lead.classification.key}`)}
              </p>
            )}
            {lead.classification && (
              <p className="text-[0.75rem] text-[var(--color-ink-muted)]">{t(`classification.reason.${lead.classification.reason}`)}</p>
            )}
          </div>
        </div>
        {/* Why now: what the customer did, as chips — the facts behind the next action. */}
        <div data-testid="why-now">
          <p className="mb-1 text-[0.75rem] font-semibold text-[var(--color-ink-muted)]">{t('workspace.why.now')}</p>
          {signals.length > 0 ? (
            <ul className="flex flex-wrap gap-1">
              {signals.map((signal) => (
                <li key={signal} className="rounded-full bg-[color-mix(in_srgb,var(--brand)_10%,transparent)] px-2 py-0.5 text-[0.75rem] font-medium text-[var(--brand)]">
                  {t(`work.signal.${signal}`)}
                </li>
              ))}
            </ul>
          ) : (
            <p className="t-caption">{t('workspace.why.noSignals')}</p>
          )}
        </div>
        <dl className="grid grid-cols-2 gap-2 text-[0.75rem]">
          <Fact label={t('leads.project')} value={lead.project?.name} />
          <Fact label={t('leads.platform')} value={lead.platform ? t(`platforms.${lead.platform}`, { defaultValue: lead.platform }) : null} />
          <Fact label={t('workspace.why.campaign')} value={lead.campaign_name} wide />
        </dl>
      </CardBody>
    </Card>
  )
}

function Fact({ label, value, wide = false }: { label: string; value: string | null | undefined; wide?: boolean }) {
  return (
    <div className={cn('min-w-0 rounded-xl bg-[var(--color-surface-muted)] px-2.5 py-1.5', wide && 'col-span-2')}>
      <dt className="text-[0.6875rem] text-[var(--color-ink-subtle)]">{label}</dt>
      <dd className="truncate font-semibold">{value || '—'}</dd>
    </div>
  )
}

/**
 * Who is holding this lead right now: the journey, or a person — and if a
 * person, why it became their turn. The answer to "should I write to them, or
 * is the automation already doing it?".
 */
export function AutomationStatus({ lead, onShowSteps }: { lead: Lead; onShowSteps: () => void }) {
  const { t } = useTranslation()
  const state = lead.automation_state ?? 'none'

  const look = {
    nurturing: { icon: Bot, ring: 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]' },
    handed_off: { icon: UserRound, ring: 'bg-[color-mix(in_srgb,var(--color-warning)_15%,transparent)] text-[color-mix(in_srgb,var(--color-warning)_80%,black)]' },
    none: { icon: Bot, ring: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]' },
  }[state]
  const Icon = look.icon

  return (
    <Card data-testid="automation-status">
      <CardBody className="space-y-2.5">
        <div className="flex items-center gap-3">
          <span className={cn('grid size-10 shrink-0 place-items-center rounded-full', look.ring)} aria-hidden>
            <Icon className="size-5" />
          </span>
          <div className="min-w-0">
            <p className="t-card-title" data-testid="automation-state">{t(`workspace.automation.${state}`)}</p>
            {state === 'handed_off' && lead.needs_human_reason && (
              <p className="text-[0.75rem] text-[var(--color-ink-muted)]">{t(`workspace.handoff.${lead.needs_human_reason}`, { defaultValue: lead.needs_human_reason })}</p>
            )}
            {state === 'nurturing' && <p className="text-[0.75rem] text-[var(--color-ink-muted)]">{t('workspace.automation.nurturingHint')}</p>}
          </div>
        </div>
        <button type="button" onClick={onShowSteps} className="text-[0.75rem] font-medium text-[var(--brand)] hover:underline">
          {t('workspace.automation.steps')}
        </button>
      </CardBody>
    </Card>
  )
}

/**
 * Has anybody heard from them — as numbers, not a transcript: what went out,
 * what came back, what was opened, and the share answered as a ring.
 */
export function Engagement({ lead }: { lead: Lead }) {
  const { t, i18n } = useTranslation()
  const { data } = useQuery({
    queryKey: ['lead-messages', lead.id, 1],
    queryFn: () => fetchLeadMessages(lead.id, 1),
  })

  const messages = data?.data ?? []
  const sent = messages.filter((m) => m.direction === 'outbound').length
  const received = messages.filter((m) => m.direction === 'inbound').length
  const opened = messages.filter((m) => m.direction === 'outbound' && m.read_at).length
  const whatsapp = messages.filter((m) => m.channel === 'whatsapp').length
  const replyRate = sent > 0 ? Math.min(100, Math.round((received / sent) * 100)) : 0

  const tiles: Array<{ key: string; icon: LucideIcon; value: number }> = [
    { key: 'sent', icon: Send, value: sent },
    { key: 'received', icon: Inbox, value: received },
    { key: 'opened', icon: Eye, value: opened },
    { key: 'whatsapp', icon: MessageCircle, value: whatsapp },
  ]

  return (
    <Card data-testid="lead-engagement">
      <CardBody className="space-y-3">
        <h2 className="t-card-title">{t('workspace.engagement.title')}</h2>
        <div className="flex items-center gap-3">
          <RingProgress value={replyRate} size={64} thickness={7} tone={replyRate > 0 ? 'good' : 'neutral'} label={t('workspace.engagement.replyRate')}>
            <span className="text-[0.9375rem] font-extrabold tabular-nums" dir="ltr">{replyRate}%</span>
          </RingProgress>
          <ul className="grid flex-1 grid-cols-2 gap-1.5">
            {tiles.map(({ key, icon: Icon, value }) => (
              <li key={key} className="flex items-center gap-1.5 rounded-xl bg-[var(--color-surface-muted)] px-2 py-1.5" data-testid={`engagement-${key}`}>
                <Icon className="size-3.5 text-[var(--color-ink-muted)]" aria-hidden />
                <span className="text-[1rem] font-bold tabular-nums">{formatNumber(value, i18n.language)}</span>
                <span className="truncate text-[0.6875rem] text-[var(--color-ink-muted)]">{t(`workspace.engagement.${key}`)}</span>
              </li>
            ))}
          </ul>
        </div>
      </CardBody>
    </Card>
  )
}
