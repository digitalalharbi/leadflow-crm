import { lazy, Suspense, useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, CalendarCheck, Info, MessageCircle, MessageSquare, Phone, Timer, Zap } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchLead, type LeadDetailResponse } from '@/features/leads'
import { fetchNextInQueue, type NextInQueue } from '@/features/work'
import { useSalesFlow } from '@/features/salesFlow'
import { skippedLeads, skipLead } from '@/features/salesSession'
import { useFocusMode } from '@/hooks/useFocusMode'
import { formatLastSeen, formatMoneyCompact } from '@/lib/format'
import { cn } from '@/lib/cn'
import { can, useAuth } from '@/stores/auth'
import { usePwa } from '@/stores/pwa'
import { useUi } from '@/stores/ui'
import { ErrorState, Modal, Skeleton, StatusBadge, TemperatureBadge } from '@/components/ui'
import { InterestBadge } from '@/components/visual/InterestBadge'
import { AppointmentDialog } from '@/components/lead/AppointmentDialog'
import { MobileConversation } from '@/components/mobile/MobileConversation'
import { OutcomeSheet } from '@/components/mobile/OutcomeSheet'
import { SalesActionBar } from '@/components/mobile/SalesActionBar'
import { useApiError } from '@/hooks/useApiError'

// Loaded on demand: the details a rep reads after the call, not before it.
const LeadSecondary = lazy(() => import('@/components/mobile/LeadSecondary'))

/**
 * A lead, on a phone, built for the next ten seconds.
 *
 * Top to bottom: who it is and why it matters now, the four things to do about it at a thumb's reach,
 * a short read on the lead, and then the conversation — the biggest part of the screen — with its
 * composer. Everything else (the timeline, the long cards, the charts-adjacent panels) is one tap away
 * in a sheet and is not even downloaded until it is asked for. The app bar and tab bar step aside; the
 * action bar at the bottom is this screen's own, and stays put while the thread scrolls.
 *
 * It does not replace the desktop lead page — a phone simply gets this one (see App's LeadRoute).
 */
export function MobileLeadScreen() {
  const { id = '' } = useParams()
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const user = useAuth((s) => s.user)
  const online = usePwa((s) => s.online)
  const translateError = useApiError()
  useFocusMode()

  const [detailsOpen, setDetailsOpen] = useState(false)
  const [appointmentOpen, setAppointmentOpen] = useState(false)
  const [composeToken, setComposeToken] = useState(0)
  const [moving, setMoving] = useState(false)
  const [params, setParams] = useSearchParams()

  const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['lead', id], queryFn: () => fetchLead(id), enabled: Boolean(id) })

  const lead = data?.lead

  // Arrived from "WhatsApp" on the work screen of a workspace that sends from inside the product: the composer is ready.
  useEffect(() => {
    if (lead && params.get('compose') === '1') {
      setComposeToken((n) => n + 1)
      setParams((p) => { p.delete('compose'); return p }, { replace: true })
    }
  }, [lead, params, setParams])

  const canWork = can.has(user, 'leads.execute')

  const goTo = (next: NextInQueue | null | undefined) => {
    if (next?.lead) navigate(`/leads/${next.lead.id}`, { replace: true })
    else {
      toast(t('mobileSales.outcome.noNext'), 'info')
      navigate('/work', { replace: true })
    }
  }

  const flow = useSalesFlow({ lead, skip: [id, ...skippedLeads()], onRecorded: (next) => goTo(next) })

  /** Next: the lead after this one, without recording anything. */
  const next = async () => {
    if (moving || !lead) return

    setMoving(true)

    try {
      const skipped = skipLead(lead.id)
      const result = await fetchNextInQueue(skipped)

      if (result?.lead) navigate(`/leads/${result.lead.id}`, { replace: true })
      else toast(t('mobileSales.next.none'), 'info')
    } catch (caught) {
      toast(translateError(caught), 'error')
    } finally {
      setMoving(false)
    }
  }

  const back = () => (window.history.length > 1 ? navigate(-1) : navigate('/work'))
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  if (isLoading) {
    return (
      <div className="min-h-dvh space-y-3 p-3" aria-busy="true">
        <Skeleton className="h-24" />
        <Skeleton className="h-16" />
        <Skeleton className="h-64" />
      </div>
    )
  }

  if (isError || !lead || !data) {
    return (
      <div className="p-3">
        <ErrorState message={translateError(error)} onRetry={() => void refetch()} />
      </div>
    )
  }

  const action = data.next_action
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['lead', id] })
  const money = (value: number | null | undefined) => formatMoneyCompact(value, 'SAR', i18n.language)
  const budget = lead.budget_max ?? lead.budget_min

  return (
    <div className={cn('mobile-lead flex min-h-dvh flex-col gap-2.5 bg-[var(--color-canvas)] px-3 pt-[max(0.5rem,env(safe-area-inset-top))]', canWork && 'sales-pad')} data-testid="mobile-lead">
      {/* Who, and how much they matter. */}
      <header className="space-y-2">
        <div className="flex items-center gap-2">
          <button type="button" onClick={back} aria-label={t('mobileSales.back')} className="pressable grid size-11 shrink-0 place-items-center rounded-full bg-[var(--color-surface)] ring-1 ring-inset ring-[var(--color-line)]" data-testid="mobile-back">
            <Back className="size-5" aria-hidden />
          </button>
          <h1 className="min-w-0 flex-1 truncate text-[1.375rem] font-bold leading-tight" data-testid="mobile-lead-name">{lead.full_name}</h1>
          <SlaChip sla={data.sla ?? null} />
        </div>

        <div className="flex flex-wrap items-center gap-1.5" data-testid="mobile-lead-badges">
          <InterestBadge level={lead.interest_level} />
          <StatusBadge status={lead.status} />
          <TemperatureBadge temperature={lead.temperature} priority={lead.is_priority} />
          {lead.project?.name && <span className="rounded-full bg-[var(--color-chrome-fill)] px-2.5 py-0.5 text-[0.875rem] font-medium" data-testid="mobile-lead-project">{lead.project.name}</span>}
        </div>

        {/* Large, fixed, thumb-sized: the four ways to reach them. */}
        <div className="grid grid-cols-4 gap-1.5" data-testid="mobile-quick">
          <QuickButton testId="quick-whatsapp" icon={<MessageCircle className="size-5" aria-hidden />} label={t('mobileSales.quick.whatsapp')}
            href={flow.whatsapp.href} external onClick={flow.whatsapp.internal ? () => setComposeToken((n) => n + 1) : flow.whatsapp.onOpened} disabled={flow.whatsapp.disabled} />
          <QuickButton testId="quick-call" tone="primary" icon={<Phone className="size-5" aria-hidden />} label={t('mobileSales.quick.call')} href={flow.call.href} onClick={flow.call.onClick} disabled={flow.call.disabled} />
          <QuickButton testId="quick-message" icon={<MessageSquare className="size-5" aria-hidden />} label={t('mobileSales.quick.message')} onClick={() => setComposeToken((n) => n + 1)} />
          <QuickButton testId="quick-appointment" icon={<CalendarCheck className="size-5" aria-hidden />} label={t('mobileSales.quick.appointment')} onClick={() => setAppointmentOpen(true)} />
        </div>
      </header>

      {/* Why now, and the short read — compact, so the conversation keeps the screen. */}
      <section className="space-y-1.5 rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-2.5" data-testid="mobile-intel">
        <div className="flex items-start gap-2">
          <span className="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-[var(--accent-soft)] text-[var(--accent-text)]" aria-hidden><Zap className="size-4" /></span>
          <p className="min-w-0 flex-1 text-[0.9375rem] leading-snug" data-testid="mobile-why-now">
            <span className="font-semibold">{t('mobileSales.whyNow')}: </span>
            {action?.reason ?? data.intelligence?.reason ?? '—'}
          </p>
          <button type="button" onClick={() => setDetailsOpen(true)} aria-label={t('mobileSales.moreInfo')} className="pressable -me-1 -mt-1 grid size-11 shrink-0 place-items-center rounded-full text-[var(--brand-text)]" data-testid="mobile-more-info">
            <Info className="size-5" aria-hidden />
          </button>
        </div>

        <dl className="grid grid-cols-3 gap-1 text-center">
          <Fact label={t('mobileSales.interest')} value={lead.interest_level ? t(`interest.level.${lead.interest_level}`, { defaultValue: lead.interest_level }) : '—'} />
          <Fact label={t('mobileSales.qualification')} value={t(`qualification.status.${lead.qualification_status}`)} />
          <Fact label={t('mobileSales.budget')} value={budget ? money(budget) : t('mobileSales.noBudget')} />
          <Fact label={t('mobileSales.lastActivity')} value={lead.last_contact_at ? formatLastSeen(lead.last_contact_at, i18n.language) : t('mobileSales.noActivity')} />
          <Fact label={t('mobileSales.nextBest')} value={action ? t(`work.action.${action.action}`) : '—'} wide />
        </dl>
      </section>

      {/* The conversation takes the rest of the screen. */}
      <div className="flex min-h-[22rem] flex-1 flex-col">
        <MobileConversation lead={lead} focusToken={composeToken} />
      </div>

      {canWork && (
        <SalesActionBar
          whatsapp={{ href: flow.whatsapp.href, external: true, disabled: flow.whatsapp.disabled, onClick: flow.whatsapp.internal ? () => setComposeToken((n) => n + 1) : flow.whatsapp.onOpened }}
          call={{ href: flow.call.href, disabled: flow.call.disabled, onClick: flow.call.onClick }}
          onOutcome={() => flow.outcome.setOpen(true)}
          onNext={() => void next()}
          busy={moving}
          pulseOutcome={flow.startedCall}
          problem={!online ? t('mobileSales.offline') : flow.call.problem ?? flow.whatsapp.problem}
        />
      )}

      <OutcomeSheet open={flow.outcome.open} onClose={() => flow.outcome.setOpen(false)} onSave={flow.outcome.save} pending={flow.outcome.pending} online={flow.outcome.online} />

      <AppointmentDialog leadId={lead.id} open={appointmentOpen} onClose={() => setAppointmentOpen(false)} onBooked={refresh} />

      <Modal open={detailsOpen} onClose={() => setDetailsOpen(false)} title={t('mobileSales.details')} size="lg">
        <Suspense fallback={<Skeleton className="h-48" />}>
          <LeadSecondary data={data as LeadDetailResponse} />
        </Suspense>
      </Modal>
    </div>
  )
}

function SlaChip({ sla }: { sla: LeadDetailResponse['sla'] }) {
  const { t } = useTranslation()

  // No clock (a lead that never needed a reply, or one already answered): nothing to say.
  if (!sla || sla.state === 'done') return null

  const late = sla.state === 'breached' || sla.minutes_left < 0

  return (
    <span
      data-testid="mobile-sla"
      data-state={sla.state}
      className={cn(
        'inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[0.875rem] font-semibold',
        late ? 'bg-[color-mix(in_srgb,var(--color-danger)_14%,transparent)] text-[var(--color-danger)]' : 'bg-[var(--status-warn-bg)] text-[var(--status-warn-ink)]',
      )}
    >
      <Timer className="size-3.5" aria-hidden />
      {late ? t('mobileSales.slaLate', { count: Math.abs(sla.minutes_left) }) : t('mobileSales.slaLeft', { count: sla.minutes_left })}
    </span>
  )
}

function Fact({ label, value, wide = false }: { label: string; value: string; wide?: boolean }) {
  return (
    <div className={cn('min-w-0 rounded-xl bg-[var(--color-surface-muted)] px-1.5 py-1', wide && 'col-span-2')}>
      <dt className="truncate text-[0.8125rem] text-[var(--color-ink-muted)]">{label}</dt>
      <dd className="truncate text-[0.9375rem] font-semibold">{value}</dd>
    </div>
  )
}

function QuickButton({
  icon, label, href, external, onClick, disabled, testId, tone = 'neutral',
}: {
  icon: React.ReactNode
  label: string
  href?: string
  external?: boolean
  onClick?: () => void
  disabled?: boolean
  testId: string
  tone?: 'neutral' | 'primary'
}) {
  const className = cn(
    'pressable flex min-h-14 flex-col items-center justify-center gap-0.5 rounded-2xl px-1 py-1.5 text-[0.8125rem] font-semibold leading-tight',
    tone === 'primary' ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface)] text-[var(--color-ink)] ring-1 ring-inset ring-[var(--color-line)]',
    disabled && 'pointer-events-none opacity-45',
  )

  if (href && !disabled) {
    return <a href={href} target={external ? '_blank' : undefined} rel={external ? 'noreferrer' : undefined} onClick={onClick} className={className} data-testid={testId}>{icon}<span className="truncate">{label}</span></a>
  }

  return <button type="button" onClick={onClick} disabled={disabled} className={className} data-testid={testId}>{icon}<span className="truncate">{label}</span></button>
}
