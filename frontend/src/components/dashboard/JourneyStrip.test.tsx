import { screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithProviders } from '@/test/utils'
import { useAuth } from '@/stores/auth'
import type { User } from '@/types/api'
import { JourneyStrip } from './JourneyStrip'

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn().mockResolvedValue({
    days: 30,
    steps: ['arrived', 'automated', 'reached', 'followed', 'waiting', 'closed'].map((key, index) => ({ key, count: 10 - index })),
  }),
}))

const asUser = (permissions: string[]) => useAuth.setState({ user: { id: 'u', role: 'sales', permissions } as unknown as User })

describe('the journey strip', () => {
  beforeEach(() => asUser([]))

  it('shows the automation step to somebody who may not open the rules, but not as a link to a screen they cannot reach', async () => {
    const { container } = renderWithProviders(<JourneyStrip days={30} />)

    await waitFor(() => expect(screen.getByTestId('journey-automated')).toBeInTheDocument())
    expect(container.querySelector('a[href="/automation"]')).toBeNull()
    // Every other step still opens its screen.
    expect(container.querySelector('a[href="/leads"]')).not.toBeNull()
    expect(container.querySelector('a[href="/work"]')).not.toBeNull()
  })

  it('links the automation step for somebody who holds automation.view', async () => {
    asUser(['automation.view'])
    const { container } = renderWithProviders(<JourneyStrip days={30} />)

    await waitFor(() => expect(container.querySelector('a[href="/automation"]')).not.toBeNull())
  })
})
