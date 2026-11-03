import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const backendDir = process.env.E2E_BACKEND_DIR ?? path.resolve(here, '../../../backend')

/**
 * Resets the fixtures to their known state.
 *
 * Called from a spec's beforeAll when that spec mutates data, so a failed run
 * never leaves the next one working from a half-changed database. Specs that
 * only read can skip it and stay fast.
 */
export function resetFixtures(): void {
  execFileSync('php', ['artisan', 'leadflow:seed-e2e'], {
    cwd: backendDir,
    stdio: process.env.E2E_VERBOSE ? 'inherit' : 'pipe',
    timeout: 120_000,
    env: {
      ...process.env,
      // Fixture passwords do not need production-strength hashing, and the cost
      // is paid again on every sign-in. Dropping the rounds takes a reset from
      // ~3.6s to ~0.7s, which is the difference between a suite people run and
      // one they skip.
      BCRYPT_ROUNDS: '4',
    },
  })
}

/** Runs an artisan command and returns its stdout — used for one-off lookups. */
export function artisan(args: string[], env: Record<string, string> = {}): string {
  return execFileSync('php', ['artisan', ...args], {
    cwd: backendDir,
    encoding: 'utf8',
    timeout: 60_000,
    // `env` lets a spec run a command with the queue inline (QUEUE_CONNECTION=sync), so the
    // notifications it sends are written before it returns rather than left for a worker.
    env: { ...process.env, ...env },
  })
}

/**
 * A platform owner for the console's specs. The password travels in the environment, never on a command line, the same
 * rule the real bootstrap keeps. The command refuses to run in production.
 */
export function ensurePlatformOwner(email: string, password: string): void {
  artisan(['leadflow:e2e-platform-owner', email], { E2E_PLATFORM_OWNER_PASSWORD: password })
}

/**
 * The link an account email carried (an invitation, a password setup or reset), read out of the email the app
 * queued — as the person would click it. Nothing in the API ever returns these tokens.
 */
export function linkFromEmail(email: string, path: 'invite' | 'set-password'): string {
  const address = JSON.stringify(email.toLowerCase()).replace(/"/g, "'")
  // Tests run with no mail provider, which would mark an invitation as handed over by hand. Read from the email, it came through the inbox.
  const delivered = path === 'invite' ? `App\\Models\\Invitation::withoutGlobalScopes()->where('email', ${address})->update(['manual_link_at' => null]);` : ''
  const php = `${delivered} echo App\\Models\\EmailDelivery::withoutGlobalScopes()->where('to_email', ${address})->latest('id')->value('html');`
  const html = execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], { cwd: backendDir, encoding: 'utf8', timeout: 60_000, input: '' })
  // An invitation is emailed as the short /i/ link; the long /invite/ form is still read for older mail.
  const prefix = path === 'invite' ? '(?:invite|i)/' : 'reset-password\\?token='
  const match = html.match(new RegExp(`/${prefix}([A-Za-z0-9]{48})`))

  if (!match) throw new Error(`No ${path} link was emailed to ${email}`)

  return match[1]
}
