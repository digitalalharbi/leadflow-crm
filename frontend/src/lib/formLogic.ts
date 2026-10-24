import type { FormField, FormLayout, LogicGroup } from '@/features/hub'

/**
 * A condition on a form, on the page — the same rules, to the letter, as the server's `Logic` and `FormLayout`: both run
 * `features/formVectors.json`. A field the page showed and the server then refused, or one it hid and the server then demanded,
 * would be a customer lost; so there is one definition and two implementations that are tested against each other.
 */
type Answers = Record<string, unknown>

const isEmpty = (v: unknown): boolean => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)

const listOf = (v: unknown[]) => v.map((x) => (typeof x === 'string' || typeof x === 'number' || typeof x === 'boolean' ? String(x) : ''))

function same(answer: unknown, value: unknown): boolean {
  if (isEmpty(answer)) return false
  if (Array.isArray(answer)) return listOf(answer).includes(String(value))

  return (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') && String(answer) === String(value)
}

function contains(answer: unknown, value: unknown): boolean {
  if (typeof value !== 'string' && typeof value !== 'number') return false
  if (String(value) === '' || isEmpty(answer)) return false
  if (Array.isArray(answer)) return listOf(answer).includes(String(value))

  return (typeof answer === 'string' || typeof answer === 'number') && String(answer).includes(String(value))
}

function compare(answer: unknown, value: unknown, cmp: (a: number, b: number) => boolean): boolean {
  const num = (x: unknown) => (typeof x === 'number' ? x : typeof x === 'string' && x.trim() !== '' && !Number.isNaN(Number(x)) ? Number(x) : null)
  const a = typeof answer === 'boolean' ? null : num(answer)
  const b = num(value)

  return a !== null && b !== null && cmp(a, b)
}

function holds(c: LogicGroup['conditions'][number], answers: Answers): boolean {
  const answer = answers[c.field]

  switch (c.op) {
    case 'equals': return same(answer, c.value)
    case 'not_equals': return !same(answer, c.value)
    case 'contains': return contains(answer, c.value)
    case 'gt': return compare(answer, c.value, (a, b) => a > b)
    case 'gte': return compare(answer, c.value, (a, b) => a >= b)
    case 'lt': return compare(answer, c.value, (a, b) => a < b)
    case 'lte': return compare(answer, c.value, (a, b) => a <= b)
    case 'empty': return isEmpty(answer)
    case 'not_empty': return !isEmpty(answer)
    default: return false
  }
}

/** Does the group hold for these answers? No group, or no conditions, is "nothing to wait for". */
export function passes(group: LogicGroup | null | undefined, answers: Answers): boolean {
  if (!group || group.conditions.length === 0) return true
  const results = group.conditions.map((c) => holds(c, answers))

  return group.match === 'any' ? results.includes(true) : !results.includes(false)
}

interface Conditional { show_when?: LogicGroup | null; hide_when?: LogicGroup | null; require_when?: LogicGroup | null }

const shows = (thing: Conditional, answers: Answers) =>
  (!thing.show_when || passes(thing.show_when, answers)) && (!thing.hide_when || !passes(thing.hide_when, answers))

export const DISPLAY_TYPES = ['heading', 'paragraph', 'divider']

/**
 * What is shown and what is required, for these answers. A hidden field's answer does not count, so a field that waits on one goes
 * too — repeated until nothing changes (a field may wait on one further down the page).
 */
export function visibility(fields: FormField[], layout: FormLayout, answers: Answers): { visible: string[]; required: string[] } {
  const byKey = new Map(fields.map((f) => [f.key, f]))
  const asked = fields.filter((f) => !f.hidden && f.type !== 'hidden').map((f) => f.key)
  let visible = asked

  for (let pass = 0; pass < 8; pass++) {
    const effective = Object.fromEntries(Object.entries(answers).filter(([k]) => visible.includes(k)))
    const next: string[] = []

    for (const s of layout.sections) {
      const sectionShown = shows(s, effective)

      for (const r of s.rows) {
        const rowShown = sectionShown && shows(r, effective)

        for (const c of r.columns) {
          for (const key of c.fields) {
            const f = byKey.get(key)
            if (rowShown && f && !f.hidden && f.type !== 'hidden' && shows(f, effective)) next.push(key)
          }
        }
      }
    }

    const stable = [...next].sort().join('|') === [...visible].sort().join('|')
    visible = next
    if (stable) break
  }

  const effective = Object.fromEntries(Object.entries(answers).filter(([k]) => visible.includes(k)))
  const required = visible.filter((k) => {
    const f = byKey.get(k)!

    return !DISPLAY_TYPES.includes(f.type) && (f.required || (f.require_when ? passes(f.require_when, effective) : false))
  })

  return { visible, required }
}

/** A layout for a form that has none: a section for each step, a row for each field. */
export function deriveLayout(fields: FormField[]): FormLayout {
  const steps = [...new Set(fields.map((f) => Math.max(1, f.step ?? 1)))].sort((a, b) => a - b)
  let n = 0

  return {
    sections: steps.map((step) => ({
      id: `s${step}`, title: null, description: null, step_title: null, show_when: null, hide_when: null,
      rows: fields.filter((f) => Math.max(1, f.step ?? 1) === step).map((f) => ({ id: `r${++n}`, show_when: null, hide_when: null, columns: [{ id: 'c1', fields: [f.key] }] })),
    })),
  }
}
