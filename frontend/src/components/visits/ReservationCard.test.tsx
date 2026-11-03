import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { ReservationCard } from './ReservationCard'
import * as visits from '@/features/visits'
import type { Reservation } from '@/features/visits'

const reservation = (price: number | null) => ({
  id: 'r1', status: 'confirmed', moves: ['convert', 'cancel'], lead: { id: 'l1', full_name: 'Rana' }, project: null, expires_at: null,
  property: price === null ? null : { id: 'p1', title: 'Unit 4', price },
}) as unknown as Reservation

function show(row: Reservation) {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter><ul><ReservationCard reservation={row} /></ul></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('a sale from a reservation', () => {
  it('asks for its figure, starting from the unit price', async () => {
    const act = vi.spyOn(visits, 'actOnReservation').mockResolvedValue({ reservation: reservation(500000) })
    show(reservation(500000))

    fireEvent.click(screen.getByTestId('reservation-convert'))
    expect(act).not.toHaveBeenCalled()
    expect(screen.getByTestId('reservation-deal-value')).toHaveValue(500000)

    fireEvent.click(screen.getByTestId('reservation-convert-confirm'))
    await waitFor(() => expect(act).toHaveBeenCalledWith('r1', 'convert', { deal_value: 500000 }))
  })

  it('cannot be recorded without one when the unit has no price', () => {
    show(reservation(null))
    fireEvent.click(screen.getByTestId('reservation-convert'))

    expect(screen.getByTestId('reservation-convert-confirm')).toBeDisabled()
    fireEvent.change(screen.getByTestId('reservation-deal-value'), { target: { value: '750000' } })
    expect(screen.getByTestId('reservation-convert-confirm')).toBeEnabled()
  })
})
