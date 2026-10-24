import { describe, expect, it } from 'vitest'
import vectors from '@/features/formVectors.json'
import type { FormField, FormLayout } from '@/features/hub'
import { DIAL, e164 } from './phoneCountries'
import { deriveLayout, passes, visibility } from './formLogic'

/** The page runs the very vectors the server runs: a rule means one thing on both sides of the wire. */
describe('logic vectors', () => {
  it.each(vectors.logic)('$name', (v) => {
    expect(passes(v.group as never, v.answers)).toBe(v.expect)
  })
})

describe('visibility vectors', () => {
  for (const set of vectors.visibility) {
    it.each(set.cases.map((c, i) => [i, c] as const))(`${set.name} #%i`, (_i, c) => {
      const fields = set.form.fields.map((f) => ({ hidden: false, mapping: 'none', label: f.key, ...f })) as unknown as FormField[]
      const layout = set.form.layout as unknown as FormLayout
      const withDefaults: FormLayout = { sections: layout.sections.map((s) => ({ title: null, description: null, step_title: null, show_when: null, hide_when: null, ...s, rows: s.rows.map((r) => ({ show_when: null, hide_when: null, ...r })) })) } as FormLayout
      const state = visibility(fields, withDefaults, c.answers)

      expect([...state.visible].sort()).toEqual([...c.visible].sort())
      expect([...state.required].sort()).toEqual([...c.required].sort())
    })
  }
})

describe('phone vectors', () => {
  it.each(vectors.phone)('$input ($country) → $e164', (v) => {
    expect(e164(v.input, v.country)).toBe(v.e164)
  })

  it('offers the countries the server knows, with the same dial codes', () => {
    expect(DIAL).toEqual(vectors.countries)
  })
})

describe('deriveLayout', () => {
  it('makes a section for each step and a row for each field', () => {
    const fields = [{ key: 'a', step: 1 }, { key: 'b', step: 2 }, { key: 'c', step: 2 }] as unknown as FormField[]
    const layout = deriveLayout(fields)

    expect(layout.sections).toHaveLength(2)
    expect(layout.sections[1].rows.map((r) => r.columns[0].fields[0])).toEqual(['b', 'c'])
  })
})
