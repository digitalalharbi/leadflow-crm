import type { ReactNode } from 'react'
import { sees } from '@/lib/permissions'
import { useAuth } from '@/stores/auth'

/** Does this person get to see `element`? For the cases where a component needs the answer, not a wrapper. */
export function useSees(element: string): boolean {
  const user = useAuth((s) => s.user)

  return sees(user, element)
}

/**
 * Renders its children only for somebody the workspace shows `element` to — see `config/features.php`
 * on the server for the list. What is off is not drawn: no placeholder, no blur, nothing to inspect.
 */
export function Gate({ element, children }: { element: string; children: ReactNode }) {
  return useSees(element) ? <>{children}</> : null
}
