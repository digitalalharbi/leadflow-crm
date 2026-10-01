import { describe, expect, it, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { LeadFormModal } from './LeadFormModal'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { ApiError } from '@/lib/api'
import i18n from '@/i18n'
import type { User } from '@/types/api'

vi.mock('@/features/leads', () => ({
  createLead: vi.fn(),
  fetchProjects: vi.fn().mockResolvedValue([]),
  fetchAssignableUsers: vi.fn().mockResolvedValue([]),
}))

const { createLead } = await import('@/features/leads')

const admin: User = {
  id: 'u-1', name: 'Admin', email: 'a@b.test', role: 'admin',
  phone: null, avatar_url: null, is_active: true, locale: 'ar',
  last_login_at: null, created_at: new Date().toISOString(),
}

describe('LeadFormModal', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('ar')
    useAuth.setState({ user: admin, status: 'authenticated' })
    useUi.setState({ toasts: [] })
  })

  it('submits the lead and reports success', async () => {
    vi.mocked(createLead).mockResolvedValueOnce({ lead: {} as never, duplicate: false })
    const onClose = vi.fn()
    const user = userEvent.setup()

    renderWithProviders(<LeadFormModal open onClose={onClose} />)

    await user.type(screen.getByLabelText(/الاسم/), 'تركي الدوسري')
    await user.type(screen.getByPlaceholderText('05xxxxxxxx'), '0501234567')
    await user.click(screen.getByRole('button', { name: 'إنشاء' }))

    await waitFor(() => expect(createLead).toHaveBeenCalled())

    expect(vi.mocked(createLead).mock.calls[0][0]).toMatchObject({
      full_name: 'تركي الدوسري',
      phone: '0501234567',
    })
    await waitFor(() => expect(onClose).toHaveBeenCalled())
  })

  it('explains a duplicate instead of showing it as an error', async () => {
    vi.mocked(createLead).mockResolvedValueOnce({ lead: {} as never, duplicate: true })
    const user = userEvent.setup()

    renderWithProviders(<LeadFormModal open onClose={vi.fn()} />)

    await user.type(screen.getByLabelText(/الاسم/), 'سعود')
    await user.type(screen.getByPlaceholderText('05xxxxxxxx'), '0501234567')
    await user.click(screen.getByRole('button', { name: 'إنشاء' }))

    await waitFor(() => {
      const toasts = useUi.getState().toasts
      expect(toasts).toHaveLength(1)
      expect(toasts[0].variant).toBe('info')
      expect(toasts[0].message).toContain('مسجّل مسبقاً')
    })
  })

  it('shows a server validation error next to the field it belongs to', async () => {
    vi.mocked(createLead).mockRejectedValueOnce(
      new ApiError('validation', 422, { phone: ['رقم الجوال غير صالح'] }),
    )
    const user = userEvent.setup()

    renderWithProviders(<LeadFormModal open onClose={vi.fn()} />)

    await user.type(screen.getByLabelText(/الاسم/), 'سعود')
    await user.type(screen.getByPlaceholderText('05xxxxxxxx'), '12')
    await user.click(screen.getByRole('button', { name: 'إنشاء' }))

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('رقم الجوال غير صالح')
    })
  })

  it('hides the assignee picker from a sales rep', () => {
    useAuth.setState({ user: { ...admin, role: 'sales' } })

    renderWithProviders(<LeadFormModal open onClose={vi.fn()} />)

    expect(screen.queryByText('اختر موظف مبيعات')).not.toBeInTheDocument()
  })
})
