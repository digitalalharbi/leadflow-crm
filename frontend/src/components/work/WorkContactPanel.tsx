import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { fetchLead } from '@/features/leads'
import { LeadMessaging } from '@/components/email/LeadMessaging'
import { LeadFiles } from '@/components/leads/LeadFiles'
import { Skeleton } from '@/components/ui'

/**
 * Everything a rep says to the lead on this screen, without leaving it.
 *
 * «اعمل الآن» exists so nobody hops between pages: a rep who has to open the
 * lead to send an email has left the queue, and the queue is what they came
 * for. The same two components the lead screen uses, in a side panel — so the
 * message a rep writes here is the one that lands on the lead's timeline, and
 * the file suggested here is the one the lead screen would suggest.
 *
 * The queue's own lead is a list row; sending needs the whole record (the
 * email address, both opt-outs), so it is read when the panel opens and not
 * on every card the queue serves.
 */
export function WorkContactPanel({ leadId }: { leadId: string }) {
  const { t } = useTranslation()
  const { data, isLoading, isError } = useQuery({
    queryKey: ['lead', leadId],
    queryFn: () => fetchLead(leadId),
  })

  if (isLoading) return <Skeleton className="h-64" />
  if (isError || !data) return <p className="notice notice-danger">{t('common.loadFailed')}</p>

  return (
    <div className="space-y-4" data-testid="work-contact-panel">
      <LeadMessaging lead={data.lead} />
      <LeadFiles lead={data.lead} />
    </div>
  )
}
