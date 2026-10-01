import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { ApiError, setUnauthenticatedHandler } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { applyAppearance, useUi } from '@/stores/ui'
import { App } from '@/App'
import '@/i18n'
import '@/index.css'

/*
 * Before React mounts, so the first paint is already the right theme. A flash
 * of white on the way into dark mode is the one thing people notice about it.
 */
applyAppearance(useUi.getState().appearance)

globalThis.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => {
  // Only while following the system; an explicit choice is not overridden by
  // the laptop deciding it is sunset.
  if (useUi.getState().appearance === 'system') {
    applyAppearance('system')
  }
})

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        // Never retry a request the server has already judged; only transient faults.
        if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false
        return failureCount < 2
      },
    },
    mutations: { retry: false },
  },
})

// A 401 anywhere drops the session and sends the user back to the login screen.
setUnauthenticatedHandler(() => {
  useAuth.getState().reset()
  queryClient.clear()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
