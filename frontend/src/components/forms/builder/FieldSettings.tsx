import { Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Select, Textarea } from '@/components/ui'
import { FIELD_TYPES, type FormField, type FormPresets, type RepeaterSubfield } from '@/features/hub'
import { ListEditor } from './ListEditor'
import { LogicEditor } from './LogicEditor'

const CHOICE = ['select', 'radio', 'multi_select']
const TEXTUAL = ['text', 'textarea', 'email']
const DISPLAY = ['heading', 'paragraph', 'divider']
const STRUCTURED = ['matrix', 'repeater', 'consent']
const SUB_TYPES: RepeaterSubfield['type'][] = ['text', 'phone', 'email', 'number', 'select', 'date', 'time', 'textarea']

const Toggle = ({ label, checked, onChange, testId, disabled }: { label: string; checked: boolean; onChange: (v: boolean) => void; testId: string; disabled?: boolean }) => (
  <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} data-testid={testId} />{label}</label>
)

/** Everything about one field: its words, its type, how it is checked, where its answer goes, and when it is shown, hidden or required. */
export function FieldSettings({ field, fields, presets, onChange }: { field: FormField; fields: FormField[]; presets?: FormPresets; onChange: (patch: Partial<FormField>) => void }) {
  const { t } = useTranslation()
  const display = DISPLAY.includes(field.type)
  const structured = STRUCTURED.includes(field.type)
  const v = field.validation ?? {}
  const setV = (p: Record<string, number | string | undefined>) => onChange({ validation: Object.fromEntries(Object.entries({ ...v, ...p }).filter(([, x]) => x !== undefined && x !== '')) as FormField['validation'] })
  const m = field.matrix
  const r = field.repeater

  const mappingGroup = field.mapping.startsWith('lead.') ? 'lead' : field.mapping.startsWith('tracking.') ? 'tracking' : field.mapping.startsWith('custom.') ? 'custom' : 'none'

  return (
    <div className="space-y-4" data-testid="field-settings">
      {field.type === 'paragraph'
        ? <Textarea label={t('hub.builder.field.text')} rows={3} value={field.label} onChange={(e) => onChange({ label: e.target.value })} data-testid="fs-label" />
        : <Input label={field.type === 'heading' ? t('hub.builder.field.text') : t('hub.forms.fieldLabel')} value={field.label} onChange={(e) => onChange({ label: e.target.value })} data-testid="fs-label" />}
      <Select label={t('hub.forms.fieldType')} value={field.type} onChange={(e) => onChange({ type: e.target.value as FormField['type'], hidden: e.target.value === 'hidden' })} data-testid="fs-type">
        {FIELD_TYPES.map((ty) => <option key={ty} value={ty}>{t(`hub.forms.type.${ty}`)}</option>)}
      </Select>

      {!display && field.type !== 'matrix' && field.type !== 'repeater' && field.type !== 'consent' && field.type !== 'checkbox' && field.type !== 'hidden' && (
        <Input label={t('hub.forms.placeholder')} value={field.placeholder ?? ''} onChange={(e) => onChange({ placeholder: e.target.value })} data-testid="fs-placeholder" />
      )}
      {!display && <Input label={t('hub.builder.field.help')} value={field.help ?? ''} onChange={(e) => onChange({ help: e.target.value })} data-testid="fs-help" />}

      {!display && (
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          <Toggle label={t('hub.forms.required')} checked={field.required} disabled={field.hidden} onChange={(c) => onChange({ required: c })} testId="fs-required" />
          <Toggle label={t('hub.forms.hidden')} checked={field.hidden} onChange={(c) => onChange({ hidden: c, required: c ? false : field.required })} testId="fs-hidden" />
        </div>
      )}
      {field.hidden && <Input label={t('hub.forms.default')} hint={t('hub.forms.defaultHint')} value={field.default ?? ''} onChange={(e) => onChange({ default: e.target.value || null })} data-testid="fs-default" />}

      {CHOICE.includes(field.type) && <ListEditor label={t('hub.forms.options')} items={field.options ?? []} onChange={(options) => onChange({ options })} testId="fs-options" />}

      {field.type === 'matrix' && m && (
        <div className="space-y-4" data-testid="fs-matrix">
          <ListEditor label={t('hub.builder.matrix.rows')} items={m.rows} min={1} max={20} onChange={(rows) => onChange({ matrix: { ...m, rows } })} testId="fs-matrix-rows" />
          <ListEditor label={t('hub.builder.matrix.columns')} items={m.columns} min={2} max={7} onChange={(columns) => onChange({ matrix: { ...m, columns } })} testId="fs-matrix-columns" />
          <Select label={t('hub.builder.matrix.mode')} value={m.multiple ? 'multiple' : 'single'} onChange={(e) => onChange({ matrix: { ...m, multiple: e.target.value === 'multiple' } })} data-testid="fs-matrix-mode">
            <option value="single">{t('hub.builder.matrix.single')}</option>
            <option value="multiple">{t('hub.builder.matrix.multiple')}</option>
          </Select>
          <Toggle label={t('hub.builder.matrix.requireAll')} checked={m.require_all_rows} onChange={(c) => onChange({ matrix: { ...m, require_all_rows: c } })} testId="fs-matrix-all" />
          <Input label={t('hub.builder.matrix.minAnswers')} type="number" min={0} max={m.rows.length} value={String(m.min_answers)} onChange={(e) => onChange({ matrix: { ...m, min_answers: Math.min(m.rows.length, Math.max(0, Number(e.target.value) || 0)) } })} data-testid="fs-matrix-min" />
        </div>
      )}

      {field.type === 'repeater' && r && (
        <div className="space-y-3" data-testid="fs-repeater">
          <p className="text-[0.9375rem] font-semibold">{t('hub.builder.repeater.columns')}</p>
          {r.subfields.map((s, i) => (
            <div key={i} className="space-y-2 rounded-xl border border-[var(--color-border)] p-2" data-testid={`fs-repeater-sub-${i}`}>
              <div className="grid grid-cols-2 gap-2">
                <Input aria-label={t('hub.forms.fieldLabel')} value={s.label} onChange={(e) => onChange({ repeater: { ...r, subfields: r.subfields.map((x, n) => (n === i ? { ...x, label: e.target.value } : x)) } })} data-testid={`fs-repeater-label-${i}`} />
                <Select aria-label={t('hub.forms.fieldType')} value={s.type} onChange={(e) => onChange({ repeater: { ...r, subfields: r.subfields.map((x, n) => (n === i ? { ...x, type: e.target.value as RepeaterSubfield['type'], options: e.target.value === 'select' ? x.options?.length ? x.options : [{ value: 'خيار 1', label: 'خيار 1' }, { value: 'خيار 2', label: 'خيار 2' }] : x.options } : x)) } })}>
                  {SUB_TYPES.map((ty) => <option key={ty} value={ty}>{t(`hub.forms.type.${ty}`)}</option>)}
                </Select>
              </div>
              {s.type === 'select' && <ListEditor label={t('hub.forms.options')} items={s.options ?? []} onChange={(options) => onChange({ repeater: { ...r, subfields: r.subfields.map((x, n) => (n === i ? { ...x, options } : x)) } })} testId={`fs-repeater-options-${i}`} />}
              <div className="flex items-center justify-between">
                <Toggle label={t('hub.forms.required')} checked={Boolean(s.required)} onChange={(c) => onChange({ repeater: { ...r, subfields: r.subfields.map((x, n) => (n === i ? { ...x, required: c } : x)) } })} testId={`fs-repeater-required-${i}`} />
                <Button size="sm" variant="ghost" aria-label={t('common.delete')} disabled={r.subfields.length <= 1} icon={<Trash2 className="size-4" />} onClick={() => onChange({ repeater: { ...r, subfields: r.subfields.filter((_, n) => n !== i) } })} />
              </div>
            </div>
          ))}
          <Button size="sm" variant="secondary" icon={<Plus className="size-4" />} disabled={r.subfields.length >= 6} data-testid="fs-repeater-add"
            onClick={() => { const n = r.subfields.length + 1; onChange({ repeater: { ...r, subfields: [...r.subfields, { key: `col_${n}`, type: 'text', label: `${t('hub.builder.repeater.column')} ${n}` }] } }) }}>{t('hub.builder.repeater.addColumn')}</Button>
          <div className="grid grid-cols-3 gap-2">
            <Input label={t('hub.builder.repeater.min')} type="number" min={0} value={String(r.min_rows)} onChange={(e) => onChange({ repeater: { ...r, min_rows: Math.max(0, Number(e.target.value) || 0) } })} data-testid="fs-repeater-min" />
            <Input label={t('hub.builder.repeater.max')} type="number" min={1} max={20} value={String(r.max_rows)} onChange={(e) => onChange({ repeater: { ...r, max_rows: Math.min(20, Math.max(1, Number(e.target.value) || 1)) } })} data-testid="fs-repeater-max" />
            <Input label={t('hub.builder.repeater.default')} type="number" min={0} value={String(r.default_rows)} onChange={(e) => onChange({ repeater: { ...r, default_rows: Math.max(0, Number(e.target.value) || 0) } })} data-testid="fs-repeater-default" />
          </div>
          <Input label={t('hub.builder.repeater.addLabel')} value={r.add_label ?? ''} onChange={(e) => onChange({ repeater: { ...r, add_label: e.target.value || null } })} />
        </div>
      )}

      {field.type === 'number' && (
        <div className="grid grid-cols-2 gap-2">
          <Input label={t('hub.forms.min')} type="number" value={String(v.min ?? '')} onChange={(e) => setV({ min: e.target.value === '' ? undefined : Number(e.target.value) })} data-testid="fs-min" />
          <Input label={t('hub.forms.max')} type="number" value={String(v.max ?? '')} onChange={(e) => setV({ max: e.target.value === '' ? undefined : Number(e.target.value) })} data-testid="fs-max" />
        </div>
      )}
      {TEXTUAL.includes(field.type) && (
        <div className="space-y-2">
          <div className="grid grid-cols-2 gap-2">
            <Input label={t('hub.builder.field.minLength')} type="number" min={0} value={String(v.min_length ?? '')} onChange={(e) => setV({ min_length: e.target.value === '' ? undefined : Number(e.target.value) })} data-testid="fs-minlen" />
            <Input label={t('hub.builder.field.maxLength')} type="number" min={1} value={String(v.max_length ?? '')} onChange={(e) => setV({ max_length: e.target.value === '' ? undefined : Number(e.target.value) })} data-testid="fs-maxlen" />
          </div>
          <Input label={t('hub.builder.field.pattern')} hint={t('hub.builder.field.patternHint')} dir="ltr" value={v.pattern ?? ''} onChange={(e) => setV({ pattern: e.target.value })} data-testid="fs-pattern" />
        </div>
      )}
      {!display && !structured && field.type !== 'checkbox' && (
        <Input label={t('hub.builder.field.message')} hint={t('hub.builder.field.messageHint')} value={v.message ?? ''} onChange={(e) => setV({ message: e.target.value })} data-testid="fs-message" />
      )}

      {!display && field.type !== 'hidden' || field.hidden ? (
        <div className="space-y-2 rounded-xl bg-[var(--color-surface-muted)] p-3" data-testid="fs-mapping-box">
          <p className="text-[0.9375rem] font-semibold">{t('hub.forms.mapping')}</p>
          <Select aria-label={t('hub.forms.mapping')} value={mappingGroup} onChange={(e) => onChange({ mapping: e.target.value === 'none' ? 'none' : e.target.value === 'custom' ? `custom.${field.key}` : e.target.value === 'lead' ? (presets?.targets.lead.find((k) => !fields.some((x) => x.mapping === `lead.${k}`)) ? `lead.${presets.targets.lead.find((k) => !fields.some((x) => x.mapping === `lead.${k}`))}` : 'none') : `tracking.${presets?.targets.tracking[0] ?? 'source'}` })} data-testid="fs-mapping-group">
            <option value="none">{t('hub.forms.mapNone')}</option>
            {!structured && <option value="lead">{t('hub.forms.mapLead')}</option>}
            {!structured && <option value="tracking">{t('hub.forms.mapTracking')}</option>}
            <option value="custom">{t('hub.builder.field.mapCustom')}</option>
          </Select>
          {mappingGroup === 'lead' && (
            <Select aria-label={t('hub.forms.mapLead')} value={field.mapping} onChange={(e) => onChange({ mapping: e.target.value })} data-testid="fs-mapping">
              {(presets?.targets.lead ?? []).map((k) => <option key={k} value={`lead.${k}`} disabled={fields.some((x) => x.key !== field.key && x.mapping === `lead.${k}`)}>{t(`hub.builder.leadFields.${k}`, { defaultValue: k })}</option>)}
            </Select>
          )}
          {mappingGroup === 'tracking' && (
            <Select aria-label={t('hub.forms.mapTracking')} value={field.mapping} onChange={(e) => onChange({ mapping: e.target.value })} data-testid="fs-mapping">
              {(presets?.targets.tracking ?? []).map((k) => <option key={k} value={`tracking.${k}`} disabled={fields.some((x) => x.key !== field.key && x.mapping === `tracking.${k}`)}>{k}</option>)}
            </Select>
          )}
          {mappingGroup === 'custom' && (
            <Input aria-label={t('hub.builder.field.customKey')} dir="ltr" value={field.mapping.slice(7)} onChange={(e) => onChange({ mapping: `custom.${e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^[^a-z]+/, '')}` })} data-testid="fs-mapping-custom" />
          )}
          <p className="t-caption text-[var(--color-ink-subtle)]">{mappingGroup === 'none' ? t('hub.builder.field.keptRaw') : t('hub.builder.field.mappedHint')}</p>
        </div>
      ) : null}

      {!field.hidden && (
        <div className="space-y-2">
          <LogicEditor title={t('hub.builder.logic.showWhen')} group={field.show_when} fields={fields} selfKey={field.key} onChange={(g) => onChange({ show_when: g, visible_if: null })} testId="logic-show" />
          <LogicEditor title={t('hub.builder.logic.hideWhen')} group={field.hide_when} fields={fields} selfKey={field.key} onChange={(g) => onChange({ hide_when: g })} testId="logic-hide" />
          {!display && <LogicEditor title={t('hub.builder.logic.requireWhen')} group={field.require_when} fields={fields} selfKey={field.key} onChange={(g) => onChange({ require_when: g })} testId="logic-require" />}
        </div>
      )}
    </div>
  )
}
