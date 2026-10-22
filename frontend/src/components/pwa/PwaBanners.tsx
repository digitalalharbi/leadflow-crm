import { useEffect, useState } from 'react'
import { Download, RefreshCw, Share, WifiOff, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { useAuth } from '@/stores/auth'
import { usePwa } from '@/stores/pwa'

/** iPhone, iPad (which says it is a Mac) — Safari there has no install event, only "Add to Home Screen". */
function isIosSafari(): boolean {
  if (typeof navigator === 'undefined') return false

  const ua = navigator.userAgent
  const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const safari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|DuckDuckGo/.test(ua)

  return ios && safari
}

/**
 * Three small notices, each only when it is true and useful:
 *
 *  - offline: there is no connection, so what is on screen may be old and nothing can be changed;
 *  - update: a new version is installed and waiting — one press and the page reloads into it;
 *  - install: the browser will let this be put on the home screen (or, on an iPhone, how to).
 *
 * Installing is offered only to somebody signed in, only after they have been here a while, and not
 * again for two weeks once put away.
 */
export function PwaBanners() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const { online, installEvent, standalone, waiting, applyUpdate, dismiss, isDismissed, setInstallEvent } = usePwa()
  const [settled, setSettled] = useState(false)
  const [iosOpen, setIosOpen] = useState(true)

  // Not the first thing a person sees: let them get on with what they came for.
  useEffect(() => {
    if (!user) return

    const timer = window.setTimeout(() => setSettled(true), 15_000)

    return () => window.clearTimeout(timer)
  }, [user])

  const canInstall = Boolean(user) && settled && !standalone && Boolean(installEvent) && !isDismissed('install')
  const iosHint = Boolean(user) && settled && !standalone && !installEvent && isIosSafari() && !isDismissed('ios') && iosOpen

  const install = async () => {
    if (!installEvent) return

    await installEvent.prompt()
    await installEvent.userChoice.catch(() => undefined)
    // The event can be used once, whatever the answer.
    setInstallEvent(null)
  }

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

      <div
        className="pointer-events-none fixed inset-x-0 z-[65] flex flex-col items-center gap-2 px-4"
        style={{ bottom: 'max(5.5rem, calc(env(safe-area-inset-bottom) + 4.5rem))' }}
      >
        {waiting && (
          <div role="status" data-testid="pwa-update" className="pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] ring-1 ring-[var(--color-line)]">
            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><RefreshCw className="size-4" /></span>
            <p className="min-w-0 flex-1 text-[0.9375rem] font-semibold">{t('pwa.updateReady')}</p>
            <Button size="sm" onClick={applyUpdate} data-testid="pwa-update-apply">{t('pwa.updateNow')}</Button>
          </div>
        )}

        {canInstall && (
          <div role="status" data-testid="pwa-install" className="pointer-events-auto flex w-full max-w-md items-center gap-3 rounded-2xl bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] ring-1 ring-[var(--color-line)]">
            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><Download className="size-4" /></span>
            <p className="min-w-0 flex-1 text-[0.9375rem]"><strong className="block">{t('pwa.installTitle')}</strong><span className="t-caption">{t('pwa.installBody')}</span></p>
            <Button size="sm" onClick={() => void install()} data-testid="pwa-install-accept">{t('pwa.install')}</Button>
            <button type="button" aria-label={t('pwa.notNow')} title={t('pwa.notNow')} data-testid="pwa-install-dismiss" onClick={() => dismiss('install')} className="pressable grid size-8 shrink-0 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)]"><X className="size-4" aria-hidden /></button>
          </div>
        )}

        {iosHint && (
          <div role="status" data-testid="pwa-ios-hint" className="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-2xl bg-[var(--color-surface)] p-3 shadow-[var(--shadow-card)] ring-1 ring-[var(--color-line)]">
            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]" aria-hidden><Share className="size-4" /></span>
            <p className="min-w-0 flex-1 text-[0.9375rem]"><strong className="block">{t('pwa.installTitle')}</strong><span className="t-caption">{t('pwa.iosSteps')}</span></p>
            <button type="button" aria-label={t('pwa.gotIt')} title={t('pwa.gotIt')} data-testid="pwa-ios-dismiss" onClick={() => { dismiss('ios'); setIosOpen(false) }} className="pressable grid size-8 shrink-0 place-items-center rounded-full hover:bg-[var(--color-chrome-fill)]"><X className="size-4" aria-hidden /></button>
          </div>
        )}
      </div>
    </>
  )
}
