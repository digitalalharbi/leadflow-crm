import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { queryConfig } from '@/lib/queryConfig'
import { BrowserRouter } from 'react-router-dom'
import { setMfaRequiredHandler, setSubscriptionClosedHandler, setUnauthenticatedHandler } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { applyAppearance, useUi } from '@/stores/ui'
import { App } from '@/App'
import { startPwa } from '@/pwa/register'
import '@/i18n'
import '@/fonts'
import '@/index.css'

/*
 * Before React mounts, so the first paint is already the right theme. A flash
 * of white on the way into dark mode is the one thing people notice about it.
 */
applyAppearance(useUi.getState().appearance)

// Installable, and honest about being offline; the service worker only in a production build.
startPwa()

globalThis.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => {
  // Only while following the system; an explicit choice is not overridden by
  // the laptop deciding it is sunset.
  if (useUi.getState().appearance === 'system') {
    applyAppearance('system')
  }
})

const queryClient = new QueryClient(queryConfig)

// A 401 anywhere drops the session and sends the user back to the login screen.
setUnauthenticatedHandler(() => {
  useAuth.getState().reset()
  queryClient.clear()
})

// A 402 anywhere: the workspace's subscription is closed. The shell swaps the page for the screen that says why.
// One place to go when the workspace requires a second factor: the profile's security card.
setMfaRequiredHandler(() => {
  if (window.location.pathname !== '/profile') window.location.assign('/profile#security')
})

setSubscriptionClosedHandler((state) => {
  useAuth.getState().closeSubscription(state as never)
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
