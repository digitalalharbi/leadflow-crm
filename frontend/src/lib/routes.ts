/**
 * The addresses the product is organised around.
 *
 * `/` is the marketing page, for everybody — signed in or not. The work starts at `/dashboard`: that is where signing
 * in lands and where "home" inside the app is. Nothing inside the app sends a person to `/` expecting their dashboard.
 */
export const HOME = '/dashboard'

/** Where signing in lands. A salesperson's day is their queue; everybody else starts from the dashboard. */
export function homeFor(user: { role?: string | null; home?: string | null } | null | undefined): string {
  return user?.home ?? (user?.role === 'sales' ? '/work' : HOME)
}

/** The official public origin: the marketing page, the login, and the canonical address search engines are told. */
export const PUBLIC_ORIGIN = (import.meta.env.VITE_PUBLIC_ORIGIN as string | undefined) ?? 'https://mohtam.pro'

/** The pages a search engine may index: the marketing page and the legal/contact pages. Everything else is the app. */
export const INDEXABLE_PATHS = ['/', '/insights', '/features', '/journey', '/vision', '/about', '/contact', '/privacy', '/terms', '/security', '/data-deletion'] as const
