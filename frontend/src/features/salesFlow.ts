import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { fetchWhatsappCapability } from '@/features/conversations'
import { logContact, recordCall, recordOutcome, type NextInQueue, type Outcome } from '@/features/work'
import { useApiError } from '@/hooks/useApiError'
import { dialTarget, whatsappTarget, type Dial } from '@/lib/dial'
import { usePwa } from '@/stores/pwa'
import { useUi } from '@/stores/ui'

/**
 * What a rep does with one lead on a phone, in one place: ring, message, say how it went, move on.
 *
 * The two screens that use it (the lead and the work queue) differ in layout and in what "next" means,
 * not in these rules:
 *
 *  - Pressing Call hands the number to the phone's dialer and records only that a call was *started*,
 *    with the time and the rep. Nothing is claimed about how it went until the rep says so in the
 *    outcome sheet, which opens by itself when they come back from the dialer.
 *  - A number that cannot be dialled is reported, never handed to the dialer.
 *  - An outcome is saved once. A second press while the first is in flight, or a second sheet for a lead
 *    already recorded a moment ago, is ignored; and with no connection it is refused in words, not queued
 *    to be sent later behind the rep's back.
 */
export interface SalesLead {
  id: string
  phone: string | null
  whatsapp: string | null
}

export function useSalesFlow({
  lead,
  skip = [],
  onRecorded,
}: {
  lead: SalesLead | undefined
  /** Leads already passed over this session, so the next one is never one the rep just skipped. */
  skip?: string[]
  /** Called with the next lead (or null) once an outcome is saved. */
  onRecorded: (next: NextInQueue | null, outcome: Outcome) => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const online = usePwa((s) => s.online)

  const dial: Dial = dialTarget(lead?.phone)
  const [openCall, setOpenCall] = useState<{ leadId: string; callId: string } | null>(null)
  const [outcomeOpen, setOutcomeOpen] = useState(false)
  // The rep has gone to the dialer: the sheet opens when the page is shown again.
  const awaiting = useRef(false)
  const placing = useRef(false)
  const saving = useRef(false)
  const recordedAt = useRef<Map<string, number>>(new Map())

  const capability = useQuery({ queryKey: ['whatsapp-capability'], queryFn: fetchWhatsappCapability, staleTime: 5 * 60_000 })
  /** True when this workspace sends WhatsApp from inside the product: the thread is the place to write. */
  const internalWhatsapp = Boolean(capability.data?.can_send)

  const refresh = useCallback(() => {
    if (lead) void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
    void queryClient.invalidateQueries({ queryKey: ['work-summary'] })
    void queryClient.invalidateQueries({ queryKey: ['leads'] })
  }, [lead, queryClient])

  const place = useMutation({
    mutationFn: (channel: 'call' | 'whatsapp') => logContact(lead!.id, { channel }),
    onSuccess: (result, channel) => {
      if (channel === 'call' && result.call) setOpenCall({ leadId: lead!.id, callId: result.call.id })
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
    onSettled: () => { placing.current = false },
  })

  /** Props for the Call control: a real link to the dialer, and the record that it was started. */
  const call = {
    href: dial.ok ? dial.href : undefined,
    disabled: !dial.ok,
    onClick: () => {
      if (!lead || !dial.ok || placing.current) return

      // One record per press, however many times a thumb lands.
      placing.current = true
      awaiting.current = true
      toast(t('mobileSales.callStarted'), 'info')
      place.mutate('call')
    },
    /** Why it cannot be pressed, in words — or null when it can. */
    problem: dial.ok ? null : dial.reason === 'missing' ? t('mobileSales.callMissing') : t('mobileSales.callInvalid', { number: lead?.phone ?? '' }),
  }

  const waLink = whatsappTarget(lead?.whatsapp)

  /** WhatsApp: the internal thread when the workspace sends from here, otherwise WhatsApp itself, by its own deep link. */
  const whatsapp = {
    internal: internalWhatsapp,
    href: internalWhatsapp ? undefined : waLink ?? undefined,
    disabled: !lead?.whatsapp || (!internalWhatsapp && !waLink),
    /** Records that WhatsApp was opened (not that anything was sent). */
    onOpened: () => {
      if (lead && !internalWhatsapp && waLink) place.mutate('whatsapp')
    },
    problem: lead?.whatsapp ? null : t('mobileSales.waMissing'),
  }

  // Back from the dialer: ask how it went, instead of hoping the rep remembers to.
  useEffect(() => {
    const onShow = () => {
      if (document.visibilityState === 'visible' && awaiting.current) {
        awaiting.current = false
        setOutcomeOpen(true)
      }
    }

    document.addEventListener('visibilitychange', onShow)
    window.addEventListener('pageshow', onShow)

    return () => {
      document.removeEventListener('visibilitychange', onShow)
      window.removeEventListener('pageshow', onShow)
    }
  }, [])

  const outcome = useMutation({
    mutationFn: (payload: { choice: Outcome; note?: string }) =>
      recordOutcome({ lead_id: lead!.id, outcome: payload.choice, note: payload.note || undefined, skip }),
    onSuccess: (result, payload) => {
      if (openCall && openCall.leadId === lead!.id) {
        void recordCall(openCall.leadId, openCall.callId, { status: payload.choice === 'no_answer' ? 'no_answer' : 'answered', outcome: payload.choice })
        setOpenCall(null)
      }

      recordedAt.current.set(lead!.id, Date.now())
      setOutcomeOpen(false)
      toast(t(`work.recorded.${payload.choice}`), 'success')
      refresh()
      void queryClient.invalidateQueries({ queryKey: ['follow-up-counts'] })
      onRecorded(result.next, payload.choice)
    },
    onError: (caught) => toast(translateError(caught), 'error'),
    onSettled: () => { saving.current = false },
  })

  /** Saves the outcome once. Returns false when it was refused (offline, already saving, or just saved). */
  const save = useCallback((choice: Outcome, note?: string): boolean => {
    if (!lead || saving.current || outcome.isPending) return false
    if (!online) return false

    const last = recordedAt.current.get(lead.id)

    if (last && Date.now() - last < 10_000) return false

    saving.current = true
    outcome.mutate({ choice, note })

    return true
  }, [lead, online, outcome])

  return {
    dial,
    call,
    whatsapp,
    outcome: { open: outcomeOpen, setOpen: setOutcomeOpen, save, pending: outcome.isPending, online },
    startedCall: openCall !== null,
  }
}
