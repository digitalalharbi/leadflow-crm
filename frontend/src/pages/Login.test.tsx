import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LoginPage } from './Login'
import * as api from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { renderWithProviders } from '@/test/utils'

function typeCredentials() {
  fireEvent.change(screen.getByLabelText('البريد الإلكتروني'), { target: { value: 'a@x.test' } })
  fireEvent.change(screen.getByLabelText('كلمة المرور', { exact: true }), { target: { value: 'a-password' } })
}

describe('signing in, one step at a time', () => {
  const login = vi.fn()

  beforeEach(() => {
    login.mockReset()
    useAuth.setState({ login, status: 'guest' } as never)
    vi.spyOn(api, 'apiGet').mockImplementation(async (path: string) => (path === '/auth/workspaces' ? { workspaces: [] } : path === '/auth/providers' ? { providers: [] } : null) as never)
  })

  afterEach(() => vi.restoreAllMocks())

  it('sends what remember me says', async () => {
    login.mockResolvedValue('ok')
    renderWithProviders(<LoginPage />)
    typeCredentials()
    fireEvent.click(screen.getByTestId('login-remember'))
    fireEvent.click(screen.getByRole('button', { name: 'تسجيل الدخول' }))

    await waitFor(() => expect(login).toHaveBeenCalledWith(expect.objectContaining({ email: 'a@x.test', remember: false })))
  })

  it('asks for the code in the same card, and Back returns with what was typed', async () => {
    login.mockResolvedValueOnce('mfa').mockResolvedValueOnce('mfa').mockResolvedValueOnce('ok')
    renderWithProviders(<LoginPage />)
    typeCredentials()
    fireEvent.click(screen.getByRole('button', { name: 'تسجيل الدخول' }))

    await screen.findByTestId('login-mfa-code')
    expect(screen.getByTestId('page-title')).toHaveTextContent('التحقق بخطوتين')
    fireEvent.click(screen.getByTestId('login-mfa-back'))
    expect(screen.getByLabelText('البريد الإلكتروني')).toHaveValue('a@x.test')

    fireEvent.click(screen.getByRole('button', { name: 'تسجيل الدخول' }))
    fireEvent.change(await screen.findByTestId('login-mfa-code'), { target: { value: '123456' } })
    fireEvent.click(screen.getByTestId('login-mfa-submit'))

    await waitFor(() => expect(login).toHaveBeenLastCalledWith(expect.objectContaining({ code: '123456', password: 'a-password' })))
  })

  it('offers a choice only when there is one', async () => {
    login.mockResolvedValue('ok')
    vi.mocked(api.apiGet).mockImplementation(async (path: string) => (path === '/auth/workspaces' ? { workspaces: [{ id: 'one', name: 'شركة أ', role: 'admin', current: true }, { id: 'two', name: 'شركة ب', role: 'sales', current: false }] } : { providers: [] }) as never)
    const post = vi.spyOn(api, 'apiPost').mockResolvedValue({ mfa_required: true })
    renderWithProviders(<LoginPage />)
    typeCredentials()
    fireEvent.click(screen.getByRole('button', { name: 'تسجيل الدخول' }))

    await screen.findByTestId('login-workspaces')
    fireEvent.click(screen.getByTestId('login-workspace-two'))

    // The other company has its own second factor: the server says so, and the code goes to the server.
    await screen.findByTestId('login-workspace-code')
    expect(post).toHaveBeenCalledWith('/auth/workspaces/two/switch', undefined)
  })

  it('draws a provider only when the server names one', async () => {
    vi.mocked(api.apiGet).mockImplementation(async (path: string) => (path === '/auth/providers' ? { providers: [{ key: 'google', label: 'Google', url: '/x' }] } : null) as never)
    renderWithProviders(<LoginPage />)

    expect(await screen.findByTestId('login-providers')).toHaveTextContent('Google')
  })

  it('shows Google after the form, with or between them, only when offered', async () => {
    vi.mocked(api.apiGet).mockImplementation(async (path: string) => (path === '/auth/providers' ? { providers: [{ key: 'google', label: 'Google', url: '/api/v1/auth/google/redirect' }] } : null) as never)
    renderWithProviders(<LoginPage />)

    const google = await screen.findByTestId('login-provider-google')
    expect(google).toHaveAttribute('href', '/api/v1/auth/google/redirect')
    expect(google).toHaveTextContent('المتابعة باستخدام Google')
    expect(screen.getByRole('button', { name: 'تسجيل الدخول' }).compareDocumentPosition(google) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(screen.getByTestId('login-providers')).toHaveTextContent('أو')
  })

  it('finishes a return from Google in the same card, asking for the code when the account has one', async () => {
    const loginGoogle = vi.fn().mockResolvedValueOnce('mfa').mockResolvedValueOnce('ok')
    useAuth.setState({ loginGoogle } as never)
    window.location.hash = '#google=abc'
    renderWithProviders(<LoginPage />)

    fireEvent.change(await screen.findByTestId('login-mfa-code'), { target: { value: '123456' } })
    expect(window.location.hash).toBe('')
    fireEvent.click(screen.getByTestId('login-mfa-submit'))

    await waitFor(() => expect(loginGoogle).toHaveBeenLastCalledWith(expect.objectContaining({ ticket: 'abc', code: '123456' })))
  })

  it('says why Google was refused', async () => {
    renderWithProviders(<LoginPage />, { route: '/login?google_error=no_account' })

    expect(await screen.findByRole('alert')).toHaveTextContent('لا يوجد حساب بهذا البريد')
  })
})
