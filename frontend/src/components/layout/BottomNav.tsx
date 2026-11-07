import { useEffect, useState } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { Menu, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'
import { can, useAuth } from '@/stores/auth'
import { HOME } from '@/lib/routes'
import { useNavigation } from './navigation'

/**
 * How many destinations sit on the bar beside the centre action and "More". A person who cannot create a lead has no centre
 * action, and the room goes to a fourth destination instead.
 */
const TABS = 3

/** A text field has the keyboard open: the bar would only sit on top of it, eating the little room left. */
function useKeyboardOpen(): boolean {
  const [open, setOpen] = useState(false)

  useEffect(() => {
    const typing = (el: EventTarget | null) =>
      el instanceof HTMLElement && (el.matches('textarea, select, [contenteditable="true"]') || (el instanceof HTMLInputElement && !['checkbox', 'radio', 'button', 'submit', 'file', 'range', 'color'].includes(el.type)))
    const onIn = (e: FocusEvent) => setOpen(typing(e.target))
    const onOut = () => setOpen(false)

    document.addEventListener('focusin', onIn)
    document.addEventListener('focusout', onOut)

    return () => {
      document.removeEventListener('focusin', onIn)
      document.removeEventListener('focusout', onOut)
    }
  }, [])

  return open
}

/**
 * The phone's navigation: a tab bar at the thumb, the way an app has one.
 *
 * The four places this person goes most (from the same permission-aware list the header and the
 * drawer use, so a role never sees a tab it cannot open) and "More", which opens the full list.
 * Phones and small tablets only; from the laptop breakpoint the header's pill track takes over.
 */
export function BottomNav() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const user = useAuth((s) => s.user)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const { tabs } = useNavigation()
  const sidebarOpen = useUi((s) => s.sidebarOpen)
  const setSidebarOpen = useUi((s) => s.setSidebarOpen)
  const keyboard = useKeyboardOpen()
  const focusMode = useUi((s) => s.focusMode)

  // A focused screen (the lead on a phone) has its own action bar where this one would be.
  if (keyboard || focusMode) return null

  // The centre action is the one thing a salesperson does most on a phone: put a lead in. The same permission as the form.
  const canAdd = !isSuperAdmin && can.has(user, 'leads.create')
  const shown = tabs.slice(0, canAdd ? TABS : TABS + 1)
  const before = canAdd ? shown.slice(0, 2) : shown
  const after = canAdd ? shown.slice(2) : []

  const tab = ({ to, labelKey, Icon }: (typeof shown)[number]) => (
    <NavLink
      key={to}
      to={to}
      end={to === '/dashboard'}
      onClick={() => setSidebarOpen(false)}
      // Its own prefix: the bar is in the DOM at every width (hidden from the laptop breakpoint), and
      // the pages' own tab strips use `tab-<key>`, so sharing the prefix made two elements answer to one id.
      data-testid={`bottom-tab-${to === HOME ? 'home' : to.slice(1)}`}
      className={({ isActive }) => cn('bottom-tab', isActive && 'bottom-tab-active')}
    >
      <Icon className="size-[1.375rem]" aria-hidden />
      <span className="truncate">{t(`nav.short.${labelKey.replace('nav.', '')}`, { defaultValue: t(labelKey) })}</span>
    </NavLink>
  )

  return (
    <nav
      aria-label={t('nav.tabs')}
      data-testid="bottom-nav"
      className="bottom-nav fixed inset-x-0 bottom-0 z-[25] flex items-stretch bg-[var(--color-surface)] shadow-[0_-1px_0_var(--color-line),0_-8px_24px_-12px_rgb(0_0_0/0.12)] lg:hidden print:hidden"
    >
      {before.map(tab)}

      {canAdd && (
        <div className="bottom-tab-fab-slot">
          <button
            type="button"
            aria-label={t('leads.addLead')}
            data-testid="bottom-nav-add"
            className="bottom-tab-fab"
            onClick={() => { setSidebarOpen(false); void navigate(`/leads?new=${Date.now()}`) }}
          >
            <Plus className="size-7" aria-hidden />
          </button>
        </div>
      )}

      {after.map(tab)}

      <button
        type="button"
        onClick={() => setSidebarOpen(!sidebarOpen)}
        aria-expanded={sidebarOpen}
        data-testid="bottom-nav-more"
        className={cn('bottom-tab', sidebarOpen && 'bottom-tab-active')}
      >
        <Menu className="size-[1.375rem]" aria-hidden />
        <span className="truncate">{t('nav.more')}</span>
      </button>
    </nav>
  )
}
