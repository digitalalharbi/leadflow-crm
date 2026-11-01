import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { WebhooksCard } from './WebhooksCard'
import { renderWithProviders } from '@/test/utils'
import i18n from '@/i18n'
import { createWebhook, fetchWebhooks } from '@/features/webhooks'

vi.mock('@/features/webhooks', async (original) => ({
  ...(await original<typeof import('@/features/webhooks')>()),
  fetchWebhooks: vi.fn(),
  createWebhook: vi.fn(),
}))

const row = {
  id: 'w-1', name: 'CRM', url: 'https://hooks.example-crm.com/in', events: ['lead.created' as const], is_active: true, paused: true,
  paused_reason: 'consecutive_failures', consecutive_failures: 15, last_success_at: null, last_failure_at: '2026-11-01T10:00:00Z',
  last_error: 'The endpoint answered 500', health: 'paused' as const, created_at: '2026-11-01T09:00:00Z',
}

describe('WebhooksCard', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('ar')
    vi.mocked(fetchWebhooks).mockResolvedValue([row])
    vi.mocked(createWebhook).mockResolvedValue({ subscription: row, secret: 'whsec_onceonly' })
  })

  it('says plainly that a failing endpoint was paused, and why', async () => {
    renderWithProviders(<WebhooksCard />)

    expect(await screen.findByTestId('webhook-health-w-1')).toHaveTextContent('متوقف بعد إخفاقات')
    expect(screen.getByText('The endpoint answered 500')).toBeInTheDocument()
  })

  it('shows the signing secret once, and only accepts an https address', async () => {
    renderWithProviders(<WebhooksCard />)
    await screen.findByTestId('webhook-list')

    await userEvent.type(screen.getByTestId('webhook-name'), 'ERP')
    await userEvent.type(screen.getByTestId('webhook-url'), 'http://plain.example-crm.com')
    expect(screen.getByTestId('webhook-create')).toBeDisabled()

    await userEvent.clear(screen.getByTestId('webhook-url'))
    await userEvent.type(screen.getByTestId('webhook-url'), 'https://erp.example-crm.com/in')
    await userEvent.click(screen.getByTestId('webhook-create'))

    expect(await screen.findByTestId('webhook-secret')).toHaveTextContent('whsec_onceonly')
  })
})
