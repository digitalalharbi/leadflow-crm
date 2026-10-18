import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardBody, Tabs } from '@/components/ui'
import { Conversation } from '@/components/whatsapp/Conversation'
import { EmailPanel } from '@/components/email/EmailPanel'
import type { Lead } from '@/types/api'

/**
 * One place for everything said to a lead.
 *
 * WhatsApp opens first because it is where the conversation is; email is one
 * press away rather than a second card further down a page that already has to
 * be scrolled. The choice is remembered for the visit, not across leads — a rep
 * working a list should land on the same channel they were just using.
 */
let lastChannel: 'whatsapp' | 'email' = 'whatsapp'

/** The channel last used in this visit, so a page that controls the tabs starts on it too. */
export const rememberedChannel = (): 'whatsapp' | 'email' => lastChannel

export function LeadMessaging({
  lead,
  channel: controlled,
  onChannel,
}: {
  lead: Lead
  /** Set by a parent that also has buttons which open a channel (the lead's action dock). */
  channel?: 'whatsapp' | 'email'
  onChannel?: (channel: 'whatsapp' | 'email') => void
}) {
  const { t } = useTranslation()
  const [own, setOwn] = useState<'whatsapp' | 'email'>(lastChannel)
  const channel = controlled ?? own

  const choose = (next: 'whatsapp' | 'email') => {
    lastChannel = next
    setOwn(next)
    onChannel?.(next)
  }

  return (
    <div className="space-y-3" data-testid="lead-messaging">
      <Tabs
        testId="messaging-tabs"
        value={channel}
        onChange={choose}
        items={[
          { key: 'whatsapp', label: t('whatsapp.channel') },
          { key: 'email', label: t('email.channel') },
        ]}
      />

      {channel === 'whatsapp' ? (
        <Conversation lead={lead} />
      ) : (
        <Card data-testid="email-card">
          <CardBody>
            <EmailPanel lead={lead} />
          </CardBody>
        </Card>
      )}
    </div>
  )
}
