import {
  BarChart3, Building2, Columns3, LayoutDashboard, Network, Plug, Rocket, Settings,
  ShieldCheck, Target, Users2, CalendarClock, CalendarCheck, Contact2, Workflow, Megaphone, Bot, Radar,
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

  /*
   * Each destination needs one permission — the same one the API asks for on the data behind it.
   * A role that cannot open a screen is not offered it, and typing its address sends them home
   * (see RequirePermission); the API refuses the data either way.
   */
  const has = (permission: string) => can.has(user, permission)
  const item = (to: string, labelKey: string, Icon: LucideIcon, permission: string, extra: Partial<NavItem> = {}): NavItem[] =>
    has(permission) ? [{ to, labelKey, Icon, ...extra }] : []

  const groups: NavItem[][] = [
    [
      ...item('/', 'nav.dashboard', LayoutDashboard, 'dashboard.view'),
      // Directly under the dashboard: a rep who opens the list first starts by
      // choosing; this screen is the one that tells them.
      ...item('/work', 'nav.work', Target, 'leads.execute'),
      ...item('/leads', 'nav.leads', Contact2, 'leads.view'),
      ...item('/pipeline', 'nav.pipeline', Columns3, 'leads.view'),
      // The two steps between interested and sold.
      ...(has('visits.view') || has('reservations.view') ? [{ to: '/visits', labelKey: 'nav.visits', Icon: CalendarCheck }] : []),
      // Money and ad accounts: the people who may read them.
      ...item('/campaigns', 'nav.campaigns', Megaphone, 'campaigns.view'),
      ...item('/tracking', 'nav.tracking', Radar, 'tracking.view'),
      ...item('/agents', 'nav.agents', Bot, 'campaigns.view'),
      // Overdue count sits next to "Follow-ups" — it is the number that should
      // pull a rep back into the app.
      ...item('/follow-ups', 'nav.followUps', CalendarClock, 'leads.execute', { badge: (counts?.overdue ?? 0) + (counts?.today ?? 0) }),
    ],
    [
      // The holding's structure; managers and admins only.
      ...item('/companies', 'nav.companies', Network, 'companies.view'),
      ...item('/projects', 'nav.projects', Building2, 'projects.view'),
      // Rules act on everybody's leads, so the same authority as the API.
      ...item('/automation', 'nav.automation', Workflow, 'automation.view'),
      ...item('/reports', 'nav.reports', BarChart3, 'analytics.view'),
    ],
    [
      ...item('/integrations', 'nav.integrations', Plug, 'integrations.view'),
      ...item('/users', 'nav.users', Users2, 'users.view'),
    ],
  ].filter((group) => group.length > 0)

  const settings: NavItem | null = has('settings.view') ? { to: '/settings', labelKey: 'nav.settings', Icon: Settings } : null
  const all = settings ? [...groups.flat(), settings] : groups.flat()

  return {
    primary: PRIMARY.map((to) => all.find((item) => item.to === to)).filter((item): item is NavItem => Boolean(item)),
    groups,
    settings,
    all,
  }
}
