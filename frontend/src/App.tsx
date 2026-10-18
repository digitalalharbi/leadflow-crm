import { useEffect, lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/stores/auth'
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

        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          <Route
            index
            element={isSuperAdmin ? <Navigate to="/platform" replace /> : <DashboardPage />}
          />

          <Route
            path="*"
            element={
              <Suspense fallback={<PageFallback />}>
                <Routes>
                  <Route path="/work" element={<WorkPage />} />
                  <Route path="/data-quality" element={<DataQualityPage />} />
                  <Route path="/automation" element={<AutomationPage />} />
                  <Route path="/leads" element={<LeadsPage />} />
                  <Route path="/leads/:id" element={<LeadDetailPage />} />
                  <Route path="/pipeline" element={<PipelinePage />} />
                  <Route path="/follow-ups" element={<FollowUpsPage />} />
                  <Route path="/companies" element={<CompaniesPage />} />
                  <Route path="/projects" element={<ProjectsPage />} />
                  <Route path="/projects/:id" element={<ProjectDetailPage />} />
                  <Route path="/reports" element={<ReportsPage />} />
                  <Route path="/integrations" element={<IntegrationsPage />} />
                  <Route path="/readiness" element={<ReadinessPage />} />
                  <Route path="/users" element={<UsersPage />} />
                  <Route path="/settings" element={<SettingsPage />} />
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
