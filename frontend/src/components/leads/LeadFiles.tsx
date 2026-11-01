import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, FileText, Mail, MessageCircle, Send } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useState } from 'react'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatRelative } from '@/lib/format'
import { Badge, Button, Card, CardBody, CardHeader, Select, Skeleton } from '@/components/ui'
import { fetchFilesForLead, fetchLeadShares, revokeLeadShare, sendFileToLead, shareFileWithLead } from '@/features/files'
import type { Lead } from '@/types/api'

/**
 * The file this lead should be sent, and whether they opened the last one.
 *
 * The product already decides which file suits a lead — their language, and
 * whether they said they were buying to invest or needed financing — and says
 * why. All of it was reachable only from an API nobody called. This is the
 * screen that was missing, so the choice is a suggestion a rep can see and
 * disagree with rather than something that happens to them.
 *
 * Opens are the reason the link is per lead rather than per file. "Did this
 * customer open the brochure" changes who gets called next; "how popular is
 * this brochure" changes nothing.
 */
export function LeadFiles({ lead }: { lead: Lead }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [chosen, setChosen] = useState('')
  const [copied, setCopied] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['lead-files', lead.id],
    queryFn: () => fetchFilesForLead(lead.id),
  })

  const { data: shares } = useQuery({
    queryKey: ['lead-shares', lead.id],
    queryFn: () => fetchLeadShares(lead.id),
  })

  const share = useMutation({
    mutationFn: (fileId: string) => shareFileWithLead(lead.id, fileId, 'manual'),
    onSuccess: async (result) => {
      /*
       * The link is put on the clipboard rather than only shown. The next thing
       * anybody does with it is paste it into WhatsApp, and a link you have to
       * select out of a card by hand is one people stop using.
       */
      try {
        await navigator.clipboard.writeText(result.share.url)
        setCopied(result.share.id)
      } catch {
        /* denied, or an insecure origin — the card still shows the link */
      }

      toast(t('files.shared'), 'success')
      void queryClient.invalidateQueries({ queryKey: ['lead-shares', lead.id] })
      /*
       * `['lead', id]` is the whole screen's query — the activities come down
       * with the lead rather than separately. Invalidating a key nobody
       * registered refetches nothing, and the hand-off would sit on the
       * timeline unseen until the next reload.
       */
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  /*
   * Sent, not just linked: the same path an automation rule takes, so a rep's
   * button and a rule choose the same file, say the same why, and leave the
   * same trace on the timeline.
   */
  const deliver = useMutation({
    mutationFn: ({ channel, fileId }: { channel: 'whatsapp' | 'email'; fileId: string }) =>
      sendFileToLead(lead.id, channel, fileId),
    onSuccess: (_, { channel }) => {
      toast(t(channel === 'email' ? 'files.sentByEmail' : 'files.sentByWhatsapp'), 'success')
      void queryClient.invalidateQueries({ queryKey: ['lead-shares', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead-messages', lead.id] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const revoke = useMutation({
    mutationFn: (shareId: string) => revokeLeadShare(lead.id, shareId),
    onSuccess: () => {
      toast(t('files.linkRevoked'), 'success')
      void queryClient.invalidateQueries({ queryKey: ['lead-shares', lead.id] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-40" />

  const available = data?.available ?? []
  const suggested = data?.suggested ?? null
  const selected = chosen || suggested?.id || available[0]?.id || ''

  return (
    <Card>
      <CardHeader title={t('files.title')} description={t('files.hint')} />
      <CardBody>
        {available.length === 0 ? (
          /*
           * Says which of the two reasons it is. A lead with no project and a
           * project with no files are different problems with different people
           * to fix them, and one sentence covering both sends everybody to the
           * wrong place.
           */
          <p className="t-caption" data-testid="lead-files-empty">
            {lead.project ? t('files.noneForProject') : t('files.noProject')}
          </p>
        ) : (
          <div className="space-y-3" data-testid="lead-files">
            {suggested && (
              <p className="t-caption">
                {t('files.suggested')}{' '}
                <span className="font-medium text-[var(--color-ink)]">{suggested.name}</span>
                {(data?.reasons?.length ?? 0) > 0 ? (
                  <span data-testid="file-reasons">
                    {' — '}
                    {data?.reasons
                      .map((reason) => t(`files.reason.${reason}`, { defaultValue: reason }))
                      .join(' · ')}
                  </span>
                ) : (
                  data?.reason && (
                    <>
                      {' — '}
                      {t(`files.reason.${data.reason}`, { defaultValue: data.reason })}
                    </>
                  )
                )}
              </p>
            )}

            <div className="flex flex-wrap items-end gap-2">
              <Select
                className="min-w-48 flex-1"
                label={t('files.file')}
                value={selected}
                onChange={(e) => setChosen(e.target.value)}
                data-testid="lead-file-select"
              >
                {available.map((file) => (
                  <option key={file.id} value={file.id}>
                    {file.name}
                  </option>
                ))}
              </Select>

              <Button
                icon={<Send className="size-4" />}
                loading={share.isPending}
                disabled={!selected}
                onClick={() => share.mutate(selected)}
                data-testid="share-file"
              >
                {t('files.share')}
              </Button>

              <Button
                variant="secondary"
                icon={<MessageCircle className="size-4" />}
                loading={deliver.isPending && deliver.variables?.channel === 'whatsapp'}
                disabled={!selected || Boolean(lead.whatsapp_opt_out_at)}
                onClick={() => deliver.mutate({ channel: 'whatsapp', fileId: selected })}
                data-testid="send-file-whatsapp"
              >
                {t('files.sendWhatsapp')}
              </Button>
              <Button
                variant="secondary"
                icon={<Mail className="size-4" />}
                loading={deliver.isPending && deliver.variables?.channel === 'email'}
                disabled={!selected || !lead.email || Boolean(lead.email_opt_out_at)}
                onClick={() => deliver.mutate({ channel: 'email', fileId: selected })}
                data-testid="send-file-email"
              >
                {t('files.sendEmail')}
              </Button>
            </div>
          </div>
        )}

        {(shares?.shares.length ?? 0) > 0 && (
          <ul className="mt-4 space-y-2 border-t border-[var(--color-line)] pt-3" data-testid="lead-shares">
            {shares?.shares.map((entry) => (
              <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex min-w-0 items-center gap-2">
                  <FileText className="size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
                  <span className="t-small truncate">{entry.file?.name ?? '—'}</span>
                </span>

                {/*
                  Opened or not, in words as well as in colour. "Sent" and
                  "opened twice" are the two states that decide whether this
                  lead is worth a call this afternoon.
                */}
                <span className="flex items-center gap-2">
                  {entry.opens > 0 ? (
                    <Badge tone="success" testId={`share-${entry.id}-opens`}>
                      {t('files.opened', { count: entry.opens })}
                      {entry.last_opened_at && ` · ${formatRelative(entry.last_opened_at, i18n.language)}`}
                    </Badge>
                  ) : (
                    <Badge tone="neutral" testId={`share-${entry.id}-opens`}>{t('files.notOpened')}</Badge>
                  )}
                  {entry.openable === false && <Badge tone="neutral" testId={`share-${entry.id}-closed`}>{t('files.linkClosed')}</Badge>}
                  {entry.openable !== false && (
                    <Button
                      size="sm"
                      variant="ghost"
                      loading={revoke.isPending && revoke.variables === entry.id}
                      onClick={() => revoke.mutate(entry.id)}
                      data-testid={`share-${entry.id}-revoke`}
                    >
                      {t('files.revokeLink')}
                    </Button>
                  )}
                  {copied === entry.id && (
                    <span className="t-caption inline-flex items-center gap-1 text-[var(--color-success)]">
                      <Check className="size-3.5" aria-hidden />
                      {t('files.copied')}
                    </span>
                  )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
