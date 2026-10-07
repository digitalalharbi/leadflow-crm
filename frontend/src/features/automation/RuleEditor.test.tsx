import { describe, expect, it, beforeEach, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { RuleEditor } from './RuleEditor'
import { renderWithProviders } from '@/test/utils'
import i18n from '@/i18n'
import type { AutomationSchema } from '@/features/automation'

/**
 * The condition value, which is the one input a manager cannot guess.
 *
 * `last_outcome` matches on internal strings — `no_answer`, `meeting_booked`.
 * A free text box asks somebody to spell one from memory, and a rule written as
 * "لم يرد" saves, runs, matches nothing and reports no error: every screen says
 * it is fine, so there is nothing to debug from.
 */
const schema: AutomationSchema = {
  triggers: ['lead.outcome_recorded', 'lead.created'],
  fields: ['last_outcome', 'city'],
  operators: ['equals', 'in'],
  condition_matches: ['all', 'any'],
  actions: ['add_tag'],
  stages: ['new', 'contacted'],
  field_values: { last_outcome: ['interested', 'no_answer'] },
}

function render(onSave = vi.fn()) {
  renderWithProviders(
    <RuleEditor rule={null} schema={schema} saving={false} onCancel={vi.fn()} onSave={onSave} />,
  )

  return onSave
}

describe('RuleEditor', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('ar')
  })

  it('offers a field with known values as a list rather than a text box', async () => {
    const user = userEvent.setup()
    render()

    await user.click(screen.getByTestId('add-condition'))

    const value = screen.getByLabelText('القيمة')
    expect(value.tagName).toBe('SELECT')
    expect(screen.getByRole('option', { name: 'لم يرد' })).toBeInTheDocument()
  })

  /**
   * A select has no empty option, so a condition nobody touched would display
   * its first value and save none — the rule then matches on `''` forever.
   */
  it('saves the value it is showing even when nobody touches it', async () => {
    const user = userEvent.setup()
    const onSave = render()

    await user.click(screen.getByTestId('add-condition'))
    await user.type(screen.getByTestId('rule-name'), 'قاعدة')
    await user.click(screen.getByTestId('save-rule'))

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({
        conditions: [{ field: 'last_outcome', operator: 'equals', value: 'interested' }],
      }),
    )
  })

  /** The old value belongs to the old field, and carrying it over is worse than
   *  losing it: the rule would hold an outcome while naming a city. */
  it('drops the value when the field changes to one with no list', async () => {
    const user = userEvent.setup()
    const onSave = render()

    await user.click(screen.getByTestId('add-condition'))
    await user.selectOptions(screen.getByLabelText('الحقل'), 'city')

    expect(screen.getByLabelText('القيمة').tagName).toBe('INPUT')

    await user.type(screen.getByTestId('rule-name'), 'قاعدة')
    await user.click(screen.getByTestId('save-rule'))

    expect(onSave).toHaveBeenCalledWith(
      expect.objectContaining({
        conditions: [{ field: 'city', operator: 'equals', value: '' }],
      }),
    )
  })

  /**
   * The toggle appears only once there are two conditions: with one it is a
   * choice between two identical outcomes, and a control that changes nothing
   * is still a thing somebody has to stop and read.
   */
  it('offers no match toggle until there are two conditions', async () => {
    const user = userEvent.setup()
    render()

    await user.click(screen.getByTestId('add-condition'))
    expect(screen.queryByTestId('condition-match')).not.toBeInTheDocument()

    await user.click(screen.getByTestId('add-condition'))
    expect(screen.getByTestId('condition-match')).toBeInTheDocument()
  })

  it('saves a rule that needs only one of its conditions', async () => {
    const user = userEvent.setup()
    const onSave = render()

    await user.click(screen.getByTestId('add-condition'))
    await user.click(screen.getByTestId('add-condition'))
    await user.selectOptions(screen.getByTestId('condition-match'), 'any')
    await user.type(screen.getByTestId('rule-name'), 'قاعدة')
    await user.click(screen.getByTestId('save-rule'))

    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ condition_match: 'any' }))
  })

  /** Every rule written before the toggle existed means what it always meant. */
  it('sends all when nobody chose', async () => {
    const user = userEvent.setup()
    const onSave = render()

    await user.click(screen.getByTestId('add-condition'))
    await user.type(screen.getByTestId('rule-name'), 'قاعدة')
    await user.click(screen.getByTestId('save-rule'))

    expect(onSave).toHaveBeenCalledWith(expect.objectContaining({ condition_match: 'all' }))
  })

  /** `in` takes several values, so it keeps the text box and its hint. */
  it('keeps a text box for an operator that takes a list', async () => {
    const user = userEvent.setup()
    render()

    await user.click(screen.getByTestId('add-condition'))
    await user.selectOptions(screen.getByLabelText('المقارنة'), 'in')

    // The hint rides along in the accessible name, so match on the label text.
    expect(screen.getByLabelText(/القيمة/).tagName).toBe('INPUT')
    expect(screen.getByText('افصل بينها بفاصلة')).toBeInTheDocument()
  })
})
