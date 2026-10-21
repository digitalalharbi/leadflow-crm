import { describe, expect, it } from 'vitest'
import { hasPermission, sees, seesPage } from './permissions'
import type { User } from '@/types/api'

const user = (extra: Partial<User>): User => ({ id: 'u', name: 'x', email: 'x@y.z', role: 'sales', phone: null, avatar_url: null, is_active: true, ...extra }) as User

describe('what a person sees of the interface', () => {
  it('shows everything unless the workspace has switched it off', () => {
    expect(sees(user({}), 'dashboard.kpi.revenue')).toBe(true)
    expect(sees(user({ hidden_elements: ['dashboard.kpi.revenue'] }), 'dashboard.kpi.revenue')).toBe(false)
    expect(sees(user({ hidden_elements: ['dashboard.kpi.revenue'] }), 'dashboard.kpi.response')).toBe(true)
  })

  it('shows nothing to nobody', () => {
    expect(sees(null, 'dashboard.kpi.revenue')).toBe(false)
  })

  it('switches a page off by the first part of its address', () => {
    const me = user({ hidden_elements: ['page.campaigns'] })

    expect(seesPage(me, '/campaigns')).toBe(false)
    expect(seesPage(me, '/campaigns/abc')).toBe(false)
    expect(seesPage(me, '/leads')).toBe(true)
    expect(seesPage(user({ hidden_elements: ['page.dashboard'] }), '/')).toBe(false)
  })

  it('leaves pages nobody can switch off to the permissions', () => {
    expect(seesPage(user({ hidden_elements: ['page.leads'] }), '/settings')).toBe(true)
  })

  it('still reads permissions the old way', () => {
    expect(hasPermission(user({ permissions: ['leads.view'] }), 'leads.view')).toBe(true)
    expect(hasPermission(user({ permissions: ['leads.view'] }), 'leads.edit')).toBe(false)
  })
})
