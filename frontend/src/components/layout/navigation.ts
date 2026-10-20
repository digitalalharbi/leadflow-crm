import {
  BarChart3, Building2, Columns3, LayoutDashboard, Network, Plug, Rocket, Settings,
  ShieldCheck, Target, Users2, CalendarClock, Contact2, Workflow, Megaphone, Bot, Radar,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'

export interface NavItem {
  to: string
  labelKey: string
  Icon: LucideIcon
  badge?: number
}

export interface Navigation {
  /** The few destinations in the floating header's pill track. */
  primary: NavItem[]
  /** Every destination, grouped for the icon rail. */
  groups: NavItem[][]
  /** Settings sits apart, at the foot of the rail. */
  settings: NavItem | null
  /** Flat, for the phone drawer. */
  all: NavItem[]
}

const PRIMARY = ['/', '/work', '/leads', '/pipeline', '/campaigns', '/projects', '/reports']

/**
 * Where a person can go, decided once.
 *
 * The header, the icon rail and the phone drawer all draw from this, so a
 * destination appears in all three or in none: they can never disagree about
 * who may open a screen. Visibility is exactly the test the API applies —
 * not a second, looser one in the navigation.
 */
export function useNavigation(): Navigation {
  const user = useAuth((s) => s.user)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)

  // Overdue count sits next to "Follow-ups" — it is the number that should
  // pull a rep back into the app.
  const { data: counts } = useQuery({
    queryKey: ['follow-up-counts'],
    queryFn: () => apiGet<{ overdue: number; today: number; upcoming: number }>('/follow-ups/counts'),
    enabled: !isSuperAdmin,
    refetchInterval: 120_000,
  })

  if (isSuperAdmin) {
    const items: NavItem[] = [
      { to: '/platform', labelKey: 'nav.superAdmin', Icon: ShieldCheck },
      { to: '/readiness', labelKey: 'nav.readiness', Icon: Rocket },
    ]

    return { primary: items, groups: [items], settings: null, all: items }
  }

  const seesAll = can.seeAllLeads(user)
  const reports = can.viewReports(user)
  const integrations = can.manageIntegrations(user)

  const groups: NavItem[][] = [
    [
      { to: '/', labelKey: 'nav.dashboard', Icon: LayoutDashboard },
      // Directly under the dashboard: a rep who opens the list first starts by
      // choosing; this screen is the one that tells them.
      { to: '/work', labelKey: 'nav.work', Icon: Target },
      { to: '/leads', labelKey: 'nav.leads', Icon: Contact2 },
      { to: '/pipeline', labelKey: 'nav.pipeline', Icon: Columns3 },
      // Money and ad accounts: the people who may read reports.
      ...(reports ? [{ to: '/campaigns', labelKey: 'nav.campaigns', Icon: Megaphone }] : []),
      ...(reports ? [{ to: '/tracking', labelKey: 'nav.tracking', Icon: Radar }] : []),
      ...(reports ? [{ to: '/agents', labelKey: 'nav.agents', Icon: Bot }] : []),
      {
        to: '/follow-ups',
        labelKey: 'nav.followUps',
        Icon: CalendarClock,
        badge: (counts?.overdue ?? 0) + (counts?.today ?? 0),
      },
    ],
    [
      // The holding's structure; managers and admins only.
      ...(seesAll ? [{ to: '/companies', labelKey: 'nav.companies', Icon: Network }] : []),
      { to: '/projects', labelKey: 'nav.projects', Icon: Building2 },
      // Rules act on everybody's leads, so the same authority as the API.
      ...(seesAll ? [{ to: '/automation', labelKey: 'nav.automation', Icon: Workflow }] : []),
      ...(reports ? [{ to: '/reports', labelKey: 'nav.reports', Icon: BarChart3 }] : []),
    ],
    [
      ...(integrations ? [{ to: '/integrations', labelKey: 'nav.integrations', Icon: Plug }] : []),
      ...(seesAll ? [{ to: '/users', labelKey: 'nav.users', Icon: Users2 }] : []),
    ],
  ].filter((group) => group.length > 0)

  const settings: NavItem = { to: '/settings', labelKey: 'nav.settings', Icon: Settings }
  const all = [...groups.flat(), settings]

  return {
    primary: PRIMARY.map((to) => all.find((item) => item.to === to)).filter((item): item is NavItem => Boolean(item)),
    groups,
    settings,
    all,
  }
}
