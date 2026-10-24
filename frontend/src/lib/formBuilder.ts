import { deriveLayout } from '@/lib/formLogic'
import type { FieldOption, FieldType, FormField, FormLayout, LayoutRow, LayoutSection } from '@/features/hub'

/**
 * What a manager does to a form, as plain functions on `{fields, layout}`: add, move, duplicate and delete a field, a row, a section;
 * change how many columns a row has. Each returns a new draft and never leaves a field placed twice or not at all — the builder's
 * drag-and-drop and its buttons both call these, so they cannot disagree.
 */
export interface Draft { fields: FormField[]; layout: FormLayout }

export type Target = { section: string; row: string; column: string; index: number } | { section: string; newRowAt: number }

export const MAX_COLUMNS = 4

let counter = 0
const uid = (prefix: string) => `${prefix}${Date.now().toString(36).slice(-4)}${(counter++).toString(36)}`

export function freeKey(fields: FormField[], base = 'field'): string {
  let n = fields.length + 1
  while (fields.some((f) => f.key === `${base}_${n}`)) n++

  return `${base}_${n}`
}

const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T

/** A layout for the fields as they are: the one the form has, or one made from its steps — and every field placed once. */
export function ensureLayout(fields: FormField[], layout: FormLayout | null | undefined): FormLayout {
  const base = layout && layout.sections.length > 0 ? clone(layout) : deriveLayout(fields)
  const known = new Set(fields.map((f) => f.key))
  const placed = new Set<string>()

  for (const s of base.sections) {
    for (const r of s.rows) {
      for (const c of r.columns) c.fields = c.fields.filter((k) => known.has(k) && !placed.has(k) && placed.add(k))
    }
    s.rows = s.rows.filter((r) => r.columns.some((c) => c.fields.length > 0))
  }

  const loose = fields.filter((f) => !placed.has(f.key))
  if (loose.length > 0) {
    if (base.sections.length === 0) base.sections.push(blankSection())
    for (const f of loose) base.sections[base.sections.length - 1].rows.push(newRow([f.key]))
  }

  return base
}

export const blankSection = (): LayoutSection => ({ id: uid('s'), title: null, description: null, step_title: null, show_when: null, hide_when: null, rows: [] })
export const newRow = (keys: string[] = [], columns = 1): LayoutRow => ({
  id: uid('r'), show_when: null, hide_when: null,
  columns: Array.from({ length: Math.max(1, columns) }, (_, i) => ({ id: uid('c'), fields: i === 0 ? keys : [] })),
})

/** Take a field out of wherever it is placed — and the row it was in, if that leaves the row empty. Other empty rows (waiting to be filled) stay. */
function unplace(layout: FormLayout, key: string): FormLayout {
  const next = clone(layout)

  for (const s of next.sections) {
    s.rows = s.rows.filter((r) => {
      const had = r.columns.some((c) => c.fields.includes(key))
      for (const c of r.columns) c.fields = c.fields.filter((k) => k !== key)

      return !had || r.columns.some((c) => c.fields.length > 0)
    })
  }

  return next
}

function place(layout: FormLayout, key: string, target: Target | undefined): FormLayout {
  const next = clone(layout)
  if (next.sections.length === 0) next.sections.push(blankSection())

  if (!target) {
    next.sections[next.sections.length - 1].rows.push(newRow([key]))

    return next
  }

  const section = next.sections.find((s) => s.id === target.section) ?? next.sections[next.sections.length - 1]

  if ('newRowAt' in target) {
    section.rows.splice(Math.min(Math.max(0, target.newRowAt), section.rows.length), 0, newRow([key]))

    return next
  }

  const column = section.rows.find((r) => r.id === target.row)?.columns.find((c) => c.id === target.column)

  if (column) column.fields.splice(Math.min(Math.max(0, target.index), column.fields.length), 0, key)
  else section.rows.push(newRow([key]))

  return next
}

export function addField(d: Draft, field: FormField, target?: Target): Draft {
  return { fields: [...d.fields, { ...field, order: d.fields.length + 1 }], layout: place(d.layout, field.key, target) }
}

export function moveField(d: Draft, key: string, target: Target): Draft {
  // Dropping onto the cell the field already fills must not remove the target out from under itself.
  if (!('newRowAt' in target)) {
    const col = d.layout.sections.find((s) => s.id === target.section)?.rows.find((r) => r.id === target.row)?.columns.find((c) => c.id === target.column)
    if (col?.fields[target.index] === key || col?.fields[target.index - 1] === key) return d
  }

  return { fields: d.fields, layout: place(unplace(d.layout, key), key, target) }
}

export function removeField(d: Draft, key: string): Draft {
  return { fields: d.fields.filter((f) => f.key !== key), layout: unplace(d.layout, key) }
}

export function updateField(d: Draft, key: string, patch: Partial<FormField>): Draft {
  return { ...d, fields: d.fields.map((f) => (f.key === key ? { ...f, ...patch } : f)) }
}

/** A field with the same questions, a new key, and its own mapping cleared (two fields may not fill one lead column). */
export function duplicateField(d: Draft, key: string): Draft {
  const original = d.fields.find((f) => f.key === key)
  if (!original) return d
  const copy: FormField = { ...clone(original), key: freeKey(d.fields, original.key.replace(/_\d+$/, '')), mapping: original.mapping.startsWith('custom.') ? original.mapping : 'none' }
  copy.label = original.label
  // Right after the original, in the same cell.
  for (const s of d.layout.sections) for (const r of s.rows) for (const c of r.columns) {
    const i = c.fields.indexOf(key)
    if (i >= 0) return addField(d, copy, { section: s.id, row: r.id, column: c.id, index: i + 1 })
  }

  return addField(d, copy)
}

export function addRow(d: Draft, sectionId: string, at: number, columns = 1): Draft {
  const layout = clone(d.layout)
  const s = layout.sections.find((x) => x.id === sectionId)
  if (s) s.rows.splice(Math.min(Math.max(0, at), s.rows.length), 0, newRow([], columns))

  return { ...d, layout }
}

/** An empty row stays while it is being filled; `ensureLayout` drops it when the form is saved. */
export function moveRow(d: Draft, rowId: string, by: -1 | 1): Draft {
  const layout = clone(d.layout)

  for (const s of layout.sections) {
    const i = s.rows.findIndex((r) => r.id === rowId)
    if (i < 0) continue
    const j = i + by
    if (j >= 0 && j < s.rows.length) [s.rows[i], s.rows[j]] = [s.rows[j], s.rows[i]]
  }

  return { ...d, layout }
}

export function duplicateRow(d: Draft, rowId: string): Draft {
  let out = d

  for (const s of d.layout.sections) {
    const i = s.rows.findIndex((r) => r.id === rowId)
    if (i < 0) continue

    const original = s.rows[i]
    const copy: LayoutRow = { ...clone(original), id: uid('r') }
    copy.columns = copy.columns.map(() => ({ id: uid('c'), fields: [] as string[] }))
    out = { ...out, layout: (() => { const l = clone(out.layout); l.sections.find((x) => x.id === s.id)!.rows.splice(i + 1, 0, copy); return l })() }

    original.columns.forEach((c, ci) => c.fields.forEach((k) => {
      const f = out.fields.find((x) => x.key === k)
      if (!f) return
      const dup: FormField = { ...clone(f), key: freeKey(out.fields, f.key.replace(/_\d+$/, '')), mapping: f.mapping.startsWith('custom.') ? f.mapping : 'none' }
      out = addField(out, dup, { section: s.id, row: copy.id, column: copy.columns[ci].id, index: 9999 })
    }))
  }

  return out
}

export function deleteRow(d: Draft, rowId: string): Draft {
  const keys = d.layout.sections.flatMap((s) => s.rows.filter((r) => r.id === rowId).flatMap((r) => r.columns.flatMap((c) => c.fields)))
  const layout = clone(d.layout)
  for (const s of layout.sections) s.rows = s.rows.filter((r) => r.id !== rowId)

  return { fields: d.fields.filter((f) => !keys.includes(f.key)), layout }
}

/** A row of one to four columns: more columns are empty; fewer fold their fields into the last one kept. */
export function setColumns(d: Draft, rowId: string, count: number): Draft {
  const layout = clone(d.layout)
  const n = Math.min(MAX_COLUMNS, Math.max(1, count))

  for (const s of layout.sections) {
    const r = s.rows.find((x) => x.id === rowId)
    if (!r) continue
    while (r.columns.length < n) r.columns.push({ id: uid('c'), fields: [] })
    while (r.columns.length > n) { const gone = r.columns.pop()!; r.columns[r.columns.length - 1].fields.push(...gone.fields) }
  }

  return { ...d, layout }
}

export function addSection(d: Draft): Draft {
  return { ...d, layout: { sections: [...d.layout.sections, blankSection()] } }
}

export function updateSection(d: Draft, id: string, patch: Partial<LayoutSection>): Draft {
  return { ...d, layout: { sections: d.layout.sections.map((s) => (s.id === id ? { ...s, ...patch } : s)) } }
}

export function updateRow(d: Draft, id: string, patch: Partial<LayoutRow>): Draft {
  return { ...d, layout: { sections: d.layout.sections.map((s) => ({ ...s, rows: s.rows.map((r) => (r.id === id ? { ...r, ...patch } : r)) })) } }
}

export function moveSection(d: Draft, id: string, by: -1 | 1): Draft {
  const sections = [...d.layout.sections]
  const i = sections.findIndex((s) => s.id === id)
  const j = i + by
  if (i < 0 || j < 0 || j >= sections.length) return d;
  [sections[i], sections[j]] = [sections[j], sections[i]]

  return { ...d, layout: { sections } }
}

export function deleteSection(d: Draft, id: string): Draft {
  const keys = d.layout.sections.filter((s) => s.id === id).flatMap((s) => s.rows.flatMap((r) => r.columns.flatMap((c) => c.fields)))
  if (d.layout.sections.length <= 1) return d

  return { fields: d.fields.filter((f) => !keys.includes(f.key)), layout: { sections: d.layout.sections.filter((s) => s.id !== id) } }
}

/** Options as people write them: one to a line, a comma or a pasted column. Blanks and repeats go; a label is its own value. */
export function parseBulkOptions(text: string): FieldOption[] {
  const seen = new Set<string>()

  return text.split(/[\n\r,،;؛\t]+/).map((l) => l.trim()).filter(Boolean).filter((l) => !seen.has(l) && seen.add(l)).map((label) => ({ value: label.slice(0, 80), label: label.slice(0, 160) }))
}

/** A new field of a type, with the defaults that make it work at once. */
export function blankField(type: FieldType, key: string, label: string): FormField {
  const base: FormField = { key, type, label, required: false, hidden: false, mapping: 'none' }

  switch (type) {
    case 'select': case 'radio': case 'multi_select':
      return { ...base, options: [{ value: 'خيار 1', label: 'خيار 1' }, { value: 'خيار 2', label: 'خيار 2' }] }
    case 'matrix':
      return { ...base, matrix: { rows: [{ value: 'row1', label: 'البند 1' }, { value: 'row2', label: 'البند 2' }], columns: [{ value: 'low', label: 'غير مهم' }, { value: 'mid', label: 'مهم' }, { value: 'high', label: 'مهم جدًا' }], multiple: false, require_all_rows: false, min_answers: 0 } }
    case 'repeater':
      return { ...base, repeater: { subfields: [{ key: 'name', type: 'text', label: 'الاسم', required: true }, { key: 'mobile', type: 'phone', label: 'الجوال' }], min_rows: 0, max_rows: 5, default_rows: 1, add_label: null } }
    case 'hidden':
      return { ...base, hidden: true }
    default:
      return base
  }
}

/** Undo and redo over any state: each change is a step, a run of typing in one box is one. */
export interface History<T> { past: T[]; present: T; future: T[] }
export const startHistory = <T,>(present: T): History<T> => ({ past: [], present, future: [] })
export function commit<T>(h: History<T>, next: T, limit = 60): History<T> {
  return next === h.present ? h : { past: [...h.past, h.present].slice(-limit), present: next, future: [] }
}
export const undo = <T,>(h: History<T>): History<T> => (h.past.length === 0 ? h : { past: h.past.slice(0, -1), present: h.past[h.past.length - 1], future: [h.present, ...h.future] })
export const redo = <T,>(h: History<T>): History<T> => (h.future.length === 0 ? h : { past: [...h.past, h.present], present: h.future[0], future: h.future.slice(1) })

/** Up or down: past a neighbour in the same column, or — at the edge of the column — into its own row above or below. */
export function nudgeField(d: Draft, key: string, dir: -1 | 1): Draft {
  for (const s of d.layout.sections) {
    for (let ri = 0; ri < s.rows.length; ri++) {
      for (const c of s.rows[ri].columns) {
        const i = c.fields.indexOf(key)
        if (i < 0) continue
        const j = i + dir

        if (j >= 0 && j < c.fields.length) {
          const layout = clone(d.layout)
          const col = layout.sections.find((x) => x.id === s.id)!.rows[ri].columns.find((x) => x.id === c.id)!;
          [col.fields[i], col.fields[j]] = [col.fields[j], col.fields[i]]

          return { ...d, layout }
        }

        // At the edge: a row of its own, above or below this one. A field alone in its row simply swaps places with the neighbouring row.
        const alone = s.rows[ri].columns.reduce((n, col) => n + col.fields.length, 0) === 1
        if (alone && ((dir < 0 && ri === 0) || (dir > 0 && ri === s.rows.length - 1))) return d
        const at = alone ? (dir < 0 ? ri - 1 : ri + 1) : dir < 0 ? ri : ri + 1

        return moveField(d, key, { section: s.id, newRowAt: at })
      }
    }
  }

  return d
}
