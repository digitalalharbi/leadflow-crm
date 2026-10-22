import { create } from 'zustand'

interface PwaState {
  /** `navigator.onLine`, kept current by the window's online/offline events. */
  online: boolean
  /** Opened from a home-screen icon (the person added it themselves): no browser bars around it. */
  standalone: boolean
  /** A new version of the app is installed and waiting to take over. */
  waiting: ServiceWorker | null
  setOnline: (online: boolean) => void
  setStandalone: (standalone: boolean) => void
  setWaiting: (worker: ServiceWorker | null) => void
  applyUpdate: () => void
}

export const usePwa = create<PwaState>((set, get) => ({
  online: typeof navigator === 'undefined' ? true : navigator.onLine,
  standalone: false,
  waiting: null,
  setOnline: (online) => set({ online }),
  setStandalone: (standalone) => set({ standalone }),
  setWaiting: (waiting) => set({ waiting }),
  // The waiting worker takes over, and register.ts reloads the page when it has.
  applyUpdate: () => get().waiting?.postMessage({ type: 'SKIP_WAITING' }),
}))
