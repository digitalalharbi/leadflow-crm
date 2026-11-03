import { useEffect, lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom'
import { HOME, INDEXABLE_PATHS } from '@/lib/routes'
import { useTitleStore, useTitleWriter } from '@/lib/brand'
import { routeTitleKey } from '@/lib/routeTitle'
import { RequireFeature } from '@/components/billing/FeatureLocked'
import { useTranslation } from 'react-i18next'
import { seesPage } from '@/lib/permissions'
import { can, useAuth } from '@/stores/auth'
import { useBrandingTheme } from '@/hooks/useBrandingTheme'
import { AppShell } from '@/components/layout/AppShell'
import { LoginPage } from '@/pages/Login'
import { Toaster, Skeleton } from '@/components/ui'
import { PwaBanners } from '@/components/pwa/PwaBanners'
import { useIsPhone } from '@/hooks/useIsPhone'

// Everything past the dashboard is code-split: the first paint only pays for
// what a user actually lands on.
/*
 * Guarded at the import, not only at the route. `import.meta.env.DEV` is
 * replaced with `false` at build time, so the branch is dead code and the
 * chunk is never emitted — a conditional route alone still ships the file.
 */
// The dashboard carries every chart in the product; it is a chunk of its own so a phone working a lead never downloads it.
const DashboardPage = lazy(() => import('@/pages/Dashboard').then((m) => ({ default: m.DashboardPage })))
const DesignSystemPage = import.meta.env.DEV
  ? lazy(() => import('@/pages/dev/DesignSystem').then((m) => ({ default: m.DesignSystemPage })))
  : () => null
const AccessPage = lazy(() => import('@/pages/Access').then((m) => ({ default: m.AccessPage })))
const WorkPage = lazy(() => import('@/pages/Work').then((m) => ({ default: m.WorkPage })))
// A phone gets the sales flow's own screens, as chunks of their own: the desktop lead page and its charts are never downloaded there.
// The public pages: no session, no app chrome, each its own small chunk.
const LandingPage = lazy(() => import('@/pages/public/Landing').then((m) => ({ default: m.LandingPage })))
const PrivacyPage = lazy(() => import('@/pages/public/pages').then((m) => ({ default: m.PrivacyPage })))
const TermsPage = lazy(() => import('@/pages/public/pages').then((m) => ({ default: m.TermsPage })))
const DataDeletionPage = lazy(() => import('@/pages/public/pages').then((m) => ({ default: m.DataDeletionPage })))
const SecurityPage = lazy(() => import('@/pages/public/pages').then((m) => ({ default: m.SecurityPage })))
const ContactPage = lazy(() => import('@/pages/public/pages').then((m) => ({ default: m.ContactPage })))
const AboutPage = lazy(() => import('@/pages/public/pages').then((m) => ({ default: m.AboutPage })))
const FeaturesPage = lazy(() => import('@/pages/public/Marketing').then((m) => ({ default: m.FeaturesPage })))
const JourneyPage = lazy(() => import('@/pages/public/Marketing').then((m) => ({ default: m.JourneyPage })))
const VisionPage = lazy(() => import('@/pages/public/Marketing').then((m) => ({ default: m.VisionPage })))
const MobileWork = lazy(() => import('@/components/mobile/MobileWork').then((m) => ({ default: m.MobileWork })))
const MobileLeadScreen = lazy(() => import('@/components/mobile/MobileLeadScreen').then((m) => ({ default: m.MobileLeadScreen })))
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
const LeadPage = lazy(() => import('@/pages/public/LeadPage').then((m) => ({ default: m.LeadPage })))

/** A landing page reached from inside the app; opened from outside, `landing/main.tsx` renders it without the app. */
function PublicLeadPage() {
  const { token = '' } = useParams()
  const { search } = useLocation()

  return <LeadPage key={token} token={token} search={search} />
}
const SharedReportPage = lazy(() => import('@/pages/SharedReport').then((m) => ({ default: m.SharedReportPage })))
const AuditPage = lazy(() => import('@/pages/Audit').then((m) => ({ default: m.AuditPage })))
const PartnersPage = lazy(() => import('@/pages/Partners').then((m) => ({ default: m.PartnersPage })))
const VisitsPage = lazy(() => import('@/pages/Visits').then((m) => ({ default: m.VisitsPage })))
const FormBuilderPage = lazy(() => import('@/pages/FormBuilder').then((m) => ({ default: m.FormBuilderPage })))
const TrackingPage = lazy(() => import('@/pages/Tracking').then((m) => ({ default: m.TrackingPage })))
const CampaignDetailPage = lazy(() => import('@/pages/CampaignDetail').then((m) => ({ default: m.CampaignDetailPage })))
const ReportsPage = lazy(() => import('@/pages/Reports').then((m) => ({ default: m.ReportsPage })))
const IntegrationsPage = lazy(() => import('@/pages/Integrations').then((m) => ({ default: m.IntegrationsPage })))
const UsersPage = lazy(() => import('@/pages/Users').then((m) => ({ default: m.UsersPage })))
const SettingsPage = lazy(() => import('@/pages/Settings').then((m) => ({ default: m.SettingsPage })))
const ProfilePage = lazy(() => import('@/pages/Profile').then((m) => ({ default: m.ProfilePage })))
const ReadinessPage = lazy(() => import('@/pages/Readiness').then((m) => ({ default: m.ReadinessPage })))
const InvitationPage = lazy(() => import('@/pages/AccountAccess').then((m) => ({ default: m.InvitationPage })))
const SetPasswordPage = lazy(() => import('@/pages/AccountAccess').then((m) => ({ default: m.SetPasswordPage })))
const ForgotPasswordPage = lazy(() => import('@/pages/AccountAccess').then((m) => ({ default: m.ForgotPasswordPage })))
const VerifyEmailPage = lazy(() => import('@/pages/AccountAccess').then((m) => ({ default: m.VerifyEmailPage })))
const ResetPasswordRedirect = lazy(() => import('@/pages/AccountAccess').then((m) => ({ default: m.ResetPasswordRedirect })))
const AcceptInviteRedirect = lazy(() => import('@/pages/AccountAccess').then((m) => ({ default: m.AcceptInviteRedirect })))
const PlatformLoginPage = lazy(() => import('@/pages/PlatformLogin').then((m) => ({ default: m.PlatformLoginPage })))
const PlatformPage = lazy(() => import('@/pages/Platform').then((m) => ({ default: m.PlatformPage })))

/** «اعمل الآن»: one lead at a time on a phone, the full board on a laptop. */
function WorkRoute() {
  return useIsPhone() ? <MobileWork /> : <WorkPage />
}

/** A lead: the conversation-first screen on a phone, the full workspace on a laptop. */
function LeadRoute() {
  return useIsPhone() ? <MobileLeadScreen /> : <LeadDetailPage />
}

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

  return isSuperAdmin ? <>{children}</> : <Navigate to={HOME} replace />
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
  const home = ([['dashboard.view', HOME], ['leads.view', '/leads'], ['visits.view', '/visits']] as const).find(([p, path]) => can.has(user, p) && seesPage(user, path))

  return home?.[1] ?? '/settings/profile'
}

/** The dashboard, unless the workspace has switched it off for this person. */
function HomeRoute() {
  const user = useAuth((s) => s.user)

  return seesPage(user, HOME) ? <Suspense fallback={<PageFallback />}><DashboardPage /></Suspense> : <Navigate to={firstHome(user)} replace />
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
    return <Navigate to={location.pathname.startsWith('/platform') ? '/platform/login' : '/login'} state={{ from: location }} replace />
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

  // Only the marketing and legal pages are for search engines; the login and the app are not (the public pages say so themselves).
  const { pathname } = useLocation()
  useEffect(() => {
    if (!(INDEXABLE_PATHS as readonly string[]).includes(pathname)) {
      document.querySelector<HTMLMetaElement>("meta[name='robots']")?.setAttribute('content', 'noindex, nofollow')
    }
  }, [pathname])

  // Every screen's tab reads `<screen> — مهتم` (`<screen> — Mohtam` in English); a screen about one thing names that thing.
  const setTitle = useTitleStore((s) => s.set)
  useEffect(() => {
    const key = routeTitleKey(pathname)
    setTitle({ route: key ? i18n.t(key) : null })
  }, [pathname, i18n, i18n.language, setTitle])
  useTitleWriter()

  // Keep <html dir> in sync whenever the language changes anywhere in the app.
  useEffect(() => {
    document.documentElement.dir = i18n.dir()
    document.documentElement.lang = i18n.language
  }, [i18n, i18n.language])

  return (
    <>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        {/* Into an account without a password in hand: an invitation, a first password, a forgotten one. */}
        <Route path="/invite/:token" element={<Suspense fallback={<PageFallback />}><InvitationPage /></Suspense>} />
        <Route path="/i/:token" element={<Suspense fallback={<PageFallback />}><InvitationPage /></Suspense>} />
        <Route path="/set-password/:token" element={<Suspense fallback={<PageFallback />}><SetPasswordPage /></Suspense>} />
        <Route path="/forgot-password" element={<Suspense fallback={<PageFallback />}><ForgotPasswordPage /></Suspense>} />
        <Route path="/verify-email" element={<Suspense fallback={<PageFallback />}><VerifyEmailPage /></Suspense>} />
        <Route path="/reset-password" element={<Suspense fallback={<PageFallback />}><ResetPasswordRedirect /></Suspense>} />
        <Route path="/accept-invite" element={<Suspense fallback={<PageFallback />}><AcceptInviteRedirect /></Suspense>} />
        {/* The platform owner's own door; a company's login never signs the owner in. */}
        <Route path="/platform/login" element={<Suspense fallback={<PageFallback />}><PlatformLoginPage /></Suspense>} />
        {/* Public, no session: what مهتم is, and the pages a visitor, a customer's lead or a platform reviewer expects to find. */}
        {/* The marketing page is the front door, for everybody; a signed-in person is not moved off it. */}
        <Route path="/" element={<Suspense fallback={<PageFallback />}><LandingPage /></Suspense>} />
        <Route path="/welcome" element={<Navigate to="/" replace />} />
        {/* What a business gets, told on its own pages; the front door stays one screen. */}
        <Route path="/features" element={<Suspense fallback={<PageFallback />}><FeaturesPage /></Suspense>} />
        <Route path="/journey" element={<Suspense fallback={<PageFallback />}><JourneyPage /></Suspense>} />
        <Route path="/vision" element={<Suspense fallback={<PageFallback />}><VisionPage /></Suspense>} />
        <Route path="/privacy" element={<Suspense fallback={<PageFallback />}><PrivacyPage /></Suspense>} />
        <Route path="/terms" element={<Suspense fallback={<PageFallback />}><TermsPage /></Suspense>} />
        <Route path="/data-deletion" element={<Suspense fallback={<PageFallback />}><DataDeletionPage /></Suspense>} />
        <Route path="/security" element={<Suspense fallback={<PageFallback />}><SecurityPage /></Suspense>} />
        <Route path="/contact" element={<Suspense fallback={<PageFallback />}><ContactPage /></Suspense>} />
        <Route path="/about" element={<Suspense fallback={<PageFallback />}><AboutPage /></Suspense>} />
        {/* The page a tracking link opens: the project, and the form that turns interest into a lead. No session, no chrome. */}
        <Route path="/l/:token" element={<Suspense fallback={<PageFallback />}><PublicLeadPage /></Suspense>} />
        {/* A live report behind a link: no session, no app chrome. */}
        <Route path="/reports/share/:token" element={<Suspense fallback={<PageFallback />}><SharedReportPage /></Suspense>} />
        {/* The canonical short forms a client receives: /r for a report, /i for an invitation. The long ones keep working. */}
        <Route path="/r/:token" element={<Suspense fallback={<PageFallback />}><SharedReportPage /></Suspense>} />

        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          <Route
            path="/dashboard"
            element={isSuperAdmin ? <Navigate to="/platform" replace /> : <HomeRoute />}
          />

          <Route
            path="*"
            element={
              <Suspense fallback={<PageFallback />}>
                <Routes>
                  <Route path="/work" element={<RequirePermission any={['leads.execute']}><WorkRoute /></RequirePermission>} />
                  <Route path="/data-quality" element={<RequirePermission any={['leads.view']}><DataQualityPage /></RequirePermission>} />
                  <Route path="/automation" element={<RequirePermission any={['automation.view']}><RequireFeature any={['automations']}><AutomationPage /></RequireFeature></RequirePermission>} />
                  <Route path="/leads" element={<RequirePermission any={['leads.view']}><LeadsPage /></RequirePermission>} />
                  <Route path="/leads/:id" element={<RequirePermission any={['leads.view']}><LeadRoute /></RequirePermission>} />
                  <Route path="/pipeline" element={<RequirePermission any={['leads.view']}><PipelinePage /></RequirePermission>} />
                  <Route path="/campaigns" element={<RequirePermission any={['campaigns.view']}><CampaignsPage /></RequirePermission>} />
                  <Route path="/tracking" element={<RequirePermission any={['tracking.view']}><RequireFeature any={['tracking_hub', 'advanced_forms', 'attribution']}><TrackingPage /></RequireFeature></RequirePermission>} />
                  <Route path="/tracking/forms/:id" element={<RequirePermission any={['tracking.view']}><RequireFeature any={['advanced_forms']}><Suspense fallback={<PageFallback />}><FormBuilderPage /></Suspense></RequireFeature></RequirePermission>} />
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
                  <Route path="*" element={<Navigate to={HOME} replace />} />
                </Routes>
              </Suspense>
            }
          />
        </Route>
      </Routes>

      <Toaster />
      <PwaBanners />
    </>
  )
}
