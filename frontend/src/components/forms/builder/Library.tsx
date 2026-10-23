import { AlignRight, Calendar, CheckSquare, Clock, Grid3x3, Heading, Hash, List, ListChecks, ListPlus, Mail, Minus, Phone, Repeat, ShieldCheck, Text, TextCursorInput, Type, type LucideIcon } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { FieldType, FormField } from '@/features/hub'
import { cn } from '@/lib/cn'

export const DRAG_TYPE = 'application/x-mt-form'
export type DragPayload = { kind: 'type'; type: FieldType } | { kind: 'preset'; key: string } | { kind: 'field'; key: string }

const ICON: Record<FieldType, LucideIcon> = {
  text: Type, phone: Phone, email: Mail, number: Hash, textarea: AlignRight, select: List, multi_select: ListChecks, radio: ListPlus, checkbox: CheckSquare, date: Calendar, time: Clock,
  hidden: TextCursorInput, heading: Heading, paragraph: Text, divider: Minus, consent: ShieldCheck, matrix: Grid3x3, repeater: Repeat,
}

const GROUPS: { key: string; types: FieldType[] }[] = [
  { key: 'basic', types: ['text', 'phone', 'email', 'number', 'textarea', 'date', 'time'] },
  { key: 'choice', types: ['select', 'radio', 'multi_select', 'checkbox', 'consent'] },
  { key: 'display', types: ['heading', 'paragraph', 'divider'] },
  { key: 'advanced', types: ['matrix', 'repeater', 'hidden'] },
]

function Item({ icon: Icon, label, payload, onAdd, testId }: { icon: LucideIcon; label: string; payload: DragPayload; onAdd: () => void; testId: string }) {
  return (
    <button type="button" draggable onClick={onAdd} onDragStart={(e) => { e.dataTransfer.setData(DRAG_TYPE, JSON.stringify(payload)); e.dataTransfer.effectAllowed = 'copyMove' }} data-testid={testId}
      className={cn('pressable flex min-h-10 w-full items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2.5 text-start text-[0.875rem] font-medium hover:border-[var(--brand)]')}>
      <Icon className="size-4 shrink-0 text-[var(--color-ink-subtle)]" aria-hidden /><span className="truncate">{label}</span>
    </button>
  )
}

/** What can be added: field types, and the questions a real estate form usually asks. Click to add at the end, or drag to a place on the canvas. */
export function Library({ presets, used, onAddType, onAddPreset }: { presets: FormField[]; used: string[]; onAddType: (type: FieldType) => void; onAddPreset: (key: string) => void }) {
  const { t } = useTranslation()
  const available = presets.filter((p) => !used.includes(p.key))

  return (
    <div className="space-y-4" data-testid="library">
      {GROUPS.map((g) => (
        <section key={g.key}>
          <h3 className="mb-1.5 text-[0.8125rem] font-bold uppercase tracking-wide text-[var(--color-ink-subtle)]">{t(`hub.builder.library.${g.key}`)}</h3>
          <div className="grid grid-cols-2 gap-1.5 lg:grid-cols-1 xl:grid-cols-2">
            {g.types.map((ty) => <Item key={ty} icon={ICON[ty]} label={t(`hub.forms.type.${ty}`)} payload={{ kind: 'type', type: ty }} onAdd={() => onAddType(ty)} testId={`lib-${ty}`} />)}
          </div>
        </section>
      ))}
      {available.length > 0 && (
        <section>
          <h3 className="mb-1.5 text-[0.8125rem] font-bold uppercase tracking-wide text-[var(--color-ink-subtle)]">{t('hub.builder.library.realEstate')}</h3>
          <div className="grid grid-cols-2 gap-1.5 lg:grid-cols-1 xl:grid-cols-2">
            {available.map((p) => <Item key={p.key} icon={ICON[p.type] ?? Type} label={p.label} payload={{ kind: 'preset', key: p.key }} onAdd={() => onAddPreset(p.key)} testId={`lib-preset-${p.key}`} />)}
          </div>
        </section>
      )}
    </div>
  )
}

export { ICON as FIELD_ICON }
