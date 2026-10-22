import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Bot, Check, CirclePause, CirclePlay, Copy, Megaphone, Pencil, ShieldAlert, Sparkles, Target, Wallet, X,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardBody, Input, Modal } from '@/components/ui'
import { StatusPill, type StatusKey } from '@/components/visual'
import { approveProposal, editProposal, rejectProposal, type Proposal } from '@/features/proposals'
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
 * One change somebody wants made, as an action card: what, to which campaign,
 * why, what it should do — and approve, reject or edit. The same card in the
 * approval inbox and inside an agent's reply, so deciding works the same way
 * wherever the proposal turns up.
 */
export function ProposalCard({ proposal: initial, currency }: { proposal: Proposal; currency: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [p, setP] = useState(initial)
  const [editing, setEditing] = useState(false)
  const [budget, setBudget] = useState(String(Number(initial.payload.daily_budget_minor ?? 0) / 100))

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['proposals'] })
    void queryClient.invalidateQueries({ queryKey: ['campaigns'] })
  }

  const decide = useMutation({
    mutationFn: (how: 'approve' | 'reject') => (how === 'approve' ? approveProposal(p.id) : rejectProposal(p.id)),
    onSuccess: ({ proposal }) => {
      setP(proposal)
      refresh()
      toast(t(`proposals.result.${proposal.status}`, { defaultValue: t('proposals.result.default') }), proposal.status === 'failed' || proposal.status === 'blocked' ? 'error' : 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const edit = useMutation({
    mutationFn: () => editProposal(p.id, { ...p.payload, daily_budget_minor: Math.round(Number(budget) * 100) }),
    onSuccess: ({ proposal }) => {
      setP(proposal)
      setEditing(false)
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const Icon = ACTION_ICON[p.action] ?? Sparkles
  const open = p.status === 'pending' || p.status === 'blocked'
  const money = (minor: unknown) => formatMoneyCompact(typeof minor === 'number' ? minor / 100 : null, currency, i18n.language)

  return (
    <Card data-testid="proposal-card" data-status={p.status} data-approval={p.approval} className={open ? undefined : 'opacity-70'}>
      <CardBody className="space-y-3">
        <div className="flex items-start gap-3">
          <span className="grid size-10 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden>
            <Icon className="size-5" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="flex flex-wrap items-center gap-1.5 text-[1rem] font-semibold">
              {t(`proposals.action.${p.action}`, { defaultValue: p.action })}
              {p.action === 'update_budget' && (
                <span className="tabular-nums text-[var(--color-ink-muted)]" dir="ltr">
                  {money(p.campaign?.daily_budget_minor)} → {money(p.payload.daily_budget_minor)}
                </span>
              )}
            </p>
            <p className="truncate text-[0.875rem] text-[var(--color-ink-muted)]" dir="auto">
              {[p.campaign?.name, p.ad_set, p.ad].filter(Boolean).join(' › ')}
            </p>
          </div>
          <span className="flex flex-col items-end gap-1">
            <StatusPill status={STATUS[p.status]} />
            {p.approval === 'self' && <Badge tone="info">{t('proposals.selfBadge')}</Badge>}
          </span>
        </div>

        <p className="text-[0.9375rem] leading-relaxed" dir="auto">{p.reason}</p>
        {p.expected_impact && <p className="text-[0.875rem] text-[var(--color-ink-muted)]" dir="auto">↗ {p.expected_impact}</p>}

        {p.policy.blocks.length > 0 && (
          <ul className="space-y-1" data-testid="proposal-blocks">
            {p.policy.blocks.map((b) => (
              <li key={b} className="flex items-center gap-1.5 text-[0.875rem] text-[var(--color-danger)]">
                <ShieldAlert className="size-3.5 shrink-0" aria-hidden />
                {t(`proposals.block.${b}`, {
                  ...p.policy.limits,
                  max: money(p.policy.limits.max_minor),
                  min: money(p.policy.limits.min_minor),
                  ceiling: money(p.policy.limits.ceiling_minor),
                  defaultValue: b,
                })}
              </li>
            ))}
          </ul>
        )}
        {p.error && <p className="text-[0.875rem] text-[var(--color-danger)]">{p.error}</p>}

        <div className="flex items-center justify-between gap-2">
          <span className="flex items-center gap-1.5 text-[0.8125rem] text-[var(--color-ink-subtle)]">
            {p.proposed_by === 'agent' && <Bot className="size-3.5" aria-hidden />}
            {p.agent ? t(`agents.name.${p.agent}`, { defaultValue: p.agent }) : p.proposer}
            {p.created_at && ` · ${formatRelative(p.created_at, i18n.language)}`}
          </span>
          {open && (
            <div className="flex items-center gap-1.5">
              {p.action === 'update_budget' && (
                <Button size="sm" variant="ghost" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)} data-testid="proposal-edit">
                  {t('proposals.edit')}
                </Button>
              )}
              <Button size="sm" variant="ghost" icon={<X className="size-4" />} loading={decide.isPending && decide.variables === 'reject'} onClick={() => decide.mutate('reject')} data-testid="proposal-reject">
                {t('proposals.reject')}
              </Button>
              {p.status === 'pending' && (
                <Button size="sm" icon={<Check className="size-4" />} loading={decide.isPending && decide.variables === 'approve'} onClick={() => decide.mutate('approve')} data-testid="proposal-approve">
                  {t('proposals.approve')}
                </Button>
              )}
            </div>
          )}
        </div>
      </CardBody>

      <Modal
        open={editing}
        onClose={() => setEditing(false)}
        title={t('proposals.editTitle')}
        size="sm"
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(false)}>{t('common.cancel')}</Button>
            <Button loading={edit.isPending} onClick={() => edit.mutate()} data-testid="proposal-save">{t('common.save')}</Button>
          </>
        }
      >
        <Input label={t('proposals.newBudget')} type="number" min="1" step="1" dir="ltr" value={budget} onChange={(e) => setBudget(e.target.value)} data-testid="proposal-budget" />
      </Modal>
    </Card>
  )
}
