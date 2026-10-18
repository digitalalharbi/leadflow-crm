import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { queryConfig } from '@/lib/queryConfig'
import { BrowserRouter } from 'react-router-dom'
import { setUnauthenticatedHandler } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { applyAppearance, useUi } from '@/stores/ui'
import { App } from '@/App'
import '@/i18n'
/*
 * The typography system: three families, all self-hosted so the product does
 * not depend on a font CDN being reachable (or on telling Google who is using
 * it). Inter's variable file carries every weight and the tabular figures;
 * IBM Plex Sans Arabic carries the Arabic at the four weights the scale uses;
 * JetBrains Mono is only ever loaded for technical values.
 */
import '@fontsource-variable/inter/index.css'
import '@fontsource/ibm-plex-sans-arabic/400.css'
import '@fontsource/ibm-plex-sans-arabic/500.css'
import '@fontsource/ibm-plex-sans-arabic/600.css'
import '@fontsource/ibm-plex-sans-arabic/700.css'
import '@fontsource/jetbrains-mono/400.css'
import '@fontsource/jetbrains-mono/500.css'
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

const queryClient = new QueryClient(queryConfig)

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
