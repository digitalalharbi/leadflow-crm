import { execSync } from 'node:child_process'

/** Builds version 2 of the app on top of the running version 1: the update spec's "a new release shipped". */
export function rebuild(label: string) {
  execSync('npx vite build --outDir dist-pwa', {
    stdio: process.env.E2E_VERBOSE ? 'inherit' : 'pipe',
    env: { ...process.env, VITE_BUILD_LABEL: label },
    timeout: 300_000,
  })
}

/**
 * A second copy of the built app on its own port, which a test can switch off and on: the only honest
 * way to make "the server is unreachable" true for a service worker (`context.setOffline` does not
 * reach requests the worker makes itself).
 */
export async function previewServer(port: number) {
  const { spawn } = await import('node:child_process')
  const url = `http://127.0.0.1:${port}`
  let child: ReturnType<typeof spawn> | null = null

  const up = async () => {
    for (let i = 0; i < 100; i++) {
      try {
        if ((await fetch(`${url}/offline.html`)).ok) return
      } catch {
        // not listening yet
      }
      await new Promise((r) => setTimeout(r, 200))
    }
    throw new Error(`preview server on ${port} did not start`)
  }

  const start = async () => {
    child = spawn('npx', ['vite', 'preview', '--outDir', 'dist-pwa', '--port', String(port), '--host', '127.0.0.1', '--strictPort'], {
      stdio: 'ignore',
      detached: true,
      env: { ...process.env, VITE_PROXY_TARGET: process.env.E2E_API_URL ?? 'http://127.0.0.1:8000' },
    })
    await up()
  }

  const stop = async () => {
    if (!child?.pid) return
    try {
      process.kill(-child.pid, 'SIGKILL')
    } catch {
      // already gone
    }
    child = null
    for (let i = 0; i < 50; i++) {
      try {
        await fetch(`${url}/offline.html`)
      } catch {
        return
      }
      await new Promise((r) => setTimeout(r, 100))
    }
  }

  await start()

  return { url, start, stop }
}
