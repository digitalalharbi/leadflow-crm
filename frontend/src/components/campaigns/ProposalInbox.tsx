import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Bot, Check, CirclePause, CirclePlay, Copy, Megaphone, Pencil, ShieldAlert, Sparkles, Target, Wallet, X,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, CardBody, Input, Modal } from '@/components/ui'
import { StatusPill, type StatusKey } from '@/components/visual'
import { approveProposal, editProposal, rejectProposal, useProposals, type Proposal } from '@/features/proposals'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'
import { formatMoneyCompact, formatRelative } from '@/lib/format'

const ACTION_ICON: Record<string, LucideIcon> = {
  pause: CirclePause,
  resume: CirclePlay,
  update_budget: Wallet,
  duplicate_ad_set: Copy,
  create_campaign: Megaphone,
  update_creative: Sparkles,
  update_targeting: Target,
}

const STATUS: Record<Proposal['status'], StatusKey> = {
  pending: 'pending',
  approved: 'active',
  executing: 'active',
  executed: 'completed',
  rejected: 'paused',
  failed: 'risk',
  blocked: 'attention',
}

/**
 * The approval inbox.
 *
 * Each card is one change somebody wants made: what, to which campaign, why,
 * what it should do — and three buttons. Blocked proposals say which limit they
 * hit, in the client's own terms ("more than 20%"), so the fix is obvious; a
 * budget proposal can be edited into range without starting again.
 */
export function ProposalInbox({ campaignId, currency }: { campaignId?: string; currency: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [editing, setEditing] = useState<Proposal | null>(null)
  const [budget, setBudget] = useState('')

  const { data } = useProposals({ campaign_id: campaignId })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['proposals'] })
    void queryClient.invalidateQueries({ queryKey: ['campaigns'] })
  }

  const decide = useMutation({
    mutationFn: ({ id, how }: { id: string; how: 'approve' | 'reject' }) => (how === 'approve' ? approveProposal(id) : rejectProposal(id)),
    onSuccess: ({ proposal }) => {
      refresh()
      toast(t(`proposals.result.${proposal.status}`, { defaultValue: t('proposals.result.default') }), proposal.status === 'failed' || proposal.status === 'blocked' ? 'error' : 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const edit = useMutation({
    mutationFn: () => editProposal(editing!.id, { ...editing!.payload, daily_budget_minor: Math.round(Number(budget) * 100) }),
    onSuccess: () => {
      setEditing(null)
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  // Open ones first; the most recent decisions after them, so what just
  // happened stays in view instead of vanishing on click.
  const rank = (p: Proposal) => (['pending', 'blocked'].includes(p.status) ? 0 : p.status === 'failed' ? 1 : 2)
  const proposals = [...(data?.proposals ?? [])].sort((a, b) => rank(a) - rank(b))
  if (proposals.length === 0) return null

  const money = (minor: unknown) => formatMoneyCompact(typeof minor === 'number' ? minor / 100 : null, currency, i18n.language)

  return (
    <section data-testid="proposal-inbox">
      <h2 className="t-card-title mb-2 px-1">{t('proposals.title')}</h2>
      <div className="grid gap-3 lg:grid-cols-2">
        {proposals.slice(0, 8).map((p) => {
          const Icon = ACTION_ICON[p.action] ?? Sparkles
          const open = p.status === 'pending' || p.status === 'blocked'

          return (
            <Card key={p.id} data-testid="proposal-card" data-status={p.status} className={open ? undefined : 'opacity-70'}>
              <CardBody className="space-y-3">
                <div className="flex items-start gap-3">
                  <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden>
                    <Icon className="size-5" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-1.5 text-[0.875rem] font-semibold">
                      {t(`proposals.action.${p.action}`, { defaultValue: p.action })}
                      {p.action === 'update_budget' && (
                        <span className="tabular-nums text-[var(--color-ink-muted)]" dir="ltr">
                          {money(p.campaign?.daily_budget_minor)} → {money(p.payload.daily_budget_minor)}
                        </span>
                      )}
                    </p>
                    <p className="truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="auto">
                      {[p.campaign?.name, p.ad_set, p.ad].filter(Boolean).join(' › ')}
                    </p>
                  </div>
                  <StatusPill status={STATUS[p.status]} />
                </div>

                <p className="text-[0.8125rem] leading-relaxed">{p.reason}</p>
                {p.expected_impact && <p className="text-[0.75rem] text-[var(--color-ink-muted)]">↗ {p.expected_impact}</p>}

                {p.policy.blocks.length > 0 && (
                  <ul className="space-y-1" data-testid="proposal-blocks">
                    {p.policy.blocks.map((b) => (
                      <li key={b} className="flex items-center gap-1.5 text-[0.75rem] text-[var(--color-danger)]">
                        <ShieldAlert className="size-3.5 shrink-0" aria-hidden />
                        {t(`proposals.block.${b}`, { ...p.policy.limits, max: money(p.policy.limits.max_minor), min: money(p.policy.limits.min_minor), ceiling: money(p.policy.limits.ceiling_minor), defaultValue: b })}
                      </li>
                    ))}
                  </ul>
                )}
                {p.error && <p className="text-[0.75rem] text-[var(--color-danger)]">{p.error}</p>}

                <div className="flex items-center justify-between gap-2">
                  <span className="flex items-center gap-1.5 text-[0.6875rem] text-[var(--color-ink-subtle)]">
                    {p.proposed_by === 'agent' && <Bot className="size-3.5" aria-hidden />}
                    {p.agent ? t(`agents.name.${p.agent}`, { defaultValue: p.agent }) : p.proposer}
                    {p.created_at && ` · ${formatRelative(p.created_at, i18n.language)}`}
                  </span>
                  {open && (
                    <div className="flex items-center gap-1.5">
                      {p.action === 'update_budget' && (
                        <Button size="sm" variant="ghost" icon={<Pencil className="size-4" />} onClick={() => { setEditing(p); setBudget(String(Number(p.payload.daily_budget_minor ?? 0) / 100)) }} data-testid="proposal-edit">
                          {t('proposals.edit')}
                        </Button>
                      )}
                      <Button size="sm" variant="ghost" icon={<X className="size-4" />} loading={decide.isPending && decide.variables?.id === p.id && decide.variables.how === 'reject'} onClick={() => decide.mutate({ id: p.id, how: 'reject' })} data-testid="proposal-reject">
                        {t('proposals.reject')}
                      </Button>
                      {p.status === 'pending' && (
                        <Button size="sm" icon={<Check className="size-4" />} loading={decide.isPending && decide.variables?.id === p.id && decide.variables.how === 'approve'} onClick={() => decide.mutate({ id: p.id, how: 'approve' })} data-testid="proposal-approve">
                          {t('proposals.approve')}
                        </Button>
                      )}
                    </div>
                  )}
                </div>
              </CardBody>
            </Card>
          )
        })}
      </div>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={t('proposals.editTitle')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>{t('common.cancel')}</Button>
            <Button loading={edit.isPending} onClick={() => edit.mutate()} data-testid="proposal-save">{t('common.save')}</Button>
          </>
        }
      >
        <Input label={t('proposals.newBudget')} type="number" min="1" step="1" dir="ltr" value={budget} onChange={(e) => setBudget(e.target.value)} data-testid="proposal-budget" />
      </Modal>
    </section>
  )
}
