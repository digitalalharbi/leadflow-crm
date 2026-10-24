import { useState } from 'react'
import { ArrowDown, ArrowUp, Columns2, Copy, GripVertical, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import type { FormField, LayoutRow, LayoutSection } from '@/features/hub'
import { cn } from '@/lib/cn'
import type { Draft, Target } from '@/lib/formBuilder'
import { DRAG_TYPE, FIELD_ICON, type DragPayload } from './Library'

export type Selection = { kind: 'field' | 'row' | 'section'; id: string } | null

/**
 * The form as it is built: sections, rows, columns, fields. Drag a field to move it, drag from the library to add one, or use the buttons —
 * every drag has a button that does the same, so nothing needs a mouse. A drop zone appears only while something is being dragged.
 */
export function Canvas({ draft, selection, onSelect, onDrop, onFieldOp, onRowOp, onSectionOp, multi }: {
  draft: Draft
  selection: Selection
  onSelect: (s: Selection) => void
  onDrop: (payload: DragPayload, target: Target) => void
  onFieldOp: (op: 'up' | 'down' | 'duplicate' | 'delete', key: string) => void
  onRowOp: (op: 'up' | 'down' | 'duplicate' | 'delete' | 'add-below', rowId: string, sectionId: string) => void
  onSectionOp: (op: 'up' | 'down' | 'delete' | 'add-row', sectionId: string) => void
  multi: boolean
}) {
  const { t } = useTranslation()
  const [dragging, setDragging] = useState(false)
  const byKey = new Map(draft.fields.map((f) => [f.key, f]))

  const read = (e: React.DragEvent): DragPayload | null => {
    try { return JSON.parse(e.dataTransfer.getData(DRAG_TYPE)) as DragPayload } catch { return null }
  }
  const zone = (target: Target) => ({
    // Always willing: whether what arrives can be dropped is decided on the drop (a drag from outside this page carries no payload and is ignored).
    onDragOver: (e: React.DragEvent) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move' },
    onDrop: (e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); setDragging(false); const p = read(e); if (p) onDrop(p, target) },
  })

  // A drop zone never changes the size of anything while a drag is under way: a layout that moves under the pointer cannot be dropped on.
  const rowZone = (section: LayoutSection, at: number) => (
    <div key={`z${at}`} {...zone({ section: section.id, newRowAt: at })} data-testid={`drop-row-${section.id}-${at}`} aria-hidden
      className={cn('h-3 rounded-full transition-colors', dragging ? 'bg-[var(--brand-soft)] outline-1 outline-dashed outline-[var(--brand)]' : '')} />
  )

  const card = (f: FormField, section: LayoutSection, row: LayoutRow, colId: string, index: number) => {
    const Icon = FIELD_ICON[f.type]
    const selected = selection?.kind === 'field' && selection.id === f.key

    return (
      <div key={f.key} {...zone({ section: section.id, row: row.id, column: colId, index })} data-testid={`canvas-field-${f.key}`} data-selected={selected || undefined}
        className={cn('group min-w-0 rounded-lg border bg-[var(--color-surface)]', selected ? 'border-[var(--brand)] ring-2 ring-[var(--brand-ring)]' : 'border-[var(--color-border)]')}>
        <div draggable onDragStart={(e) => { e.dataTransfer.setData(DRAG_TYPE, JSON.stringify({ kind: 'field', key: f.key } satisfies DragPayload)); e.dataTransfer.effectAllowed = 'move'; setDragging(true) }} onDragEnd={() => setDragging(false)}
          className="flex items-center gap-1.5 p-1.5">
          <GripVertical className="size-4 shrink-0 cursor-grab text-[var(--color-ink-subtle)]" aria-hidden />
          <button type="button" onClick={() => onSelect({ kind: 'field', id: f.key })} aria-pressed={selected} data-testid={`select-field-${f.key}`} className="flex min-w-0 flex-1 items-center gap-2 text-start">
            <Icon className="size-4 shrink-0 text-[var(--brand-text)]" aria-hidden />
            <span className="truncate text-[0.9375rem] font-semibold">{f.label || t(`hub.forms.type.${f.type}`)}{f.required && <span className="ms-0.5 text-[var(--color-danger)]">*</span>}</span>
            {f.hidden && <span className="t-caption rounded bg-[var(--color-surface-muted)] px-1.5">{t('hub.forms.hidden')}</span>}
            {(f.show_when || f.hide_when || f.require_when) && <span className="t-caption rounded bg-[var(--brand-soft)] px-1.5 text-[var(--brand-text)]" data-testid={`has-logic-${f.key}`}>{t('hub.builder.canvas.conditional')}</span>}
          </button>
          <span className="flex shrink-0 gap-0.5 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100">
            <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveUp')} icon={<ArrowUp className="size-3.5" />} onClick={() => onFieldOp('up', f.key)} data-testid={`up-${f.key}`} />
            <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveDown')} icon={<ArrowDown className="size-3.5" />} onClick={() => onFieldOp('down', f.key)} data-testid={`down-${f.key}`} />
            <Button size="sm" variant="ghost" aria-label={t('hub.builder.duplicate')} icon={<Copy className="size-3.5" />} onClick={() => onFieldOp('duplicate', f.key)} data-testid={`dup-${f.key}`} />
            <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-3.5" />} onClick={() => onFieldOp('delete', f.key)} data-testid={`remove-${f.key}`} />
          </span>
        </div>
      </div>
    )
  }

  return (
    <div className="min-w-0 space-y-4" data-testid="canvas" onDragEnd={() => setDragging(false)} onDragLeave={(e) => { if (e.currentTarget === e.target) setDragging(false) }}
      onDragEnter={() => setDragging(true)}>
      {draft.layout.sections.map((section, si) => {
        const sectionSelected = selection?.kind === 'section' && selection.id === section.id

        return (
          <section key={section.id} data-testid={`canvas-section-${si}`} className={cn('min-w-0 rounded-2xl border p-3', sectionSelected ? 'border-[var(--brand)]' : 'border-[var(--color-border)]')}>
            <div className="mb-2 flex items-center justify-between gap-2">
              <button type="button" onClick={() => onSelect({ kind: 'section', id: section.id })} aria-pressed={sectionSelected} data-testid={`select-section-${si}`} className="min-w-0 text-start">
                <span className="block truncate text-[0.8125rem] font-bold text-[var(--color-ink-subtle)]">{multi ? t('hub.builder.canvas.step', { n: si + 1 }) : t('hub.builder.canvas.section', { n: si + 1 })}</span>
                <span className="block truncate font-extrabold">{section.step_title || section.title || t('hub.builder.canvas.untitled')}</span>
              </button>
              <span className="flex shrink-0 gap-0.5">
                <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveUp')} disabled={si === 0} icon={<ArrowUp className="size-3.5" />} onClick={() => onSectionOp('up', section.id)} />
                <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveDown')} disabled={si === draft.layout.sections.length - 1} icon={<ArrowDown className="size-3.5" />} onClick={() => onSectionOp('down', section.id)} />
                <Button size="sm" variant="ghost" aria-label={t('common.delete')} disabled={draft.layout.sections.length <= 1} icon={<Trash2 className="size-3.5" />} onClick={() => onSectionOp('delete', section.id)} data-testid={`delete-section-${si}`} />
              </span>
            </div>

            {rowZone(section, 0)}
            {section.rows.map((row, ri) => {
              const rowSelected = selection?.kind === 'row' && selection.id === row.id

              return (
                <div key={row.id}>
                  <div data-testid={`canvas-row-${row.id}`} data-row={ri} className={cn('rounded-xl border p-2', rowSelected ? 'border-[var(--brand)]' : 'border-dashed border-[var(--color-border)]')}>
                    <div className="mb-1.5 flex items-center justify-between gap-1">
                      <button type="button" onClick={() => onSelect({ kind: 'row', id: row.id })} aria-pressed={rowSelected} className="flex items-center gap-1 text-[0.75rem] font-semibold text-[var(--color-ink-subtle)]" data-testid={`select-row-${row.id}`}>
                        <Columns2 className="size-3.5" aria-hidden />{t('hub.builder.canvas.row', { n: ri + 1 })}
                        {(row.show_when || row.hide_when) && <span className="rounded bg-[var(--brand-soft)] px-1 text-[var(--brand-text)]">{t('hub.builder.canvas.conditional')}</span>}
                      </button>
                      <span className="flex items-center gap-0.5">
                        {[1, 2, 3, 4].map((n) => (
                          <button key={n} type="button" onClick={() => onRowOp(`cols-${n}` as never, row.id, section.id)} aria-label={t('hub.builder.canvas.columns', { n })} aria-pressed={row.columns.length === n} data-testid={`cols-${row.id}-${n}`}
                            className={cn('grid size-7 place-items-center rounded text-[0.75rem] font-bold', row.columns.length === n ? 'bg-[var(--brand)] text-[var(--brand-contrast)]' : 'bg-[var(--color-surface-muted)]')}>{n}</button>
                        ))}
                        <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveUp')} disabled={ri === 0} icon={<ArrowUp className="size-3.5" />} onClick={() => onRowOp('up', row.id, section.id)} data-testid={`row-up-${row.id}`} />
                        <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveDown')} disabled={ri === section.rows.length - 1} icon={<ArrowDown className="size-3.5" />} onClick={() => onRowOp('down', row.id, section.id)} data-testid={`row-down-${row.id}`} />
                        <Button size="sm" variant="ghost" aria-label={t('hub.builder.duplicate')} icon={<Copy className="size-3.5" />} onClick={() => onRowOp('duplicate', row.id, section.id)} data-testid={`row-dup-${row.id}`} />
                        <Button size="sm" variant="ghost" aria-label={t('common.delete')} icon={<Trash2 className="size-3.5" />} onClick={() => onRowOp('delete', row.id, section.id)} data-testid={`row-del-${row.id}`} />
                      </span>
                    </div>
                    <div className={cn('grid gap-2', row.columns.length > 1 && 'sm:grid-cols-[repeat(var(--cols),minmax(0,1fr))]')} style={{ ['--cols' as string]: row.columns.length }}>
                      {row.columns.map((col) => (
                        <div key={col.id} data-testid={`canvas-col-${col.id}`} className="min-h-12 min-w-0 space-y-1.5" {...zone({ section: section.id, row: row.id, column: col.id, index: col.fields.length })}>
                          {col.fields.map((k, i) => (byKey.get(k) ? card(byKey.get(k)!, section, row, col.id, i) : null))}
                          {col.fields.length === 0 && (
                            <div data-testid={`drop-col-${col.id}`} className={cn('grid min-h-10 place-items-center rounded-lg border-2 border-dashed text-[0.75rem] text-[var(--color-ink-subtle)]', dragging ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand-text)]' : 'border-[var(--color-border)]')}>
                              {t('hub.builder.canvas.dropHere')}
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  </div>
                  {rowZone(section, ri + 1)}
                </div>
              )
            })}
            <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => onSectionOp('add-row', section.id)} data-testid={`add-row-${si}`}>{t('hub.builder.canvas.addRow')}</Button>
          </section>
        )
      })}
    </div>
  )
}
