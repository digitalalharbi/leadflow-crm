/*
 * The typography system: one family, self-hosted so the product does not depend on a font CDN
 * being reachable (or on telling Google who is using it). IBM Plex Sans Arabic is the identity's
 * only typeface — Arabic and Latin in one rhythm, every digit 0–9 included — in the five weights
 * the scale uses. The files are the same OFL release as brand/Mohtam-Brand-Kit/06-الخطوط-Fonts,
 * served as subsetted woff2 so a phone downloads only the ranges a page draws.
 */
import '@fontsource/ibm-plex-sans-arabic/300.css'
import '@fontsource/ibm-plex-sans-arabic/400.css'
import '@fontsource/ibm-plex-sans-arabic/500.css'
import '@fontsource/ibm-plex-sans-arabic/600.css'
import '@fontsource/ibm-plex-sans-arabic/700.css'
