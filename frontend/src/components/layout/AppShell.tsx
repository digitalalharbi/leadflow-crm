import { Outlet } from 'react-router-dom'
import { useLiveFeed } from '@/hooks/useLiveFeed'
import { useAuth } from '@/stores/auth'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)

  /*
   * Mounted here rather than on the screens that use it: one socket for the
   * session, subscribed once, instead of one per page that happens to show
   * leads — and it keeps listening while somebody is on a screen that does not.
   */
  useLiveFeed()

  return (
    /*
     * The platform console and a tenant workspace must never be mistaken for
     * each other — one wrong click there affects every customer. The whole
     * difference is carried by this one attribute: the stylesheet re-points the
     * chrome's surface tokens to ink, and every child follows without knowing
     * which console it is in.
     */
    <div className="min-h-dvh" data-console={isSuperAdmin ? 'platform' : undefined}>
      <Sidebar />

      {/* The sidebar is fixed, so the content is offset logically (works in RTL). */}
      <div className="lg:ms-[13.5rem]">
        <Topbar />
        <main className="mx-auto w-full max-w-[110rem] px-3 py-3 sm:px-4 sm:py-4">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
