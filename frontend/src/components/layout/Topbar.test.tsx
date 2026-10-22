import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { Topbar } from './Topbar'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'

/**
 * The account button shows the user's name beside the avatar on a wide screen
 * and hides it below `sm`, where the avatar is `aria-hidden` — so on a phone
 * the button had no accessible name at all.
 *
 * The assertion is on the attribute rather than on the computed name on
 * purpose: jsdom applies no media queries, so the name span is "visible" here
 * at every width and a `getByRole(name)` check passes whether the label exists
 * or not. Only the explicit attribute guarantees the name at the width where
 * it was missing.
 */
describe('Topbar', () => {
  it('labels the account button explicitly, not by text that a breakpoint hides', () => {
    useAuth.setState({
      user: {
        id: 'u1',
        name: 'عبدالله الحربي',
        email: 'a@example.test',
        role: 'admin',
        phone: null,
        avatar_url: null,
        is_active: true,
        locale: 'ar',
        last_login_at: null,
        created_at: new Date().toISOString(),
      },
      status: 'authenticated',
    })

    renderWithProviders(<Topbar />)

    expect(screen.getByTestId('account-menu')).toHaveAttribute('aria-label', 'عبدالله الحربي')
  })
})
