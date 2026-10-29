import { useState } from 'react'
import { HOME } from '@/lib/routes'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, ExternalLink, PartyPopper, Timer, Zap } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchNextInQueue } from '@/features/work'
import { useSalesFlow } from '@/features/salesFlow'
import { useApiError } from '@/hooks/useApiError'
import { useFocusMode } from '@/hooks/useFocusMode'
import { formatElapsed } from '@/lib/format'
import { cn } from '@/lib/cn'
import { usePwa } from '@/stores/pwa'
import { ErrorState, Skeleton, StatusBadge, TemperatureBadge } from '@/components/ui'
import { InterestBadge } from '@/components/visual/InterestBadge'
import { OutcomeSheet } from '@/components/mobile/OutcomeSheet'
import { SalesActionBar } from '@/components/mobile/SalesActionBar'

/**
 * «اعمل الآن» on a phone: one lead, and nothing else.
 *
 * Who it is, how interested they are, which project, how long they have waited, why this one is first,
 * and what to do about it — then Call, WhatsApp, Open lead and Skip. Saying how it went moves to the next
 * lead on its own. The queue list, the pulse and the timeline that surround the card on a laptop are not
 * here: on a phone they were the part nobody scrolled to.
 */
export function MobileWork() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const translateError = useApiError()
  const online = usePwa((s) => s.online)
  useFocusMode()

  const [skipped, setSkipped] = useState<string[]>([])

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['work-next', skipped],
    queryFn: () => fetchNextInQueue(skipped),
    refetchOnWindowFocus: false,
  })

  const lead = data?.lead ?? undefined
  const action = data?.action

  const flow = useSalesFlow({
    lead,
    skip: skipped,
    onRecorded: (next) => {
      // The next lead arrives in the same response; show it without asking again.
      const advanced = [...skipped, lead!.id]

      queryClient.setQueryData(['work-next', advanced], next)
      setSkipped(advanced)
    },
  })

  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  const skip = () => lead && setSkipped((prev) => [...prev, lead.id])

  const header = (
    <header className="flex items-center gap-2">
      <Link to={HOME} aria-label={t('mobileSales.back')} className="pressable grid size-11 shrink-0 place-items-center rounded-full bg-[var(--color-surface)] ring-1 ring-inset ring-[var(--color-line)]" data-testid="mobile-back">
        <Back className="size-5" aria-hidden />
      </Link>
      <div className="min-w-0 flex-1">
        <h1 className="truncate text-[1.375rem] font-bold leading-tight" data-testid="page-title">{t('work.title')}</h1>
        {data && data.remaining > 0 && <p className="t-caption" data-testid="mobile-work-remaining">{t('work.remaining', { count: data.remaining })}</p>}
      </div>
    </header>
  )

  if (isLoading) {
    return (
      <div className="min-h-dvh space-y-3 p-3" aria-busy="true">
        {header}
        <Skeleton className="h-72" />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-3 p-3">
        {header}
        <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
      </div>
    )
  }

  if (!lead || !action) {
    return (
      <div className="space-y-4 p-3" data-testid="work-empty">
        {header}
        <div className="grid place-items-center gap-3 rounded-3xl border border-[var(--color-line)] bg-[var(--color-surface)] px-6 py-14 text-center">
          <PartyPopper className="size-10 text-[var(--accent)]" aria-hidden />
          <p className="text-[1.125rem] font-semibold">{t('mobileSales.work.empty')}</p>
          <Link to="/leads" className="pressable inline-flex min-h-12 items-center rounded-full bg-[var(--brand)] px-6 font-semibold text-[var(--brand-contrast)]">{t('nav.leads')}</Link>
        </div>
      </div>
    )
  }

  const sla = data?.sla
  const late = sla ? sla.state === 'breached' || sla.minutes_left < 0 : false

  return (
    <div className="sales-pad flex min-h-dvh flex-col gap-3 bg-[var(--color-canvas)] px-3 pt-[max(0.5rem,env(safe-area-inset-top))]" data-testid="mobile-work">
      {header}

      <article className="space-y-3 rounded-3xl border border-[var(--color-line)] bg-[var(--color-surface)] p-4 shadow-[var(--shadow-card)]" data-testid="work-card">
        <div className="space-y-2">
          <Link to={`/leads/${lead.id}`} className="block text-[1.625rem] font-bold leading-tight" data-testid="work-lead-name">{lead.full_name}</Link>
          <div className="flex flex-wrap items-center gap-1.5">
            <InterestBadge level={data?.interest?.level ?? lead.interest_level} />
            <StatusBadge status={lead.status} />
            <TemperatureBadge temperature={lead.temperature} priority={lead.is_priority} />
          </div>
          <p className="text-[1rem] text-[var(--color-ink-muted)]" data-testid="mobile-work-meta">
            {[lead.project?.name, lead.city].filter(Boolean).join(' · ')}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-[0.9375rem]">
          <span className="inline-flex items-center gap-1 rounded-full bg-[var(--color-chrome-fill)] px-3 py-1 font-medium" data-testid="mobile-waiting">
            <Timer className="size-4" aria-hidden />
            {t('mobileSales.work.waiting', { when: formatElapsed(data?.profile?.waiting_since ?? lead.created_at, i18n.language) })}
          </span>
          {sla && sla.state !== 'done' && (
            <span className={cn('rounded-full px-3 py-1 font-semibold', late ? 'bg-[color-mix(in_srgb,var(--color-danger)_14%,transparent)] text-[var(--color-danger)]' : 'bg-[var(--status-warn-bg)] text-[var(--status-warn-ink)]')} data-testid="mobile-sla">
              {late ? t('mobileSales.slaLate', { count: Math.abs(sla.minutes_left) }) : t('mobileSales.slaLeft', { count: sla.minutes_left })}
            </span>
          )}
        </div>

        <div className="space-y-1.5 rounded-2xl bg-[var(--color-surface-muted)] p-3">
          <p className="flex items-start gap-2 text-[1rem] leading-snug" data-testid="work-reason">
            <Zap className="mt-0.5 size-4 shrink-0 text-[var(--accent-text)]" aria-hidden />
            <span><span className="font-semibold">{t('mobileSales.whyNow')}: </span>{action.reason}</span>
          </p>
          {data?.rank?.reason && <p className="t-small text-[var(--color-ink-muted)]" data-testid="work-rank-reason">{t('work.rank.label')}: {data.rank.reason}</p>}
          <p className="text-[1rem]" data-testid="mobile-next-action"><span className="font-semibold">{t('mobileSales.nextBest')}: </span>{t(`work.action.${action.action}`)}</p>
        </div>

        <Link to={`/leads/${lead.id}`} className="pressable flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-[var(--color-surface-muted)] text-[1rem] font-semibold ring-1 ring-inset ring-[var(--color-line)]" data-testid="work-open-lead">
          <ExternalLink className="size-5" aria-hidden />
          {t('mobileSales.work.openLead')}
        </Link>
      </article>

      <SalesActionBar
        whatsapp={{
          // A workspace that sends from inside the product answers in the lead's own thread; otherwise WhatsApp opens by its deep link.
          href: flow.whatsapp.internal ? undefined : flow.whatsapp.href,
          external: !flow.whatsapp.internal,
          disabled: flow.whatsapp.disabled,
          onClick: flow.whatsapp.internal ? () => navigate(`/leads/${lead.id}?compose=1`) : flow.whatsapp.onOpened,
        }}
        call={{ href: flow.call.href, disabled: flow.call.disabled, onClick: flow.call.onClick }}
        onOutcome={() => flow.outcome.setOpen(true)}
        onNext={skip}
        nextLabel={t('mobileSales.work.skip')}
        pulseOutcome={flow.startedCall}
        problem={!online ? t('mobileSales.offline') : flow.call.problem ?? flow.whatsapp.problem}
      />

      <OutcomeSheet open={flow.outcome.open} onClose={() => flow.outcome.setOpen(false)} onSave={flow.outcome.save} pending={flow.outcome.pending} online={flow.outcome.online} hasNext={(data?.remaining ?? 0) > 1} />
    </div>
  )
}
