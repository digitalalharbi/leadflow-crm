import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AcceptInviteRedirect, ResetPasswordRedirect, VerifyEmailPage } from './AccountAccess'
import { homeFor } from '@/lib/routes'
import * as api from '@/lib/api'

function at(path: string, element: React.ReactNode) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={path.split('?')[0]} element={element} />
        <Route path="/set-password/:token" element={<p>set password page</p>} />
        <Route path="/forgot-password" element={<p>forgot page</p>} />
        <Route path="/invite/:token" element={<p>invitation page</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('the plain names of the account links', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('confirms an address from its link', async () => {
    const post = vi.spyOn(api, 'apiPost').mockResolvedValue({ verified: true })
    at('/verify-email?token=abc.def', <VerifyEmailPage />)

    // Opening the link does nothing by itself: a mail scanner opens it too.
    expect(post).not.toHaveBeenCalled()
    fireEvent.change(screen.getByTestId('verify-password'), { target: { value: 'my-password' } })
    fireEvent.click(screen.getByTestId('verify-submit'))

    await waitFor(() => expect(screen.getByTestId('verify-done')).toBeInTheDocument())
    expect(post).toHaveBeenCalledWith('/auth/email/verify', { token: 'abc.def', password: 'my-password' })
  })

  it('says so when a link is no good, and does not call the server without one', async () => {
    const post = vi.spyOn(api, 'apiPost').mockRejectedValue(new Error('no'))
    at('/verify-email', <VerifyEmailPage />)

    expect(screen.getByTestId('verify-invalid')).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })

  it('sends a reset address to the page its token opens, or to asking for a new one', () => {
    at('/reset-password?token=t0k', <ResetPasswordRedirect />)
    expect(screen.getByText('set password page')).toBeInTheDocument()
  })

  it('has somewhere useful to go without a token', () => {
    at('/reset-password', <ResetPasswordRedirect />)
    expect(screen.getByText('forgot page')).toBeInTheDocument()
  })

  it('opens the invitation from its token, and explains itself without one', () => {
    at('/accept-invite?token=t0k', <AcceptInviteRedirect />)
    expect(screen.getByText('invitation page')).toBeInTheDocument()
  })

  it('lands a salesperson on their queue and everyone else on the dashboard', () => {
    expect(homeFor({ role: 'sales' })).toBe('/work')
    expect(homeFor({ role: 'admin' })).toBe('/dashboard')
    expect(homeFor(null)).toBe('/dashboard')
  })
})
