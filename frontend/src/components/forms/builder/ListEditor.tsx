import { useState } from 'react'
import { ArrowDown, ArrowUp, Copy, GripVertical, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Textarea } from '@/components/ui'
import type { FieldOption } from '@/features/hub'
import { parseBulkOptions } from '@/lib/formBuilder'

/**
 * A list of choices, rows or columns: rename, add, duplicate, delete, reorder (by dragging the grip or with the arrows), and paste a
 * whole list at once — "الرياض، جدة، الخبر" becomes three. The value behind a label is kept; a new one is the label itself.
 */
export function ListEditor({ items, onChange, min = 1, max = 40, testId, label }: { items: FieldOption[]; onChange: (items: FieldOption[]) => void; min?: number; max?: number; testId: string; label: string }) {
  const { t } = useTranslation()
  const [drag, setDrag] = useState<number | null>(null)
  const [bulk, setBulk] = useState('')
  const [bulkOpen, setBulkOpen] = useState(false)

  const move = (from: number, to: number) => {
    if (to < 0 || to >= items.length || from === to) return
    const next = [...items]
    const [item] = next.splice(from, 1)
    next.splice(to, 0, item)
    onChange(next)
  }

  const unique = (value: string) => {
    let v = value
    let n = 2
    while (items.some((o) => o.value === v)) v = `${value} ${n++}`

    return v
  }

  const rename = (i: number, text: string) => onChange(items.map((o, n) => (n === i ? { value: o.value === o.label ? text : o.value, label: text } : o)))

  return (
    <div data-testid={testId}>
      <p className="mb-1.5 text-[0.9375rem] font-semibold">{label}</p>
      <ul className="space-y-1.5">
        {items.map((o, i) => (
          <li key={`${o.value}-${i}`} draggable onDragStart={() => setDrag(i)} onDragOver={(e) => e.preventDefault()} onDrop={() => { if (drag !== null) move(drag, i); setDrag(null) }}
            className="flex items-center gap-1.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-1.5" data-testid={`${testId}-item-${i}`}>
            <GripVertical className="size-4 shrink-0 cursor-grab text-[var(--color-ink-subtle)]" aria-hidden />
            <input aria-label={t('hub.builder.list.label')} value={o.label} onChange={(e) => rename(i, e.target.value)} className="field !h-10 min-w-0 flex-1" data-testid={`${testId}-label-${i}`} />
            <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveUp')} disabled={i === 0} icon={<ArrowUp className="size-3.5" />} onClick={() => move(i, i - 1)} />
            <Button size="sm" variant="ghost" aria-label={t('hub.builder.moveDown')} disabled={i === items.length - 1} icon={<ArrowDown className="size-3.5" />} onClick={() => move(i, i + 1)} />
            <Button size="sm" variant="ghost" aria-label={t('hub.builder.duplicate')} disabled={items.length >= max} icon={<Copy className="size-3.5" />}
              onClick={() => { const v = unique(o.value); onChange([...items.slice(0, i + 1), { value: v, label: o.label }, ...items.slice(i + 1)]) }} data-testid={`${testId}-dup-${i}`} />
            <Button size="sm" variant="ghost" aria-label={t('common.delete')} disabled={items.length <= min} icon={<Trash2 className="size-3.5" />} onClick={() => onChange(items.filter((_, n) => n !== i))} data-testid={`${testId}-del-${i}`} />
          </li>
        ))}
      </ul>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={items.length >= max}
          onClick={() => { const label = `${t('hub.builder.list.item')} ${items.length + 1}`; onChange([...items, { value: unique(label), label }]) }} data-testid={`${testId}-add`}>{t('hub.builder.list.add')}</Button>
        <Button size="sm" variant="ghost" onClick={() => setBulkOpen((v) => !v)} data-testid={`${testId}-bulk-toggle`}>{t('hub.builder.list.bulk')}</Button>
      </div>
      {bulkOpen && (
        <div className="mt-2 space-y-2">
          <Textarea aria-label={t('hub.builder.list.bulk')} hint={t('hub.builder.list.bulkHint')} rows={4} value={bulk} onChange={(e) => setBulk(e.target.value)} data-testid={`${testId}-bulk`} />
          <Button size="sm" onClick={() => {
            const parsed = parseBulkOptions(bulk).filter((o) => !items.some((x) => x.value === o.value)).slice(0, max - items.length)
            onChange([...items, ...parsed]); setBulk(''); setBulkOpen(false)
          }} disabled={bulk.trim() === ''} data-testid={`${testId}-bulk-apply`}>{t('hub.builder.list.bulkApply')}</Button>
        </div>
      )}
    </div>
  )
}
