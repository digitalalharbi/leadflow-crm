import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { applyAppearance, storedAppearance } from '@/lib/appearance'
import { LeadPage } from '@/pages/public/LeadPage'
import './i18n'
import '@fontsource/ibm-plex-sans-arabic/arabic-400.css'
import '@fontsource/ibm-plex-sans-arabic/latin-400.css'
import '@fontsource/ibm-plex-sans-arabic/arabic-500.css'
import '@fontsource/ibm-plex-sans-arabic/latin-500.css'
import '@fontsource/ibm-plex-sans-arabic/arabic-600.css'
import '@fontsource/ibm-plex-sans-arabic/latin-600.css'
import '@fontsource/ibm-plex-sans-arabic/arabic-700.css'
import '@fontsource/ibm-plex-sans-arabic/latin-700.css'
import './landing.css'

applyAppearance(storedAppearance())

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <LeadPage token={decodeURIComponent(window.location.pathname.split('/')[2])} search={window.location.search} />
  </StrictMode>,
)
