import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { PageHeaderSlotProvider } from '@/components/ui/PageHeader'
import { useLiveFeed } from '@/hooks/useLiveFeed'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { BottomNav } from './BottomNav'
import { IconRail } from './IconRail'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

/**
 * The application frame.
 *
 * One large rounded workspace floating on the darker outer canvas, holding a
 * white capsule header, a compact icon rail and the page. The frame is a layout
 * (a grid with a rail column and a content column), not a coordinate system:
 * in Arabic the rail is on the start edge because the grid says so, not because
 * anything was mirrored after the fact.
 *
 * Below the desktop breakpoint the frame gives its margin back, the header becomes a flat app bar,
 * the rail becomes a drawer and a tab bar sits at the thumb — a phone has no use for a floating
 * window on a table, and should feel like an app, not a shrunken website.
 */
export function AppShell() {
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const focusMode = useUi((s) => s.focusMode)
  // A callback ref into state: the pages below need the element itself, and
  // need to re-render once it exists.
  const [titleSlot, setTitleSlot] = useState<HTMLElement | null>(null)

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
    <div
      className="app-outer min-h-dvh bg-[var(--color-outer)] p-0 sm:p-3 lg:p-5 print:bg-white print:p-0"
      data-console={isSuperAdmin ? 'platform' : undefined}
    >
      <div
        data-focus={focusMode}
        className="app-frame mx-auto flex min-h-dvh w-full max-w-[112rem] flex-col gap-4 bg-[var(--color-canvas)] p-2 pb-[calc(5.5rem+env(safe-area-inset-bottom))] data-[focus=true]:max-lg:p-0 sm:min-h-[calc(100dvh-1.5rem)] sm:rounded-[var(--radius-frame)] sm:p-3 lg:min-h-[calc(100dvh-2.5rem)] lg:p-4 lg:pb-4 print:min-h-0 print:p-0"
      >
        {/* On a phone a focused screen has its own header; the app bar would only cost it height. */}
        <div className={focusMode ? 'max-lg:hidden' : 'contents'}><Topbar /></div>

        {/* The title row: full width, above the rail and the cards. */}
        <div ref={setTitleSlot} className="empty:hidden" data-testid="page-header-slot" />

        <div className="flex flex-1 items-start gap-4">
          <IconRail />
          <main className="min-w-0 flex-1">
            <PageHeaderSlotProvider value={titleSlot}>
              <Outlet />
            </PageHeaderSlotProvider>
          </main>
        </div>
      </div>

      {/* After the frame in the DOM: on a desktop it is not rendered at all, and
          the first brand mark on the page must be the header's. */}
      <Sidebar />

      {/* The phone's tab bar. After the drawer's scrim in the order of things that float, below the drawer itself. */}
      <BottomNav />
    </div>
  )
}
