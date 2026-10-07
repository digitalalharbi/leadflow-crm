import { describe, expect, it, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import { LeadTimeline } from './LeadTimeline'
import { renderWithProviders } from '@/test/utils'
import i18n from '@/i18n'
import type { LeadActivity } from '@/types/api'

/**
 * Who did it — including when the answer is "nobody".
 *
 * An automated change carries no user, so the line started at the timestamp and
 * a stage moved by a rule read exactly like a stage moved by a colleague. The
 * backend has marked these `by: automation` all along; the interface never
 * showed it, which left the first question anybody asks about an automatic
 * change — which rule did this? — answerable only by opening the automation log
 * and matching timestamps by eye.
 */
function activity(overrides: Partial<LeadActivity> = {}): LeadActivity {
  return {
    id: 'a-1',
    type: 'status_changed',
    description: null,
    meta: { from: 'contacted', to: 'qualified' },
    user: null,
    created_at: new Date().toISOString(),
    ...overrides,
  }
}

describe('LeadTimeline attribution', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('ar')
  })

  it('names the rule behind an automated change', () => {
    renderWithProviders(
      <LeadTimeline
        activities={[activity({ meta: { from: 'contacted', to: 'qualified', by: 'automation', rule_name: 'ترقية المهتمين' } })]}
      />,
    )

    expect(screen.getByText(/قاعدة: ترقية المهتمين/)).toBeInTheDocument()
  })

  /** A rule deleted since, or one that ran before names were carried. */
  it('still says it was automatic when the rule is not named', () => {
    renderWithProviders(
      <LeadTimeline activities={[activity({ meta: { by: 'automation' } })]} />,
    )

    expect(screen.getByText(/الأتمتة/)).toBeInTheDocument()
  })

  it('credits a person by name', () => {
    renderWithProviders(
      <LeadTimeline activities={[activity({ user: { id: 'u-1', name: 'سارة' } })]} />,
    )

    expect(screen.getByText(/سارة/)).toBeInTheDocument()
    expect(screen.queryByText(/الأتمتة/)).not.toBeInTheDocument()
  })

  /** Most entries are neither, and must not be labelled as automatic. */
  it('says nothing about an entry that carries no actor and no marker', () => {
    renderWithProviders(<LeadTimeline activities={[activity({ meta: null })]} />)

    expect(screen.queryByText(/الأتمتة/)).not.toBeInTheDocument()
    expect(screen.queryByText(/قاعدة:/)).not.toBeInTheDocument()
  })
})
