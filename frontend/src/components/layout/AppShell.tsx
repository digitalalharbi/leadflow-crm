import { Outlet } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

export function AppShell() {
  return (
    <div className="min-h-dvh">
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
