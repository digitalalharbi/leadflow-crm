import { RefreshCw, WifiOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { usePwa } from '@/stores/pwa'

/**
 * Two small notices, each only when it is true:
 *
 *  - offline: there is no connection, so what is on screen may be old and nothing can be changed;
 *  - update: a new version is installed and waiting — one press and the page reloads into it.
 *
 * Installing is never pushed: the product opens in a browser and is made to feel like an app there
 * (see BottomNav). A person who adds it to their home screen themselves gets the same screens.
 */
export function PwaBanners() {
  const { t } = useTranslation()
  const { online, waiting, applyUpdate } = usePwa()

  return (
    <>
      {!online && (
        <div
          role="status"
          aria-live="polite"
          data-testid="pwa-offline"
          className="fixed inset-x-0 top-0 z-[70] flex items-center justify-center gap-2 bg-[var(--color-ink)] px-4 pb-2 text-center text-[0.9375rem] font-semibold text-[var(--color-canvas)]"
          style={{ paddingTop: 'max(0.5rem, env(safe-area-inset-top))' }}
        >
          <WifiOff className="size-4 shrink-0" aria-hidden />
          <span>{t('pwa.offline')}</span>
        </div>
      )}

      {waiting && (
        <div
          className="pointer-events-none fixed inset-x-0 z-[65] flex justify-center px-4"
          style={{ bottom: 'max(5.5rem, calc(env(safe-area-inset-bottom) + 4.5rem))' }}
        >
          <div role="status" data-testid="pwa-update" className="pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] ring-1 ring-[var(--color-line)]">
            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><RefreshCw className="size-4" /></span>
            <p className="min-w-0 flex-1 text-[0.9375rem] font-semibold">{t('pwa.updateReady')}</p>
            <Button size="sm" onClick={applyUpdate} data-testid="pwa-update-apply">{t('pwa.updateNow')}</Button>
          </div>
        </div>
      )}
    </>
  )
}
