import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ApiKeysCard } from './ApiKeysCard'
import { renderWithProviders } from '@/test/utils'
import i18n from '@/i18n'
import { createApiKey, fetchApiKeys, revokeApiKey } from '@/features/apiKeys'

vi.mock('@/features/apiKeys', async (original) => ({
  ...(await original<typeof import('@/features/apiKeys')>()),
  fetchApiKeys: vi.fn(),
  createApiKey: vi.fn(),
  revokeApiKey: vi.fn(),
}))

const row = {
  id: 'k-1', name: 'CRM sync', prefix: 'mht_ab12cd34', scopes: ['leads:read' as const], expires_at: null,
  last_used_at: null, revoked_at: null, created_by: 'Admin', created_at: '2026-11-01T10:00:00Z', status: 'active' as const,
}

describe('ApiKeysCard', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('ar')
    vi.mocked(fetchApiKeys).mockResolvedValue([row])
    vi.mocked(createApiKey).mockResolvedValue({ key: row, plain: 'mht_ab12cd34_secretsecretsecret' })
    vi.mocked(revokeApiKey).mockResolvedValue({ ...row, status: 'revoked' })
  })

  it('shows a new key once, and only the prefix in the list', async () => {
    renderWithProviders(<ApiKeysCard />)

    expect(await screen.findByText(/mht_ab12cd34…/)).toBeInTheDocument()
    expect(screen.queryByText(/secretsecret/)).toBeNull()

    await userEvent.type(screen.getByTestId('api-key-name'), 'CRM sync')
    await userEvent.click(screen.getByTestId('api-key-create'))

    expect(await screen.findByTestId('api-key-plain')).toHaveTextContent('mht_ab12cd34_secretsecretsecret')
    expect(vi.mocked(createApiKey).mock.calls[0][0]).toEqual({ name: 'CRM sync', scopes: ['leads:read'], expires_in_days: null })

    await userEvent.click(screen.getByTestId('api-key-dismiss'))
    expect(screen.queryByText(/secretsecret/)).toBeNull()
  })

  it('will not create a key with no permissions', async () => {
    renderWithProviders(<ApiKeysCard />)
    await screen.findByTestId('api-key-list')

    await userEvent.type(screen.getByTestId('api-key-name'), 'Empty')
    await userEvent.click(screen.getByTestId('api-scope-leads:read'))

    expect(screen.getByTestId('api-key-create')).toBeDisabled()
  })

  it('revokes a key', async () => {
    renderWithProviders(<ApiKeysCard />)

    await userEvent.click(await screen.findByTestId('api-key-revoke-k-1'))

    await waitFor(() => expect(vi.mocked(revokeApiKey).mock.calls[0][0]).toBe('k-1'))
  })
})
