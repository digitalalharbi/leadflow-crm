import { describe, expect, it } from 'vitest'
import type { FormField } from '@/features/hub'
import { addField, addRow, nudgeField, addSection, blankField, commit, deleteRow, deleteSection, duplicateField, duplicateRow, ensureLayout, moveField, moveRow, parseBulkOptions, redo, removeField, setColumns, startHistory, undo, type Draft } from './formBuilder'

const f = (key: string, over: Partial<FormField> = {}): FormField => ({ key, type: 'text', label: key, required: false, hidden: false, mapping: 'none', ...over })

const draft = (): Draft => {
  const fields = [f('a'), f('b'), f('c')]

  return { fields, layout: ensureLayout(fields, null) }
}

const placed = (d: Draft) => d.layout.sections.flatMap((s) => s.rows.flatMap((r) => r.columns.flatMap((c) => c.fields)))
const rows = (d: Draft) => d.layout.sections[0].rows

describe('the layout always places every field exactly once', () => {
  it('makes one from the fields, and adopts a field nobody placed', () => {
    const fields = [f('a'), f('b')]
    const layout = ensureLayout(fields, { sections: [{ id: 's1', rows: [{ id: 'r1', columns: [{ id: 'c1', fields: ['a', 'ghost', 'a'] }] }] }] } as never)

    expect(layout.sections.flatMap((s) => s.rows.flatMap((r) => r.columns.flatMap((c) => c.fields)))).toEqual(['a', 'b'])
  })
})

describe('fields', () => {
  it('adds a field in a new row at the end', () => {
    const d = addField(draft(), f('d'))
    expect(placed(d)).toEqual(['a', 'b', 'c', 'd'])
    expect(rows(d)).toHaveLength(4)
  })

  it('drops a field into a column beside another', () => {
    const d0 = setColumns(draft(), draft().layout.sections[0].rows[0].id, 2)
    const row = rows(d0)[0]
    const d = moveField(d0, 'c', { section: d0.layout.sections[0].id, row: row.id, column: row.columns[1].id, index: 0 })

    expect(rows(d)[0].columns.map((c) => c.fields)).toEqual([['a'], ['c']])
    expect(placed(d).sort()).toEqual(['a', 'b', 'c'])
  })

  it('moves a field to a new row between two others', () => {
    const d0 = draft()
    const d = moveField(d0, 'c', { section: d0.layout.sections[0].id, newRowAt: 1 })

    expect(placed(d)).toEqual(['a', 'c', 'b'])
  })

  it('never loses or doubles a field when it is dropped where it already is', () => {
    const d0 = draft()
    const r = rows(d0)[1]
    const d = moveField(d0, 'b', { section: d0.layout.sections[0].id, row: r.id, column: r.columns[0].id, index: 0 })

    expect(placed(d)).toEqual(['a', 'b', 'c'])
  })

  it('removes a field and the row it leaves empty', () => {
    const d = removeField(draft(), 'b')

    expect(placed(d)).toEqual(['a', 'c'])
    expect(rows(d)).toHaveLength(2)
    expect(d.fields.map((x) => x.key)).toEqual(['a', 'c'])
  })

  it('duplicates a field right after the original with a new key and no claim on the original\'s lead column', () => {
    const d0 = { ...draft(), fields: draft().fields.map((x) => (x.key === 'a' ? { ...x, mapping: 'lead.city' } : x)) }
    const d = duplicateField(d0, 'a')

    expect(placed(d).slice(0, 2)).toEqual(['a', d.fields[3].key])
    expect(d.fields[3].key).not.toBe('a')
    expect(d.fields[3].mapping).toBe('none')
  })
})

describe('rows and columns', () => {
  it('turns a row into up to four columns and folds the fields back when it shrinks', () => {
    const d0 = draft()
    const id = rows(d0)[0].id
    const four = setColumns(d0, id, 4)
    expect(rows(four)[0].columns).toHaveLength(4)

    const r = rows(four)[0]
    const filled = moveField(four, 'b', { section: four.layout.sections[0].id, row: r.id, column: r.columns[3].id, index: 0 })
    const back = setColumns(filled, id, 2)
    expect(rows(back)[0].columns).toHaveLength(2)
    expect(placed(back)).toContain('b')
    expect(setColumns(d0, id, 9).layout.sections[0].rows[0].columns).toHaveLength(4)
  })

  it('moves a row up and down but not off the ends', () => {
    const d0 = draft()
    const ids = rows(d0).map((r) => r.id)
    expect(rows(moveRow(d0, ids[2], -1)).map((r) => r.id)).toEqual([ids[0], ids[2], ids[1]])
    expect(rows(moveRow(d0, ids[0], -1)).map((r) => r.id)).toEqual(ids)
    expect(rows(moveRow(d0, ids[2], 1)).map((r) => r.id)).toEqual(ids)
  })

  it('duplicates a row with copies of its fields', () => {
    const d0 = draft()
    const d = duplicateRow(d0, rows(d0)[0].id)

    expect(rows(d)).toHaveLength(4)
    expect(d.fields).toHaveLength(4)
    expect(new Set(d.fields.map((x) => x.key)).size).toBe(4)
    expect(placed(d).sort()).toEqual(d.fields.map((x) => x.key).sort())
  })

  it('deletes a row with its fields', () => {
    const d0 = draft()
    const d = deleteRow(d0, rows(d0)[1].id)

    expect(d.fields.map((x) => x.key)).toEqual(['a', 'c'])
    expect(placed(d)).toEqual(['a', 'c'])
  })

  it('adds an empty row anywhere, then a field can be dropped into it', () => {
    const d0 = draft()
    const d1 = addRow(d0, d0.layout.sections[0].id, 1, 2)
    const empty = rows(d1)[1]
    expect(empty.columns).toHaveLength(2)

    const d2 = moveField(d1, 'c', { section: d1.layout.sections[0].id, row: empty.id, column: empty.columns[1].id, index: 0 })
    expect(rows(d2)[1].columns[1].fields).toEqual(['c'])
  })
})

describe('sections', () => {
  it('adds a section, and deleting one takes its fields with it — but never the last', () => {
    const d1 = addSection(draft())
    expect(d1.layout.sections).toHaveLength(2)

    const first = d1.layout.sections[0].id
    const d2 = deleteSection(d1, first)
    expect(d2.layout.sections).toHaveLength(1)
    expect(d2.fields).toHaveLength(0)
    expect(deleteSection(d2, d2.layout.sections[0].id).layout.sections).toHaveLength(1)
  })
})

describe('options', () => {
  it('turns a pasted list into options — one to a line, or separated by commas — without blanks or repeats', () => {
    expect(parseBulkOptions('الرياض\nجدة\n\n  الخبر \nالدمام\nجدة')).toEqual([
      { value: 'الرياض', label: 'الرياض' }, { value: 'جدة', label: 'جدة' }, { value: 'الخبر', label: 'الخبر' }, { value: 'الدمام', label: 'الدمام' },
    ])
    expect(parseBulkOptions('a, b، c').map((o) => o.label)).toEqual(['a', 'b', 'c'])
  })
})

describe('new fields come ready to work', () => {
  it('gives a matrix rows and columns, a repeater its columns, a choice its options', () => {
    expect(blankField('matrix', 'm', 'x').matrix!.columns.length).toBeGreaterThanOrEqual(2)
    expect(blankField('repeater', 'r', 'x').repeater!.subfields.length).toBeGreaterThan(0)
    expect(blankField('select', 's', 'x').options!.length).toBe(2)
    expect(blankField('hidden', 'h', 'x').hidden).toBe(true)
  })
})

describe('history', () => {
  it('undoes and redoes, and a new change forgets the future', () => {
    let h = startHistory(1)
    h = commit(h, 2)
    h = commit(h, 3)
    expect(h.present).toBe(3)
    h = undo(h)
    expect(h.present).toBe(2)
    h = redo(h)
    expect(h.present).toBe(3)
    h = undo(undo(h))
    expect(h.present).toBe(1)
    expect(undo(h).present).toBe(1)
    h = commit(h, 9)
    expect(h.future).toEqual([])
    expect(redo(h).present).toBe(9)
  })

  it('does not record a change that changed nothing', () => {
    const h = startHistory({ a: 1 })

    expect(commit(h, h.present)).toBe(h)
  })
})

describe('nudging a field', () => {
  it('moves a field up and down through the rows', () => {
    const d0 = draft()
    expect(placed(nudgeField(d0, 'c', -1))).toEqual(['a', 'c', 'b'])
    expect(placed(nudgeField(d0, 'a', 1))).toEqual(['b', 'a', 'c'])
  })

  it('swaps within a column when there is a neighbour', () => {
    let d = draft()
    const r = rows(d)[0]
    d = moveField(d, 'b', { section: d.layout.sections[0].id, row: r.id, column: r.columns[0].id, index: 1 })

    expect(rows(d)[0].columns[0].fields).toEqual(['a', 'b'])
    expect(rows(nudgeField(d, 'b', -1))[0].columns[0].fields).toEqual(['b', 'a'])
  })
})
