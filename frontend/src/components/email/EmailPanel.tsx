import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Ban, Clock, Mail, MailCheck, MailOpen, Paperclip, Send } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchEmailCapability, sendLeadEmail, setEmailOptOut } from '@/features/email'
import { fetchLeadMessages, fetchMessageTemplates } from '@/features/conversations'
import { fetchFilesForLead, shareFileWithLead } from '@/features/files'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime } from '@/lib/format'
import { Badge, Button, Input, Select, Skeleton, Textarea } from '@/components/ui'
import type { Lead, LeadMessage } from '@/types/api'
import { cn } from '@/lib/cn'

/**
 * Email to one lead, beside their WhatsApp.
 *
 * Same shape as the WhatsApp thread on purpose: a rep who has learnt one has
 * learnt both. What differs is what email needs — a subject — and what it can
 * tell you that WhatsApp cannot honestly: whether it was opened.
 *
 * The state words are the channel's own. "Sent" means a mail server took it;
 * "opened" means somebody's software fetched the pixel. Neither is "delivered",
 * which email has no receipt for, and a tick that said so would be a guess.
 */
export function EmailPanel({ lead }: { lead: Lead }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [templateId, setTemplateId] = useState('')

  const optedOut = Boolean(lead.email_opt_out_at)
  const noAddress = !lead.email

  const { data: capability } = useQuery({
    queryKey: ['email-capability'],
    queryFn: fetchEmailCapability,
    staleTime: 5 * 60 * 1000,
  })
  const { data: templates } = useQuery({
    queryKey: ['message-templates', 'email'],
    queryFn: () => fetchMessageTemplates(false, 'email'),
  })
  const { data, isLoading } = useQuery({
    queryKey: ['lead-messages', lead.id, 1],
    queryFn: () => fetchLeadMessages(lead.id, 1),
    refetchInterval: (query) =>
      query.state.data?.data.some((m) => m.channel === 'email' && m.status === 'pending') ? 10_000 : false,
  })

  /*
   * The file the product would send this lead, as a link.
   *
   * Not an attachment: an attachment is a copy that nobody can count, and a
   * link is a per-lead address whose opens move the score. The same chooser and
   * the same share the lead screen uses, with the channel set to email so the
   * hand-off is recorded as one.
   */
  const { data: files } = useQuery({
    queryKey: ['lead-files', lead.id],
    queryFn: () => fetchFilesForLead(lead.id),
    enabled: Boolean(lead.project),
  })
  const suggested = files?.suggested ?? null

  const attach = useMutation({
    mutationFn: () => shareFileWithLead(lead.id, suggested!.id, 'email'),
    onSuccess: (result) => {
      setBody((current) => `${current.trimEnd()}${current.trim() ? '\n\n' : ''}${result.share.url}`)
      void queryClient.invalidateQueries({ queryKey: ['lead-shares', lead.id] })
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const emails = (data?.data ?? []).filter((m) => m.channel === 'email').toReversed()

  const send = useMutation({
    mutationFn: () =>
      sendLeadEmail(lead.id, {
        subject: subject || undefined,
        body: body || undefined,
        template_uuid: templateId || undefined,
      }),
    onSuccess: () => {
      setSubject('')
      setBody('')
      setTemplateId('')
      void queryClient.invalidateQueries({ queryKey: ['lead-messages', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      toast(t('email.queued'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const optOut = useMutation({
    mutationFn: (value: boolean) => setEmailOptOut(lead.id, value),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      toast(t('common.saved'), 'success')
    },
    onError: (e) => toast(translateError(e), 'error'),
  })

  const chooseTemplate = (id: string) => {
    setTemplateId(id)
    const template = templates?.templates.find((item) => item.id === id)
    // Shown as written, placeholders and all; the server fills them in when it
    // sends, and a preview that differed from the send would be a second source
    // of truth about what the customer reads.
    if (template) {
      setSubject(template.subject ?? '')
      setBody(template.body)
    }
  }

  const blocked = optedOut || noAddress
  // A template that has been chosen and not edited is sent by id, so the server
  // renders it; the moment the rep types, what they typed is what goes.
  const ready = !blocked && subject.trim() !== '' && body.trim() !== ''

  return (
    <div className="space-y-4" data-testid="email-panel">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-[0.8125rem] text-[var(--color-ink-muted)]">
          <Mail className="size-4" aria-hidden />
          <span dir="ltr" className="tabular">{lead.email ?? t('email.noAddress')}</span>
          {optedOut && <Badge tone="danger" testId="email-opted-out-badge">{t('email.optedOutShort')}</Badge>}
          {capability?.mode === 'mock' && (
            <Badge tone="warning" testId="email-mock-badge">{t('email.mockMode')}</Badge>
          )}
        </div>

        <Button
          size="sm"
          variant="ghost"
          loading={optOut.isPending}
          onClick={() => optOut.mutate(!optedOut)}
          data-testid="toggle-email-opt-out"
        >
          <Ban className="size-4" />
          {optedOut ? t('email.optIn') : t('email.optOut')}
        </Button>
      </div>

      {capability?.mode === 'mock' && (
        <p className="notice" data-testid="email-mock-notice">{t('email.mockHint')}</p>
      )}
      {optedOut && <p className="notice notice-danger">{t('email.optedOut')}</p>}
      {noAddress && <p className="notice">{t('email.noAddressHint')}</p>}

      {isLoading ? (
        <Skeleton className="h-24" />
      ) : emails.length === 0 ? (
        <p className="py-3 text-center text-sm text-[var(--color-ink-muted)]">{t('email.none')}</p>
      ) : (
        <ul className="space-y-2" data-testid="email-list">
          {emails.map((message) => (
            <EmailRow key={message.id} message={message} />
          ))}
        </ul>
      )}

      <div className="space-y-2 border-t border-[var(--color-border)] pt-4">
        {(templates?.templates.length ?? 0) > 0 && (
          <Select
            label={t('email.templates')}
            value={templateId}
            data-testid="email-template-select"
            disabled={blocked}
            onChange={(e) => chooseTemplate(e.target.value)}
          >
            <option value="">{t('email.chooseTemplate')}</option>
            {templates?.templates.map((template) => (
              <option key={template.id} value={template.id}>{template.name}</option>
            ))}
          </Select>
        )}

        <Input
          value={subject}
          disabled={blocked}
          placeholder={t('email.subject')}
          aria-label={t('email.subject')}
          data-testid="email-subject"
          onChange={(e) => setSubject(e.target.value)}
        />
        <Textarea
          rows={5}
          value={body}
          disabled={blocked}
          placeholder={t('email.bodyPlaceholder')}
          aria-label={t('email.body')}
          data-testid="email-body"
          onChange={(e) => setBody(e.target.value)}
        />

        <div className="flex flex-wrap items-center gap-2">
          <Button
            onClick={() => send.mutate()}
            loading={send.isPending}
            disabled={!ready}
            data-testid="send-email"
          >
            <Send className="size-4" />
            {t('email.send')}
          </Button>

          {suggested && (
            <Button
              variant="secondary"
              onClick={() => attach.mutate()}
              loading={attach.isPending}
              disabled={blocked}
              data-testid="email-attach-file"
            >
              <Paperclip className="size-4" />
              {t('email.attachFile', { name: suggested.name })}
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}

function EmailRow({ message }: { message: LeadMessage }) {
  const { t } = useTranslation()
  const opened = Boolean(message.read_at)

  const mark = {
    pending: { icon: Clock, tone: 'text-[var(--color-ink-subtle)]' },
    sent: { icon: MailCheck, tone: 'text-[var(--color-ink-muted)]' },
    delivered: { icon: MailCheck, tone: 'text-[var(--color-ink-muted)]' },
    read: { icon: MailOpen, tone: 'text-[var(--color-temp-cold)]' },
    failed: { icon: AlertTriangle, tone: 'text-[var(--color-status-lost)]' },
    received: { icon: Mail, tone: 'text-[var(--color-ink-muted)]' },
  }[message.status]
  const Icon = mark.icon

  return (
    <li
      className={cn(
        'rounded-[var(--radius-panel)] bg-[var(--brand-soft)] px-3 py-2 text-sm',
        message.status === 'failed' && 'ring-1 ring-[var(--color-status-lost)]',
      )}
      data-testid="email-row"
    >
      <div className="flex items-start justify-between gap-2">
        <p className="min-w-0 truncate font-medium">{message.subject}</p>
        <span
          className={cn('flex shrink-0 items-center gap-1 text-[0.6875rem]', mark.tone)}
          data-testid={`email-status-${message.status}`}
        >
          <Icon className="size-3.5" aria-hidden />
          {opened ? t('email.status.read') : t(`email.status.${message.status}`)}
        </span>
      </div>
      <p className="mt-1 line-clamp-2 whitespace-pre-wrap break-words text-[0.8125rem] text-[var(--color-ink-muted)]">
        {message.body}
      </p>
      <p className="mt-1 text-[0.6875rem] text-[var(--color-ink-subtle)] tabular">
        {formatDateTime(message.created_at)}
      </p>
      {message.status === 'failed' && message.failure_reason && (
        <p className="mt-1 text-[0.6875rem] text-[var(--color-status-lost)]">{message.failure_reason}</p>
      )}
    </li>
  )
}
