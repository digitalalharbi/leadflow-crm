import { fireEvent, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiPost } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { renderWithProviders } from '@/test/utils'
import type { User } from '@/types/api'
import { ProjectLeadSources } from './ProjectLeadSources'

const base = { qualified: 1, appointments: 0, sales: 0, conversion_rate: 0, last_lead_at: null, tracking_health: 'not_applicable' }

vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  apiGet: vi.fn((url: string) => Promise.resolve(url.endsWith('/compare')
    ? { days: 30, pages: [
      { id: 'a', name: 'صفحة أ', status: 'published', path: '/l/a', views: 10, leads: 2, conversion_rate: 20, qualified: 1, appointments: 0, sales: 0, revenue_minor: 0 },
      { id: 'b', name: 'صفحة ب', status: 'published', path: '/l/b', views: 5, leads: 0, conversion_rate: 0, qualified: 0, appointments: 0, sales: 0, revenue_minor: 0 },
    ] }
    : { totals: { sources: 3, connected: 1, leads: 3, sales: 0 }, sources: [
      { ...base, id: 'p1', type: 'landing_page', name: 'رواق الرئيسية', status: 'connected', leads: 3, path: '/l/riwaq' },
      { ...base, id: 'm1', type: 'platform_form', name: 'نموذج ميتا', status: 'awaiting_setup', leads: 0, provider: 'meta' },
      { ...base, id: 'whatsapp', type: 'whatsapp', name: 'WhatsApp', status: 'awaiting_setup', leads: 0, clicks: 4 },
    ] })),
  apiPost: vi.fn().mockResolvedValue({ mapping: { id: 'x' } }),
}))

describe('Project → Lead Sources', () => {
  beforeEach(() => useAuth.setState({ user: { id: 'u', role: 'admin', permissions: ['tracking.view', 'tracking.edit'] } as unknown as User }))

  it('shows every kind of source on one list, with its state and numbers', async () => {
    renderWithProviders(<ProjectLeadSources projectId="p" projectName="رواق" />)

    await waitFor(() => expect(screen.getAllByTestId('source-card')).toHaveLength(3))
    expect(screen.getAllByTestId('source-card').map((c) => c.getAttribute('data-type'))).toEqual(['landing_page', 'platform_form', 'whatsapp'])
    expect(screen.getByText('رواق الرئيسية')).toBeInTheDocument()
    await waitFor(() => expect(screen.getAllByTestId('comparison-row')).toHaveLength(2))
  })

  it('offers every way to add a source, and ties a platform form to the project with its fields', async () => {
    renderWithProviders(<ProjectLeadSources projectId="p" projectName="رواق" />)
    fireEvent.click(await screen.findByTestId('add-source'))

    for (const key of ['landing_page', 'platform_form', 'google_sheet', 'whatsapp', 'call', 'api', 'import']) expect(screen.getByTestId(`add-${key}`)).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('add-platform_form'))
    fireEvent.change(screen.getByTestId('platform-form-id'), { target: { value: ' 123 ' } })
    fireEvent.change(screen.getByTestId('map-source-0'), { target: { value: 'Full Name' } })
    fireEvent.click(screen.getByTestId('platform-activate'))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/projects/p/lead-sources/mappings', expect.objectContaining({ provider: 'meta', form_ref: '123', field_mapping: { 'Full Name': 'full_name' } })))
  })

  it('someone who can only read cannot add', async () => {
    useAuth.setState({ user: { id: 'u', role: 'sales_manager', permissions: ['tracking.view'] } as unknown as User })
    renderWithProviders(<ProjectLeadSources projectId="p" projectName="رواق" />)

    await screen.findAllByTestId('source-card')
    expect(screen.queryByTestId('add-source')).toBeNull()
  })
})
