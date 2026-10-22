import { useTranslation } from 'react-i18next'
import { useProposals, type Proposal } from '@/features/proposals'
import { ProposalCard } from './ProposalCard'

/**
 * The approval inbox: open proposals first, then the most recent decisions, so
 * what just happened stays in view instead of vanishing on click.
 */
export function ProposalInbox({ campaignId, currency }: { campaignId?: string; currency: string }) {
  const { t } = useTranslation()
  const { data } = useProposals({ campaign_id: campaignId })

  const rank = (p: Proposal) => (['pending', 'blocked'].includes(p.status) ? 0 : p.status === 'failed' ? 1 : 2)
  const proposals = [...(data?.proposals ?? [])].sort((a, b) => rank(a) - rank(b))

  if (proposals.length === 0) return null

  return (
    <section data-testid="proposal-inbox">
      <h2 className="t-card-title mb-2 px-1">{t('proposals.title')}</h2>
      <div className="grid gap-3 lg:grid-cols-2">
        {proposals.slice(0, 8).map((p) => <ProposalCard key={`${p.id}-${p.status}`} proposal={p} currency={currency} />)}
      </div>
    </section>
  )
}
