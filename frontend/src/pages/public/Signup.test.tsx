import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { PricingPage, SignupPage } from './Signup'

const api = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', async (orig) => ({ ...(await orig<typeof import('@/lib/api')>()), apiGet: api.apiGet, apiPost: api.apiPost }))

const plans = {
  plans: [
    { key: 'starter', name: 'Starter', description: null, price_monthly: '199.00', price_yearly: null, currency: 'SAR', included_seats: 3, features: ['tracking_hub'], priced: true },
    { key: 'custom', name: 'Custom', description: null, price_monthly: null, price_yearly: null, currency: 'SAR', included_seats: null, features: [], priced: false },
  ],
  trial_days: 14,
}

describe('the sales funnel pages', () => {
  beforeEach(() => { api.apiGet.mockReset(); api.apiPost.mockReset() })

  it('shows a set price, and says contact-us for a plan with none', async () => {
    api.apiGet.mockResolvedValue(plans)
    renderWithProviders(<PricingPage />)

    expect(await screen.findByTestId('plan-price-starter')).toHaveTextContent(/199|١٩٩/)
    expect(screen.getByTestId('plan-price-custom')).not.toHaveTextContent(/\d/)
    expect(screen.getByTestId('plan-choose-starter')).toHaveAttribute('href', '/signup?plan=starter')
  })

  it('says so when the plans cannot be read', async () => {
    api.apiGet.mockRejectedValue(new Error('down'))
    renderWithProviders(<PricingPage />)

    expect(await screen.findByTestId('pricing-failed')).toBeInTheDocument()
  })

  it('a company asks for a trial, which goes in as a demo lead, and is told it was received', async () => {
    api.apiGet.mockImplementation(async (path: string) => (path === '/public/contact' ? { form: true } : plans))
    api.apiPost.mockResolvedValue(null)
    const user = userEvent.setup()
    renderWithProviders(<SignupPage />)

    await screen.findByTestId('signup-form')
    const [company, name, email] = screen.getAllByRole('textbox').filter((el) => el.getAttribute('tabindex') !== '-1')
    await user.type(company, 'Palm Developments')
    await user.type(name, 'Sara')
    await user.type(email, 'sara@palm-dev.sa')
    expect(screen.getByTestId('signup-submit')).toBeDisabled()
    await user.click(screen.getByTestId('signup-consent'))
    await user.click(screen.getByTestId('signup-submit'))

    await waitFor(() => expect(api.apiPost).toHaveBeenCalledWith('/public/contact', expect.objectContaining({ kind: 'demo', company: 'Palm Developments', email: 'sara@palm-dev.sa', consent: true })))
    expect(api.apiPost).not.toHaveBeenCalledWith('/public/signup', expect.anything())
    expect(await screen.findByTestId('signup-sent')).toBeInTheDocument()
  })

  it('offers contact instead of the form when the request form is switched off', async () => {
    api.apiGet.mockImplementation(async (path: string) => (path === '/public/contact' ? { form: false } : plans))
    renderWithProviders(<SignupPage />)

    expect(await screen.findByTestId('signup-closed')).toBeInTheDocument()
    expect(screen.queryByTestId('signup-form')).toBeNull()
  })
})
