import { create } from 'zustand'

/** Chrome's install prompt, which the browser hands over once and expects to be shown on a person's own action. */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const DISMISS_KEY = 'mahtam.pwa.dismissed'
const DISMISS_DAYS = { install: 14, ios: 30 } as const

type Prompt = keyof typeof DISMISS_DAYS

function readDismissed(): Partial<Record<Prompt, number>> {
  try {
    return JSON.parse(localStorage.getItem(DISMISS_KEY) ?? '{}') as Partial<Record<Prompt, number>>
  } catch {
    return {}
  }
}

interface PwaState {
  /** `navigator.onLine`, kept current by the window's online/offline events. */
  online: boolean
  installEvent: BeforeInstallPromptEvent | null
  /** Running from the home screen already. */
  standalone: boolean
  /** A new version of the app is installed and waiting to take over. */
  waiting: ServiceWorker | null
  dismissed: Partial<Record<Prompt, number>>
  setOnline: (online: boolean) => void
  setInstallEvent: (event: BeforeInstallPromptEvent | null) => void
  setStandalone: (standalone: boolean) => void
  setWaiting: (worker: ServiceWorker | null) => void
  dismiss: (prompt: Prompt) => void
  /** Whether a prompt was put away recently enough that it should stay away. */
  isDismissed: (prompt: Prompt) => boolean
  applyUpdate: () => void
}

export const usePwa = create<PwaState>((set, get) => ({
  online: typeof navigator === 'undefined' ? true : navigator.onLine,
  installEvent: null,
  standalone: false,
  waiting: null,
  dismissed: typeof localStorage === 'undefined' ? {} : readDismissed(),
  setOnline: (online) => set({ online }),
  setInstallEvent: (installEvent) => set({ installEvent }),
  setStandalone: (standalone) => set({ standalone }),
  setWaiting: (waiting) => set({ waiting }),
  dismiss: (prompt) => {
    const dismissed = { ...get().dismissed, [prompt]: Date.now() }

    set({ dismissed })

    try {
      localStorage.setItem(DISMISS_KEY, JSON.stringify(dismissed))
    } catch {
      /* private browsing: it will simply ask again next visit */
    }
  },
  isDismissed: (prompt) => {
    const at = get().dismissed[prompt]

    return typeof at === 'number' && Date.now() - at < DISMISS_DAYS[prompt] * 86_400_000
  },
  // The waiting worker takes over, and register.ts reloads the page when it has.
  applyUpdate: () => get().waiting?.postMessage({ type: 'SKIP_WAITING' }),
}))
