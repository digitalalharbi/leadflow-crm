import { Outlet } from 'react-router-dom'
import { useAuth } from '@/stores/auth'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)

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
      <div className="lg:ms-[17rem]">
        <Topbar />
        <main className="mx-auto w-full max-w-[90rem] px-4 py-5 sm:px-6 sm:py-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
