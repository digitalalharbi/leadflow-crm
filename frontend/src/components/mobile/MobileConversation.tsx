import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, CheckCheck, Clock, ExternalLink, Send } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchLeadMessages, fetchWhatsappCapability, sendLeadMessage } from '@/features/conversations'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, whatsappLink } from '@/lib/format'
import { cn } from '@/lib/cn'
import { usePwa } from '@/stores/pwa'
import { useUi } from '@/stores/ui'
import { Button, Skeleton } from '@/components/ui'
import type { Lead, LeadMessage } from '@/types/api'

/**
 * The conversation, as the biggest thing on a phone's lead screen.
 *
 * A chat, not a card: the messages fill the space between the header and the composer, newest at the
 * bottom and already scrolled to, each with who sent it, when, over what channel and whether it arrived.
 * The composer is a single line that grows, with one send button the size of a thumb. Anything else about
 * the lead lives in a sheet; there is no sidebar here.
 *
 * What the message record does not carry — attachments, a per-message "read by the rep" flag — is not
 * invented: "new" is the customer's messages since the last reply, which is what a rep wants to know.
 */
export function MobileConversation({ lead, focusToken }: { lead: Lead; focusToken: number }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const online = usePwa((s) => s.online)

  const [body, setBody] = useState('')
  const [page, setPage] = useState(1)
  const composer = useRef<HTMLTextAreaElement>(null)
  const end = useRef<HTMLDivElement>(null)

  const capability = useQuery({ queryKey: ['whatsapp-capability'], queryFn: fetchWhatsappCapability, staleTime: 5 * 60_000 })
  const { data, isLoading } = useQuery({
    queryKey: ['lead-messages', lead.id, page],
    queryFn: () => fetchLeadMessages(lead.id, page),
    // A rep answering a customer wants the reply when it lands, without pulling to refresh.
    refetchInterval: 15_000,
  })

  const optedOut = Boolean(lead.whatsapp_opt_out_at)
  const canSend = Boolean(capability.data?.can_send) && !optedOut

  const send = useMutation({
    mutationFn: () => sendLeadMessage(lead.id, { body }),
    onSuccess: (result) => {
      setBody('')
      void queryClient.invalidateQueries({ queryKey: ['lead-messages', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })

      if (result.message.status === 'failed') toast(result.message.failure_reason ?? t('whatsapp.status.failed'), 'error')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  // Oldest first, as a thread reads. WhatsApp only: email has its own place.
  const messages = (data?.data ?? []).filter((m) => m.channel === 'whatsapp').toReversed()
  const hasMore = (data?.meta?.last_page ?? 1) > page

  // New customer messages since the last thing we sent.
  const lastOutbound = messages.findLastIndex((m) => m.direction === 'outbound')
  const unread = messages.slice(lastOutbound + 1).filter((m) => m.direction === 'inbound').length

  // Land on the newest message, and again when one arrives.
  useEffect(() => {
    end.current?.scrollIntoView({ block: 'end' })
  }, [messages.length, isLoading])

  // "Message" in the header asks for the composer.
  useEffect(() => {
    if (focusToken > 0) {
      composer.current?.focus()
      composer.current?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    }
  }, [focusToken])

  return (
    <section aria-label={t('mobileSales.messages.title')} className="flex min-h-0 flex-1 flex-col" data-testid="mobile-conversation">
      <header className="flex items-center justify-between gap-2 px-1 pb-1.5">
        <h2 className="text-[1.0625rem] font-semibold">{t('mobileSales.messages.title')}</h2>
        <div className="flex items-center gap-1.5">
          {unread > 0 && (
            <span className="rounded-full bg-[var(--accent-soft)] px-2.5 py-0.5 text-[0.875rem] font-semibold text-[var(--accent-text)]" data-testid="conversation-unread">
              {t('mobileSales.messages.unread', { count: unread })}
            </span>
          )}
          <span className="rounded-full bg-[var(--color-chrome-fill)] px-2.5 py-0.5 text-[0.875rem] font-medium text-[var(--color-ink-muted)]">{t('mobileSales.messages.channel')}</span>
        </div>
      </header>

      <div className="min-h-0 flex-1 overflow-y-auto rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] p-2.5" data-testid="conversation-thread" role="log" aria-live="polite">
        {isLoading ? (
          <Skeleton className="h-40" />
        ) : messages.length === 0 ? (
          <p className="py-10 text-center text-[1rem] text-[var(--color-ink-muted)]" data-testid="conversation-empty">{t('mobileSales.messages.empty')}</p>
        ) : (
          <ul className="space-y-2">
            {hasMore && (
              <li className="text-center">
                <Button variant="ghost" size="sm" onClick={() => setPage((p) => p + 1)}>{t('mobileSales.messages.loadMore')}</Button>
              </li>
            )}
            {messages.map((message) => (
              <Bubble key={message.id} message={message} />
            ))}
          </ul>
        )}
        <div ref={end} />
      </div>

      {optedOut && <p className="notice notice-danger mt-2">{t('mobileSales.messages.optedOut')}</p>}

      <form
        className="mt-2 flex items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          if (canSend && body.trim() && !send.isPending && online) send.mutate()
        }}
      >
        <textarea
          ref={composer}
          rows={1}
          value={body}
          disabled={optedOut}
          placeholder={t('mobileSales.messages.placeholder')}
          aria-label={t('mobileSales.messages.placeholder')}
          data-testid="mobile-message-input"
          onChange={(e) => {
            setBody(e.target.value)
            // Grows with what is written, to a few lines, then scrolls.
            e.target.style.height = 'auto'
            e.target.style.height = `${Math.min(e.target.scrollHeight, 120)}px`
          }}
          className="min-h-12 flex-1 resize-none rounded-2xl border border-[var(--color-line)] bg-[var(--color-surface)] px-4 py-3 text-[1rem] leading-snug outline-none focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--brand-ring)] disabled:opacity-50"
        />

        {canSend ? (
          <Button type="submit" size="lg" className="size-12 shrink-0 !px-0" loading={send.isPending} disabled={!body.trim() || !online} aria-label={t('mobileSales.messages.send')} data-testid="mobile-send">
            <Send className="size-5" aria-hidden />
          </Button>
        ) : (
          // Without a connected account the product does not pretend to send: it hands over to WhatsApp, message ready.
          <a
            href={whatsappLink(lead.whatsapp, body || undefined) ?? undefined}
            target="_blank"
            rel="noreferrer"
            aria-disabled={optedOut || !lead.whatsapp}
            aria-label={t('mobileSales.messages.openWhatsapp')}
            data-testid="mobile-open-whatsapp"
            className={cn('pressable grid size-12 shrink-0 place-items-center rounded-full bg-[var(--brand)] text-[var(--brand-contrast)]', (optedOut || !lead.whatsapp) && 'pointer-events-none opacity-45')}
          >
            <ExternalLink className="size-5" aria-hidden />
          </a>
        )}
      </form>
    </section>
  )
}

function Bubble({ message }: { message: LeadMessage }) {
  const { t } = useTranslation()
  const outbound = message.direction === 'outbound'
  const sender = outbound ? (message.sent_by?.name ?? t('mobileSales.messages.automation')) : t('mobileSales.messages.customer')

  return (
    <li className={cn('flex', outbound ? 'justify-start' : 'justify-end')} data-testid={`message-${message.direction}`}>
      <div
        className={cn(
          'max-w-[86%] rounded-2xl px-3 py-2 text-[1rem] leading-snug',
          outbound ? 'bg-[var(--brand-soft)]' : 'bg-[var(--color-surface-sunken)]',
          message.status === 'failed' && 'ring-1 ring-[var(--color-danger)]',
        )}
      >
        <p className="mb-0.5 text-[0.8125rem] font-semibold text-[var(--color-ink-muted)]">
          {sender}
          {message.template_name && <span className="font-normal"> · {t('mobileSales.messages.template')}: {message.template_name}</span>}
        </p>
        <p className="whitespace-pre-wrap break-words">{message.body}</p>
        <div className="mt-1 flex items-center gap-1.5 text-[0.8125rem] text-[var(--color-ink-subtle)]">
          <span className="tabular">{formatDateTime(message.created_at)}</span>
          {outbound && <Delivery message={message} />}
        </div>
        {message.status === 'failed' && message.failure_reason && <p className="mt-1 text-[0.8125rem] text-[var(--color-danger)]">{message.failure_reason}</p>}
      </div>
    </li>
  )
}

function Delivery({ message }: { message: LeadMessage }) {
  const { t } = useTranslation()
  const icon = {
    pending: <Clock className="size-3" />,
    sent: <Check className="size-3" />,
    delivered: <CheckCheck className="size-3" />,
    read: <CheckCheck className="size-3 text-[var(--color-temp-cold)]" />,
    failed: <AlertTriangle className="size-3 text-[var(--color-danger)]" />,
    received: null,
  }[message.status]

  return (
    <span className="flex items-center gap-1" data-testid={`message-status-${message.status}`}>
      {icon}
      <span className="sr-only">{t(`whatsapp.status.${message.status}`)}</span>
    </span>
  )
}
