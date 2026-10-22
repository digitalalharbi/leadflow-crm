import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { GitMerge } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Modal, StatusBadge } from '@/components/ui'
import { fetchDuplicates, mergeLead, type DuplicateCandidate } from '@/features/leads'
import { useApiError } from '@/hooks/useApiError'
import { formatDate } from '@/lib/format'
import { useUi } from '@/stores/ui'
import type { LeadStatus } from '@/types/api'

/**
 * "This may be the same person" — for managers only, because folding two
 * records together cannot be cleanly undone.
 *
 * Never drawn when there is nothing to suggest. The reasons are evidence (the
 * same phone, email or provider submission) and never a name, so the person
 * deciding sees what the system saw.
 */
export function DuplicatesBanner({ leadId, onMerged }: { leadId: string; onMerged: () => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [open, setOpen] = useState(false)
  const [confirming, setConfirming] = useState<DuplicateCandidate | null>(null)

  const { data } = useQuery({ queryKey: ['lead-duplicates', leadId], queryFn: () => fetchDuplicates(leadId) })

  const merge = useMutation({
    mutationFn: (duplicate: DuplicateCandidate) => mergeLead(leadId, duplicate.id),
    onSuccess: () => {
      toast(t('merge.done'), 'success')
      setConfirming(null)
      setOpen(false)
      void queryClient.invalidateQueries({ queryKey: ['lead-duplicates', leadId] })
      onMerged()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const duplicates = data?.duplicates ?? []

  if (duplicates.length === 0) return null

  return (
    <>
      <Card className="mb-4 border border-[color-mix(in_srgb,var(--color-warning)_40%,transparent)]" data-testid="duplicates-banner">
        <CardBody className="flex flex-wrap items-center justify-between gap-3 py-3">
          <span className="flex items-center gap-2 text-[1rem] font-semibold">
            <GitMerge className="size-5 text-[var(--color-warning)]" aria-hidden />
            {t('merge.found', { count: duplicates.length })}
          </span>
          <Button size="sm" variant="secondary" data-testid="duplicates-review" onClick={() => setOpen(true)}>
            {t('merge.review')}
          </Button>
        </CardBody>
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title={t('merge.title')} description={t('merge.hint')} size="lg">
        <ul className="space-y-2" data-testid="duplicates-list">
          {duplicates.map((candidate) => (
            <li key={candidate.id} data-testid="duplicate-candidate" className="rounded-[var(--radius-card)] bg-[var(--color-surface-muted)] p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-[1rem] font-semibold">{candidate.name}</p>
                  <p className="t-caption" dir="ltr">{[candidate.phone, candidate.email].filter(Boolean).join(' · ')}</p>
                  <p className="t-caption">{[candidate.platform, candidate.campaign, formatDate(candidate.created_at, i18n.language)].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                  {candidate.status && <StatusBadge status={candidate.status as LeadStatus} />}
                  {candidate.reasons.map((reason) => (
                    <span key={reason} className="rounded-full bg-[var(--brand-soft)] px-2 py-0.5 text-[0.8125rem] font-semibold text-[var(--brand)]" data-testid="duplicate-reason">
                      {t(`merge.reason.${reason}`)}
                    </span>
                  ))}
                  <Button size="sm" data-testid="duplicate-merge" onClick={() => setConfirming(candidate)}>
                    {t('merge.action')}
                  </Button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      </Modal>

      <Modal
        open={confirming !== null}
        onClose={() => setConfirming(null)}
        title={t('merge.confirmTitle')}
        description={t('merge.confirmBody', { name: confirming?.name ?? '' })}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirming(null)}>{t('common.cancel')}</Button>
            <Button loading={merge.isPending} data-testid="duplicate-confirm" onClick={() => confirming && merge.mutate(confirming)}>
              {t('merge.confirm')}
            </Button>
          </>
        }
      >
        <p className="t-caption">{t('merge.keeps')}</p>
      </Modal>
    </>
  )
}
