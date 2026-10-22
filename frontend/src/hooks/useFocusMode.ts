import { useEffect } from 'react'
import { useUi } from '@/stores/ui'

/** Turns the app bar and tab bar off for as long as the calling screen is mounted (see `focusMode` in the UI store). */
export function useFocusMode(on = true): void {
  const setFocusMode = useUi((s) => s.setFocusMode)

  useEffect(() => {
    setFocusMode(on)

    return () => setFocusMode(false)
  }, [on, setFocusMode])
}
