import { useSyncExternalStore } from 'react'

/** The width below which the product is a phone's: the same line the tab bar, drawer and header use (Tailwind `lg`). */
const QUERY = '(max-width: 1023.98px)'

function subscribe(onChange: () => void): () => void {
  const list = globalThis.matchMedia?.(QUERY)

  list?.addEventListener('change', onChange)

  return () => list?.removeEventListener('change', onChange)
}

const snapshot = () => globalThis.matchMedia?.(QUERY).matches ?? false

/** True on a phone or small tablet, and re-evaluated when the window is resized or rotated. */
export function useIsPhone(): boolean {
  return useSyncExternalStore(subscribe, snapshot, () => false)
}
