import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { getEcho } from '@/lib/echo'
import { can, useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * Keeps an open screen honest.
 *
 * The work queue refetches every sixty seconds, which is the right floor for a
 * page left open all day and the wrong answer for the one thing this product
 * promises: a lead that arrives at 10:04 should be on somebody's screen at
 * 10:04. A minute is also exactly long enough to walk away from the desk.
 *
 * The server sends a nudge, never a lead — see LeadFeedUpdate. So this
 * invalidates the queries the change could have touched and lets them refetch
 * through the ordinary endpoints, where the policies run. Nothing is written
 * into the cache from the socket: a payload that bypassed the API would also
 * bypass every rule about who may see what.
 */

type FeedPayload = {
  event: string
  lead_id: string
  assignee_id: string | null
}

export function useLiveFeed(): void {
  const user = useAuth((s) => s.user)
  const tenantId = useAuth((s) => s.branding?.tenant_id)
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const { t } = useTranslation()

  useEffect(() => {
    const echo = getEcho()

    if (!echo || !user) {
      return
    }

    /*
     * Everyone listens to their own feed. Only the people who can already see
     * every lead listen to the workspace — a rep told that a lead exists which
     * their queue will never show them has been told something useless and
     * something they are not allowed to know.
     */
    const channels = [`user.${user.id}`]

    if (tenantId && can.seeAllLeads(user)) {
      channels.push(`tenant.${tenantId}.leads`)
    }

    /*
     * Development activity has its own channel and its own rule: anyone who can
     * open a project can watch it. A merged pull request is not somebody's
     * phone number, so the lead feed's narrower rule would only mean a rep
     * looking at a project screen that never updates.
     */
    const developmentChannel = tenantId ? `tenant.${tenantId}.development` : null

    /*
     * A lead assigned to a manager arrives on both of their channels. Without
     * this the screen refetches twice and says "a lead arrived" twice for one
     * lead, which is how people learn to distrust a notification.
     */
    const seen = new Set<string>()

    const onFeed = (payload: FeedPayload) => {
      const key = `${payload.event}:${payload.lead_id}`

      if (seen.has(key)) {
        return
      }

      seen.add(key)
      // Long enough to swallow the duplicate, short enough that a genuine
      // second event about the same lead still gets through.
      setTimeout(() => seen.delete(key), 3_000)

      // Everything a change of this kind could be showing on some screen.
      for (const queryKey of [
        ['work-next'],
        ['work-summary'],
        ['leads'],
        ['lead'],
        ['dashboard'],
        ['follow-ups'],
        ['follow-up-counts'],
      ]) {
        void queryClient.invalidateQueries({ queryKey })
      }

      /*
       * Only the two that mean somebody should do something now, and only when
       * it is theirs. A manager watching the workspace does not need a toast per
       * arrival across a team of twelve — the number on the screen updating is
       * what they are watching.
       */
      const mine = payload.assignee_id === user.id

      if (payload.event === 'lead.created' && mine) {
        toast(t('live.leadArrived'), 'info')
      }

      if (payload.event === 'lead.assigned' && mine) {
        toast(t('live.leadAssigned'), 'info')
      }

      if (payload.event === 'lead.replied' && mine) {
        toast(t('live.leadReplied'), 'info')
      }
    }

    const onDevelopment = (payload: { project_id: string }) => {
      void queryClient.invalidateQueries({ queryKey: ['development', payload.project_id] })
      void queryClient.invalidateQueries({ queryKey: ['github'] })
    }

    for (const name of channels) {
      // The leading dot means "this exact name", not one namespaced under the
      // backend's PHP namespace — which is what broadcastAs() produces.
      echo.private(name).listen('.lead.feed', onFeed)
    }

    if (developmentChannel) {
      echo.private(developmentChannel).listen('.repository.feed', onDevelopment)
    }

    return () => {
      for (const name of channels) {
        echo.leave(name)
      }

      if (developmentChannel) {
        echo.leave(developmentChannel)
      }
    }
  }, [user, tenantId, queryClient, toast, t])
}
