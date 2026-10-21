import { useEffect, lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { seesPage } from '@/lib/permissions'
import { can, useAuth } from '@/stores/auth'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { AppShell } from '@/components/layout/AppShell'
import { LoginPage } from '@/pages/Login'
import { DashboardPage } from '@/pages/Dashboard'
import { Toaster, Skeleton } from '@/components/ui'

// Everything past the dashboard is code-split: the first paint only pays for
// what a user actually lands on.
/*
 * Guarded at the import, not only at the route. `import.meta.env.DEV` is
 * replaced with `false` at build time, so the branch is dead code and the
 * chunk is never emitted — a conditional route alone still ships the file.
 */
const DesignSystemPage = import.meta.env.DEV
  ? lazy(() => import('@/pages/dev/DesignSystem').then((m) => ({ default: m.DesignSystemPage })))
  : () => null
const AccessPage = lazy(() => import('@/pages/Access').then((m) => ({ default: m.AccessPage })))
const WorkPage = lazy(() => import('@/pages/Work').then((m) => ({ default: m.WorkPage })))
const DataQualityPage = lazy(() => import('@/pages/DataQuality').then((m) => ({ default: m.DataQualityPage })))
const AutomationPage = lazy(() => import('@/pages/Automation').then((m) => ({ default: m.AutomationPage })))
const LeadsPage = lazy(() => import('@/pages/Leads').then((m) => ({ default: m.LeadsPage })))
const LeadDetailPage = lazy(() => import('@/pages/LeadDetail').then((m) => ({ default: m.LeadDetailPage })))
const PipelinePage = lazy(() => import('@/pages/Pipeline').then((m) => ({ default: m.PipelinePage })))
const FollowUpsPage = lazy(() => import('@/pages/FollowUps').then((m) => ({ default: m.FollowUpsPage })))
const CompaniesPage = lazy(() => import('@/pages/Companies').then((m) => ({ default: m.CompaniesPage })))
const ProjectsPage = lazy(() => import('@/pages/Projects').then((m) => ({ default: m.ProjectsPage })))
const ProjectDetailPage = lazy(() => import('@/pages/ProjectDetail').then((m) => ({ default: m.ProjectDetailPage })))
const AgentsPage = lazy(() => import('@/pages/Agents').then((m) => ({ default: m.AgentsPage })))
const CreativesPage = lazy(() => import('@/pages/Creatives').then((m) => ({ default: m.CreativesPage })))
const CampaignsPage = lazy(() => import('@/pages/Campaigns').then((m) => ({ default: m.CampaignsPage })))
const SharedReportPage = lazy(() => import('@/pages/SharedReport').then((m) => ({ default: m.SharedReportPage })))
const AuditPage = lazy(() => import('@/pages/Audit').then((m) => ({ default: m.AuditPage })))
const PartnersPage = lazy(() => import('@/pages/Partners').then((m) => ({ default: m.PartnersPage })))
const VisitsPage = lazy(() => import('@/pages/Visits').then((m) => ({ default: m.VisitsPage })))
const TrackingPage = lazy(() => import('@/pages/Tracking').then((m) => ({ default: m.TrackingPage })))
const CampaignDetailPage = lazy(() => import('@/pages/CampaignDetail').then((m) => ({ default: m.CampaignDetailPage })))
const ReportsPage = lazy(() => import('@/pages/Reports').then((m) => ({ default: m.ReportsPage })))
const IntegrationsPage = lazy(() => import('@/pages/Integrations').then((m) => ({ default: m.IntegrationsPage })))
const UsersPage = lazy(() => import('@/pages/Users').then((m) => ({ default: m.UsersPage })))
const SettingsPage = lazy(() => import('@/pages/Settings').then((m) => ({ default: m.SettingsPage })))
const ProfilePage = lazy(() => import('@/pages/Profile').then((m) => ({ default: m.ProfilePage })))
const ReadinessPage = lazy(() => import('@/pages/Readiness').then((m) => ({ default: m.ReadinessPage })))
const PlatformPage = lazy(() => import('@/pages/Platform').then((m) => ({ default: m.PlatformPage })))

function PageFallback() {
  return (
    <div className="space-y-4" aria-busy="true">
      <Skeleton className="h-8 w-48" />
      <Skeleton className="h-64 w-full" />
    </div>
  )
}

/**
 * The platform console belongs to the SaaS owner. A tenant user has no business
 * seeing even its shell, so the route sends them home rather than rendering a
 * page whose every request will 404.
 */
function RequireSuperAdmin({ children }: { children: React.ReactNode }) {
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const status = useAuth((s) => s.status)

  if (status === 'idle' || status === 'loading') return <PageFallback />

  return isSuperAdmin ? <>{children}</> : <Navigate to="/" replace />
}

/**
 * A screen a role has not been given is not a blank page or a wall of 403s: it is not there. The
 * navigation already leaves it out; this covers the address typed by hand, and sends the person
 * to the first thing they do have. (The API refuses the data either way.)
 */
function RequirePermission({ any, children }: { any: string[]; children: React.ReactNode }) {
  const user = useAuth((s) => s.user)

  const { pathname } = useLocation()

  if (can.any(user, any) && seesPage(user, pathname)) return <>{children}</>

  return <Navigate to={firstHome(user)} replace />
}

/** Where somebody lands who asked for a screen they do not have: the first one they do. */
function firstHome(user: ReturnType<typeof useAuth.getState>['user']): string {
  const home = ([['dashboard.view', '/'], ['leads.view', '/leads'], ['visits.view', '/visits']] as const).find(([p, path]) => can.has(user, p) && seesPage(user, path))

  return home?.[1] ?? '/settings/profile'
}

/** The dashboard, unless the workspace has switched it off for this person. */
function HomeRoute() {
  const user = useAuth((s) => s.user)

  return seesPage(user, '/') ? <DashboardPage /> : <Navigate to={firstHome(user)} replace />
}

/** Blocks a route until the session is known, so no flash of the login screen. */
function RequireAuth({ children }: { children: React.ReactNode }) {
  const status = useAuth((s) => s.status)
  const location = useLocation()

  if (status === 'idle' || status === 'loading') {
    return (
      <div className="grid min-h-dvh place-items-center">
        <Skeleton className="size-10 rounded-full" />
      </div>
    )
  }

  if (status === 'anonymous') {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  return <>{children}</>
}

export function App() {
  const restore = useAuth((s) => s.restore)
  const branding = useAuth((s) => s.branding)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const { i18n } = useTranslation()

  useBrandingTheme(branding)

  useEffect(() => {
    void restore()
  }, [restore])

  // Keep <html dir> in sync whenever the language changes anywhere in the app.
  useEffect(() => {
    document.documentElement.dir = i18n.dir()
    document.documentElement.lang = i18n.language
  }, [i18n, i18n.language])

  return (
    <>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        {/* A live report behind a link: no session, no app chrome. */}
        <Route path="/reports/share/:token" element={<Suspense fallback={<PageFallback />}><SharedReportPage /></Suspense>} />

        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          <Route
            index
            element={isSuperAdmin ? <Navigate to="/platform" replace /> : <HomeRoute />}
          />

          <Route
            path="*"
            element={
              <Suspense fallback={<PageFallback />}>
                <Routes>
                  <Route path="/work" element={<RequirePermission any={['leads.execute']}><WorkPage /></RequirePermission>} />
                  <Route path="/data-quality" element={<RequirePermission any={['leads.view']}><DataQualityPage /></RequirePermission>} />
                  <Route path="/automation" element={<RequirePermission any={['automation.view']}><AutomationPage /></RequirePermission>} />
                  <Route path="/leads" element={<RequirePermission any={['leads.view']}><LeadsPage /></RequirePermission>} />
                  <Route path="/leads/:id" element={<RequirePermission any={['leads.view']}><LeadDetailPage /></RequirePermission>} />
                  <Route path="/pipeline" element={<RequirePermission any={['leads.view']}><PipelinePage /></RequirePermission>} />
                  <Route path="/campaigns" element={<RequirePermission any={['campaigns.view']}><CampaignsPage /></RequirePermission>} />
                  <Route path="/tracking" element={<RequirePermission any={['tracking.view']}><TrackingPage /></RequirePermission>} />
                  <Route path="/audit" element={<RequirePermission any={['audit.view']}><AuditPage /></RequirePermission>} />
                  <Route path="/partners" element={<RequirePermission any={['partners.view']}><PartnersPage /></RequirePermission>} />
                  <Route path="/visits" element={<RequirePermission any={['visits.view', 'reservations.view']}><VisitsPage /></RequirePermission>} />
                  <Route path="/agents" element={<RequirePermission any={['campaigns.view']}><AgentsPage /></RequirePermission>} />
                  <Route path="/creatives" element={<RequirePermission any={['campaigns.view']}><CreativesPage /></RequirePermission>} />
                  <Route path="/campaigns/:id" element={<RequirePermission any={['campaigns.view']}><CampaignDetailPage /></RequirePermission>} />
                  <Route path="/follow-ups" element={<RequirePermission any={['leads.view']}><FollowUpsPage /></RequirePermission>} />
                  <Route path="/companies" element={<RequirePermission any={['companies.view']}><CompaniesPage /></RequirePermission>} />
                  <Route path="/projects" element={<RequirePermission any={['projects.view']}><ProjectsPage /></RequirePermission>} />
                  <Route path="/projects/:id" element={<RequirePermission any={['projects.view']}><ProjectDetailPage /></RequirePermission>} />
                  <Route path="/reports" element={<RequirePermission any={['analytics.view']}><ReportsPage /></RequirePermission>} />
                  <Route path="/integrations" element={<RequirePermission any={['integrations.view']}><IntegrationsPage /></RequirePermission>} />
                  <Route path="/readiness" element={<ReadinessPage />} />
                  <Route path="/access" element={<RequirePermission any={['users.edit']}><AccessPage /></RequirePermission>} />
                  <Route path="/users" element={<RequirePermission any={['users.view']}><UsersPage /></RequirePermission>} />
                  <Route path="/settings" element={<RequirePermission any={['settings.view']}><SettingsPage /></RequirePermission>} />
                  <Route path="/settings/profile" element={<ProfilePage />} />

                  {/*
                    Internal reference, development only. It is not a feature,
                    and a customer finding it would be reading our notes —
                    `import.meta.env.DEV` is compile-time, so the route and its
                    chunk are absent from a production build entirely.
                  */}
                  {import.meta.env.DEV && (
                    <Route path="/dev/design-system" element={<DesignSystemPage />} />
                  )}
                  <Route
                    path="/platform"
                    element={
                      <RequireSuperAdmin>
                        <PlatformPage />
                      </RequireSuperAdmin>
                    }
                  />
                  <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
              </Suspense>
            }
          />
        </Route>
      </Routes>

      <Toaster />
    </>
  )
}
