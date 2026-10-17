import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Ban, Check, CheckCheck, Clock, ExternalLink, Send } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  fetchLeadMessages, fetchMessageTemplates, fetchWhatsappCapability,
  previewTemplate, sendLeadMessage, setWhatsappOptOut,
} from '@/features/conversations'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, whatsappLink } from '@/lib/format'
import { Badge, Button, Card, CardBody, CardHeader, Select, Skeleton, Textarea } from '@/components/ui'
import type { Lead, LeadMessage } from '@/types/api'
import { cn } from '@/lib/cn'

/**
 * The conversation with one lead. Deliberately not an inbox: no cross-lead
 * view, no unread counters, no assignment from here. A rep works a lead, and
 * the conversation is one part of that lead.
 */
export function Conversation({ lead }: { lead: Lead }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [body, setBody] = useState('')
  const [templateId, setTemplateId] = useState('')
  const [page, setPage] = useState(1)

  // The capability endpoint, not the settings one: a rep may not read which
  // credentials are stored, but needs to know whether this button sends.
  const { data: capability } = useQuery({
    queryKey: ['whatsapp-capability'],
    queryFn: fetchWhatsappCapability,
    staleTime: 5 * 60 * 1000,
  })
  const { data: templateData } = useQuery({ queryKey: ['message-templates'], queryFn: () => fetchMessageTemplates() })
  const { data, isLoading } = useQuery({
    queryKey: ['lead-messages', lead.id, page],
    queryFn: () => fetchLeadMessages(lead.id, page),
    /*
     * Polled only while something is still in flight, and not at all otherwise.
     *
     * A send that hits a blip — the network dropped, Meta answered 503 — is now
     * held pending and retried in the background, so this thread is the one
     * screen where the answer arrives a minute after the rep asked for it.
     * Without this it reads "جارٍ الإرسال" until they navigate away and back,
     * which is a worse lie than the "فشل الإرسال" it replaced.
     */
    refetchInterval: (query) =>
      query.state.data?.data.some((message) => message.channel === 'whatsapp' && message.status === 'pending')
        ? 10_000
        : false,
  })

  const optedOut = Boolean(lead.whatsapp_opt_out_at)
  const canSend = Boolean(capability?.can_send) && !optedOut

  const optOut = useMutation({
    mutationFn: (value: boolean) => setWhatsappOptOut(lead.id, value),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      toast(t('common.saved'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const send = useMutation({
    mutationFn: () => sendLeadMessage(lead.id, { body, template_uuid: templateId || undefined }),
    onSuccess: (result) => {
      setBody('')
      setTemplateId('')
      void queryClient.invalidateQueries({ queryKey: ['lead-messages', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })

      // A provider rejection comes back as a recorded message, not an error,
      // so the rep is told rather than left thinking it went out.
      if (result.message.status === 'failed') {
        toast(result.message.failure_reason ?? t('whatsapp.status.failed'), 'error')
      }
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const chooseTemplate = async (id: string) => {
    setTemplateId(id)
    if (!id) return

    try {
      // Rendered on the server, so what the rep sees is exactly what is sent —
      // including any placeholder that had to be dropped.
      const preview = await previewTemplate(lead.id, id)
      setBody(preview.body)
    } catch (e) {
      toast(translateError(e), 'error')
    }
  }

  // The API returns newest first for pagination; the thread reads oldest first.
  // Email lives in its own panel; this thread is WhatsApp's alone.
  const messages = (data?.data ?? []).filter((m) => m.channel === 'whatsapp').toReversed()
  const hasMore = (data?.meta?.last_page ?? 1) > page

  return (
    <Card data-testid="conversation">
      <CardHeader
        title={t('whatsapp.conversation')}
        action={
          <div className="flex items-center gap-2">
            {optedOut ? (
              <Badge tone="danger" testId="opted-out-badge">{t('whatsapp.optedOutShort')}</Badge>
            ) : capability?.mode === 'mock' ? (
              <Badge tone="warning">{t('integrations.configurationRequired')}</Badge>
            ) : null}

            {/* Consent lives beside the conversation, because that is where a
                rep is when the customer asks them to stop. */}
            <Button
              size="sm"
              variant="ghost"
              loading={optOut.isPending}
              onClick={() => optOut.mutate(!optedOut)}
              data-testid="toggle-opt-out"
            >
              <Ban className="size-4" />
              {optedOut ? t('whatsapp.optIn') : t('whatsapp.optOut')}
            </Button>
          </div>
        }
      />

      <CardBody className="space-y-4">
        {optedOut && (
          <p className="notice notice-danger">{t('whatsapp.optedOut')}</p>
        )}

        {isLoading ? (
          <Skeleton className="h-32" />
        ) : messages.length === 0 ? (
          <div className="py-6 text-center">
            <p className="text-sm text-[var(--color-ink-muted)]">{t('whatsapp.noMessages')}</p>
            <p className="mt-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('whatsapp.noMessagesHint')}</p>
          </div>
        ) : (
          <div className="space-y-2" data-testid="message-list">
            {hasMore && (
              <div className="text-center">
                <Button variant="ghost" size="sm" onClick={() => setPage((p) => p + 1)}>
                  {t('whatsapp.loadMore')}
                </Button>
              </div>
            )}
            {messages.map((message) => (
              <MessageBubble key={message.id} message={message} />
            ))}
          </div>
        )}

        <div className="space-y-2 border-t border-[var(--color-border)] pt-4">
          {(templateData?.templates.length ?? 0) > 0 && (
            <Select
              label={t('whatsapp.templates')}
              value={templateId}
              data-testid="template-select"
              onChange={(e) => void chooseTemplate(e.target.value)}
            >
              <option value="">{t('whatsapp.chooseTemplate')}</option>
              {templateData?.templates.map((template) => (
                <option key={template.id} value={template.id}>{template.name}</option>
              ))}
            </Select>
          )}

          <Textarea
            rows={3}
            value={body}
            disabled={optedOut}
            placeholder={t('whatsapp.messagePlaceholder')}
            data-testid="message-input"
            onChange={(e) => setBody(e.target.value)}
          />

          <div className="flex flex-wrap items-center gap-2">
            {canSend ? (
              <Button
                onClick={() => send.mutate()}
                loading={send.isPending}
                disabled={body.trim().length === 0}
                data-testid="send-message"
              >
                <Send className="size-4" />
                {t('whatsapp.sendVia')}
              </Button>
            ) : (
              /*
               * Without a connected account the product does not pretend to
               * send: it hands the rep off to WhatsApp with the message ready,
               * which is what they were doing before this feature existed.
               */
              <a
                href={whatsappLink(lead.whatsapp, body || undefined) ?? undefined}
                target="_blank"
                rel="noreferrer"
                className={cn((optedOut || !lead.whatsapp) && 'pointer-events-none opacity-50')}
              >
                <Button variant="secondary" disabled={optedOut || !lead.whatsapp} data-testid="open-whatsapp">
                  <ExternalLink className="size-4" />
                  {t('whatsapp.openWhatsapp')}
                </Button>
              </a>
            )}

            {!canSend && !optedOut && (
              <span className="text-[0.8125rem] text-[var(--color-ink-muted)]">
                {t('whatsapp.notConnectedHint')}
              </span>
            )}
          </div>
        </div>
      </CardBody>
    </Card>
  )
}

function MessageBubble({ message }: { message: LeadMessage }) {
  const outbound = message.direction === 'outbound'

  return (
    <div className={cn('flex', outbound ? 'justify-start' : 'justify-end')} data-testid={`message-${message.direction}`}>
      <div
        className={cn(
          'max-w-[85%] rounded-[var(--radius-panel)] px-3 py-2 text-sm sm:max-w-[75%]',
          outbound
            ? 'bg-[var(--brand-soft)] text-[var(--color-ink)]'
            : 'bg-[var(--color-surface-sunken)] text-[var(--color-ink)]',
          message.status === 'failed' && 'ring-1 ring-[var(--color-status-lost)]',
        )}
      >
        {message.template_name && (
          <p className="mb-1 text-[0.6875rem] text-[var(--color-ink-subtle)]">{message.template_name}</p>
        )}

        <p className="whitespace-pre-wrap break-words">{message.body}</p>

        {/* The delivery state lives on the message, not in the timeline: a
            separate activity row for every "delivered" would bury the things a
            rep actually needs to read. */}
        <div className="mt-1 flex items-center gap-1.5 text-[0.6875rem] text-[var(--color-ink-subtle)]">
          <span className="tabular">{formatDateTime(message.created_at)}</span>
          {outbound && <StatusMark message={message} />}
        </div>

        {message.status === 'failed' && message.failure_reason && (
          <p className="mt-1 text-[0.6875rem] text-[var(--color-status-lost)]">{message.failure_reason}</p>
        )}
      </div>
    </div>
  )
}

function StatusMark({ message }: { message: LeadMessage }) {
  const { t } = useTranslation()
  const label = t(`whatsapp.status.${message.status}`)

  const icon = {
    pending: <Clock className="size-3" />,
    sent: <Check className="size-3" />,
    delivered: <CheckCheck className="size-3" />,
    read: <CheckCheck className="size-3 text-[var(--color-temp-cold)]" />,
    failed: <AlertTriangle className="size-3 text-[var(--color-status-lost)]" />,
    received: null,
  }[message.status]

  return (
    <span className="flex items-center gap-1" title={label} data-testid={`message-status-${message.status}`}>
      {icon}
      <span className="sr-only">{label}</span>
    </span>
  )
}
