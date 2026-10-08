import { beforeEach, describe, expect, it, vi } from 'vitest'
import { can, useAuth } from './auth'
import { tokenStorage } from '@/lib/api'
import type { User } from '@/types/api'

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')

  return {
    ...actual,
    apiGet: vi.fn(),
    apiPost: vi.fn(),
  }
})

const { apiGet, apiPost } = await import('@/lib/api')

function makeUser(role: User['role']): User {
  return {
    id: 'u-1',
    name: 'Test User',
    email: 'user@example.test',
    role,
    phone: null,
    avatar_url: null,
    is_active: true,
    locale: 'ar',
    last_login_at: null,
    created_at: new Date().toISOString(),
  }
}

describe('auth store', () => {
  beforeEach(() => {
    useAuth.setState({
      user: null,
      branding: null,
      isSuperAdmin: false,
      status: 'idle',
    })
    tokenStorage.clear()
  })

  it('stores the token and user after a successful login', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      token: 'tok-123',
      user: makeUser('admin'),
      branding: null,
      is_super_admin: false,
    } as never)

    await useAuth.getState().login({ email: 'a@b.test', password: 'secret' })

    expect(tokenStorage.get()).toBe('tok-123')
    expect(useAuth.getState().status).toBe('authenticated')
    expect(useAuth.getState().user?.role).toBe('admin')
  })

  it('goes straight to anonymous when there is no stored token', async () => {
    await useAuth.getState().restore()

    expect(apiGet).not.toHaveBeenCalled()
    expect(useAuth.getState().status).toBe('anonymous')
  })

  it('clears a stale token when /me rejects it', async () => {
    tokenStorage.set('stale-token')
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('unauthenticated'))

    await useAuth.getState().restore()

    expect(tokenStorage.get()).toBeNull()
    expect(useAuth.getState().status).toBe('anonymous')
    expect(useAuth.getState().user).toBeNull()
  })

  it('ends the local session even if the logout request fails', async () => {
    tokenStorage.set('tok-123')
    useAuth.setState({ user: makeUser('sales'), status: 'authenticated' })
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('network'))

    await useAuth.getState().logout()

    expect(tokenStorage.get()).toBeNull()
    expect(useAuth.getState().status).toBe('anonymous')
  })
})

describe('role capabilities', () => {
  it('lets an admin manage everything', () => {
    const admin = makeUser('admin')

    expect(can.manageTenant(admin)).toBe(true)
    expect(can.manageUsers(admin)).toBe(true)
    expect(can.manageIntegrations(admin)).toBe(true)
    expect(can.seeAllLeads(admin)).toBe(true)
  })

  it('lets a manager run the team but not the tenant', () => {
    const manager = makeUser('manager')

    expect(can.seeAllLeads(manager)).toBe(true)
    expect(can.assignLeads(manager)).toBe(true)
    expect(can.viewReports(manager)).toBe(true)
    expect(can.manageTenant(manager)).toBe(false)
    expect(can.manageUsers(manager)).toBe(false)
    expect(can.manageIntegrations(manager)).toBe(false)
  })

  /**
   * Running the team and editing an account are two authorities, and the one a
   * manager has is the narrower of them: they say how their team's leads are
   * shared out, and nobody — manager included — awards themselves a bigger
   * share. Mirrors UserPolicy::routeWork.
   */
  it('lets a manager share out their team\'s leads but not their own', () => {
    const manager = makeUser('manager')
    const rep = { ...makeUser('sales'), id: 'u-2' }

    expect(can.routeWork(manager, rep)).toBe(true)
    expect(can.routeWork(manager, manager)).toBe(false)
  })

  it('lets an admin share out anyone\'s leads, their own included', () => {
    const admin = makeUser('admin')
    const rep = { ...makeUser('sales'), id: 'u-2' }

    expect(can.routeWork(admin, rep)).toBe(true)
    expect(can.routeWork(admin, admin)).toBe(true)
  })

  it('never lets a rep decide their own share', () => {
    const sales = makeUser('sales')
    const other = { ...makeUser('sales'), id: 'u-2' }

    expect(can.routeWork(sales, sales)).toBe(false)
    expect(can.routeWork(sales, other)).toBe(false)
    expect(can.routeWork(null, other)).toBe(false)
  })

  it('restricts a sales rep to their own leads', () => {
    const sales = makeUser('sales')

    expect(can.seeAllLeads(sales)).toBe(false)
    expect(can.assignLeads(sales)).toBe(false)
    expect(can.viewReports(sales)).toBe(false)
    expect(can.manageUsers(sales)).toBe(false)
    expect(can.manageIntegrations(sales)).toBe(false)
  })

  it('treats a missing user as having no permissions', () => {
    expect(can.seeAllLeads(null)).toBe(false)
    expect(can.manageTenant(null)).toBe(false)
  })
})
