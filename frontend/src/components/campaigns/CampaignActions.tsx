import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CirclePause, CirclePlay, Wallet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Modal, Textarea } from '@/components/ui'
import { propose } from '@/features/proposals'
import type { CampaignRow } from '@/features/campaigns'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

/**
 * Pause, resume, budget — each one files a proposal rather than acting.
 *
 * A person pressing "pause" still goes through the gateway: the limits are the
 * same whoever asks, and the change is logged the same way. The reason is
 * required because the log is only useful if it says why.
 */
export function CampaignActions({ campaign }: { campaign: CampaignRow }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [action, setAction] = useState<'pause' | 'resume' | 'update_budget' | null>(null)
  const [reason, setReason] = useState('')
  const [budget, setBudget] = useState(campaign.daily_budget ? String(campaign.daily_budget) : '')

  const send = useMutation({
    mutationFn: () =>
      propose({
        action: action!,
        campaign_id: campaign.id,
        reason,
        payload: action === 'update_budget' ? { daily_budget_minor: Math.round(Number(budget) * 100) } : {},
      }),
    onSuccess: ({ proposal }) => {
      void queryClient.invalidateQueries({ queryKey: ['proposals'] })
      setAction(null)
      setReason('')
      toast(proposal.status === 'blocked' ? t('proposals.filedBlocked') : t('proposals.filed'), proposal.status === 'blocked' ? 'error' : 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <>
      {campaign.status === 'active' ? (
        <Button variant="outline" icon={<CirclePause className="size-4" />} onClick={() => setAction('pause')} data-testid="campaign-pause">
          {t('proposals.action.pause')}
        </Button>
      ) : campaign.status === 'paused' ? (
        <Button variant="outline" icon={<CirclePlay className="size-4" />} onClick={() => setAction('resume')} data-testid="campaign-resume">
          {t('proposals.action.resume')}
        </Button>
      ) : null}
      <Button variant="outline" icon={<Wallet className="size-4" />} onClick={() => setAction('update_budget')} data-testid="campaign-budget">
        {t('proposals.action.update_budget')}
      </Button>

      <Modal
        open={action !== null}
        onClose={() => setAction(null)}
        title={action ? t(`proposals.action.${action}`) : ''}
        description={t('proposals.needsApproval')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setAction(null)}>{t('common.cancel')}</Button>
            <Button disabled={reason.trim() === '' || (action === 'update_budget' && !(Number(budget) > 0))} loading={send.isPending} onClick={() => send.mutate()} data-testid="proposal-submit">
              {t('proposals.submit')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {action === 'update_budget' && (
            <Input label={t('proposals.newBudget')} type="number" min="1" dir="ltr" value={budget} onChange={(e) => setBudget(e.target.value)} data-testid="proposal-new-budget" />
          )}
          <Textarea label={t('proposals.reason')} value={reason} onChange={(e) => setReason(e.target.value)} data-testid="proposal-reason" />
        </div>
      </Modal>
    </>
  )
}
