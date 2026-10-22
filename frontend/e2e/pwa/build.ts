import { execSync } from 'node:child_process'

/** Builds the production bundle the PWA specs serve. Version "v1"; the update spec builds a "v2" on top of it. */
export default function globalSetup() {
  if (process.env.PWA_SKIP_BUILD) return

  execSync('npx vite build --outDir dist-pwa --emptyOutDir', {
    stdio: process.env.E2E_VERBOSE ? 'inherit' : 'pipe',
    env: { ...process.env, VITE_BUILD_LABEL: 'v1' },
    timeout: 300_000,
  })
}

export function rebuild(label: string) {
  execSync('npx vite build --outDir dist-pwa', {
    stdio: process.env.E2E_VERBOSE ? 'inherit' : 'pipe',
    env: { ...process.env, VITE_BUILD_LABEL: label },
    timeout: 300_000,
  })
}
