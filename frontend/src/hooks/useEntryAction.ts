import { HOME } from '@/lib/routes'
import { useAuth } from '@/stores/auth'

/**
 * Where a public page's “way in” goes: to the free-trial signup for a visitor (the login is its own link beside it), to the dashboard for somebody already signed in.
 * The public page itself never moves a signed-in person anywhere; it only offers the right button.
 */
export function useEntryAction(): { authenticated: boolean; to: string } {
  const status = useAuth((s) => s.status)
  const isSuperAdmin = useAuth((s) => s.isSuperAdmin)
  const authenticated = status === 'authenticated'

  return { authenticated, to: authenticated ? (isSuperAdmin ? '/platform' : HOME) : '/signup' }
}
