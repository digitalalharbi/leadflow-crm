import { useQueryClient } from '@tanstack/react-query'
import { Card, CardBody } from '@/components/ui'
import { ConversationCard } from '@/components/lead/ConversationCard'
import { FollowUpPlanCard } from '@/components/lead/FollowUpPlanCard'
import { HandoffBriefCard } from '@/components/lead/HandoffBriefCard'
import { InterestCard } from '@/components/lead/InterestCard'
import { IntelligenceStrip } from '@/components/lead/IntelligenceStrip'
import { JourneyTimeline } from '@/components/lead/JourneyTimeline'
import { AppointmentsPanel, CallsPanel, SourceChain } from '@/components/lead/SidePanels'
import type { LeadDetailResponse } from '@/features/leads'

/**
 * Everything about a lead that is not the next thing to do: loaded only when the rep opens the details
 * sheet, as a chunk of its own — the timeline, the charts-adjacent panels and the longer cards stay off
 * the first paint of a screen whose job is to get a rep onto the phone.
 */
export default function LeadSecondary({ data }: { data: LeadDetailResponse }) {
  const queryClient = useQueryClient()
  const { lead } = data

  return (
    <div className="space-y-3" data-testid="lead-secondary">
      {data.handoff && <HandoffBriefCard brief={data.handoff} compact />}
      <IntelligenceStrip intelligence={data.intelligence} />
      <ConversationCard leadId={lead.id} conversation={data.conversation} />
      <InterestCard lead={lead} />
      {data.follow_up && <FollowUpPlanCard leadId={lead.id} plan={data.follow_up} onChanged={() => void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })} />}
      <CallsPanel calls={data.calls} />
      <AppointmentsPanel appointments={data.appointments} />
      <SourceChain lead={lead} />
      <Card>
        <CardBody>
          <JourneyTimeline leadId={lead.id} />
        </CardBody>
      </Card>
    </div>
  )
}
