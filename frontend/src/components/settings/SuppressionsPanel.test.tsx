import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { apiDelete, apiPost } from '@/lib/api'
import { SuppressionsPanel } from './SuppressionsPanel'

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn().mockResolvedValue({
    data: [{ id: 's1', channel: 'phone', value: '+966551234567', reason: 'opt_out', note: null, created_at: null }],
    total: 1,
  }),
  apiPost: vi.fn().mockResolvedValue({ id: 's2', channel: 'email', value: 'a@b.test', reason: 'manual' }),
  apiDelete: vi.fn().mockResolvedValue(undefined),
}))

describe('the do-not-contact list', () => {
  it('lists the entries with why each is there', async () => {
    renderWithProviders(<SuppressionsPanel />)

    await waitFor(() => expect(screen.getAllByTestId('suppression-row')).toHaveLength(1))
    expect(screen.getByText('+966551234567')).toBeInTheDocument()
  })

  it('adds an address and removes an entry only after confirming', async () => {
    renderWithProviders(<SuppressionsPanel />)
    await screen.findByText('+966551234567')

    fireEvent.change(screen.getByTestId('suppression-channel'), { target: { value: 'email' } })
    fireEvent.change(screen.getByTestId('suppression-value'), { target: { value: ' a@b.test ' } })
    fireEvent.click(screen.getByTestId('suppression-add'))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/settings/suppressions', { channel: 'email', value: 'a@b.test', note: undefined }))

    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    fireEvent.click(screen.getByTestId('suppression-remove'))
    expect(apiDelete).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('suppression-remove'))
    await waitFor(() => expect(apiDelete).toHaveBeenCalledWith('/settings/suppressions/s1'))
    confirm.mockRestore()
  })
})
