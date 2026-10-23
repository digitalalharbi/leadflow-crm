import { useRef, useState } from 'react'
import { Check, ImagePlus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Input, Select } from '@/components/ui'
import { HEADER_LAYOUTS, PALETTE, uploadFormAsset, type FormDesign } from '@/features/hub'
import { useApiError } from '@/hooks/useApiError'
import { contrast, inkOn, PALETTE_HEX } from '@/lib/formDesign'
import { cn } from '@/lib/cn'
import { useUi } from '@/stores/ui'

function ImageField({ label, value, onChange, testId }: { label: string; value: string | null; onChange: (url: string | null) => void; testId: string }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const input = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)

  const pick = async (file: File | undefined) => {
    if (!file) return
    setBusy(true)
    try { onChange((await uploadFormAsset(file)).url) } catch (caught) { toast(translateError(caught), 'error') } finally { setBusy(false); if (input.current) input.current.value = '' }
  }

  return (
    <div data-testid={testId}>
      <p className="mb-1.5 text-[0.9375rem] font-semibold">{label}</p>
      <div className="flex items-center gap-3">
        <div className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-lg border border-dashed border-[var(--color-border)] bg-[var(--color-surface-muted)]">
          {value ? <img src={value} alt="" className="max-h-full max-w-full object-contain" data-testid={`${testId}-preview`} /> : <ImagePlus className="size-5 text-[var(--color-ink-subtle)]" aria-hidden />}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="secondary" loading={busy} onClick={() => input.current?.click()} data-testid={`${testId}-upload`}>{t('hub.builder.design.upload')}</Button>
          {value && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={() => onChange(null)} data-testid={`${testId}-remove`}>{t('common.delete')}</Button>}
        </div>
        <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" tabIndex={-1} aria-label={label} onChange={(e) => void pick(e.target.files?.[0])} data-testid={`${testId}-file`} />
      </div>
      <p className="t-caption mt-1 text-[var(--color-ink-subtle)]">{t('hub.builder.design.imageHint')}</p>
    </div>
  )
}

function Swatches({ label, value, onChange, testId }: { label: string; value: string; onChange: (token: (typeof PALETTE)[number]) => void; testId: string }) {
  const { t } = useTranslation()

  return (
    <div data-testid={testId}>
      <p className="mb-1.5 text-[0.9375rem] font-semibold">{label}</p>
      <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={label}>
        {PALETTE.map((token) => (
          <button key={token} type="button" role="radio" aria-checked={value === token} aria-label={t(`hub.builder.design.colors.${token}`)} onClick={() => onChange(token)} data-testid={`${testId}-${token}`}
            className={cn('grid size-9 place-items-center rounded-full ring-offset-2', value === token && 'ring-2 ring-[var(--color-ink)]')} style={{ background: PALETTE_HEX[token], color: inkOn(PALETTE_HEX[token]) }}>
            {value === token && <Check className="size-4" aria-hidden />}
          </button>
        ))}
      </div>
    </div>
  )
}

const Choice = <T extends string>({ label, value, options, onChange, testId, names }: { label: string; value: T; options: readonly T[]; onChange: (v: T) => void; testId: string; names: (v: T) => string }) => (
  <Select label={label} value={value} onChange={(e) => onChange(e.target.value as T)} data-testid={testId}>
    {options.map((o) => <option key={o} value={o}>{names(o)}</option>)}
  </Select>
)

/** The header and the look of the form: choices from closed lists, a logo and a hero image that are uploaded, never any CSS. */
export function DesignPanel({ design, logoUrl, imageUrl, onDesign, onLogo, onImage }: {
  design: FormDesign
  logoUrl: string | null
  imageUrl: string | null
  onDesign: (d: FormDesign) => void
  onLogo: (url: string | null) => void
  onImage: (url: string | null) => void
}) {
  const { t } = useTranslation()
  const h = design.header
  const s = design.style
  const setH = (p: Partial<FormDesign['header']>) => onDesign({ ...design, header: { ...h, ...p } })
  const setS = (p: Partial<FormDesign['style']>) => onDesign({ ...design, style: { ...s, ...p } })
  const n = (group: string) => (v: string) => t(`hub.builder.design.${group}.${v}`)
  const ratio = contrast(PALETTE_HEX[s.primary], inkOn(PALETTE_HEX[s.primary]))

  return (
    <div className="space-y-6" data-testid="design-panel">
      <section className="space-y-4">
        <h3 className="font-extrabold">{t('hub.builder.design.header')}</h3>
        <div role="radiogroup" aria-label={t('hub.builder.design.headerLayout')} className="grid grid-cols-2 gap-2" data-testid="header-layouts">
          {HEADER_LAYOUTS.map((l) => (
            <button key={l} type="button" role="radio" aria-checked={h.layout === l} onClick={() => setH({ layout: l })} data-testid={`header-layout-${l}`}
              className={cn('pressable rounded-xl border p-2 text-start text-[0.875rem] font-semibold', h.layout === l ? 'border-[var(--brand)] bg-[var(--brand-soft)]' : 'border-[var(--color-border)]')}>
              {t(`hub.builder.design.layouts.${l}`)}
            </button>
          ))}
        </div>
        <ImageField label={t('hub.builder.design.logo')} value={logoUrl} onChange={onLogo} testId="design-logo" />
        <div className="grid grid-cols-2 gap-2">
          <Choice label={t('hub.builder.design.logoSize')} value={h.logo_size} options={['sm', 'md', 'lg'] as const} onChange={(v) => setH({ logo_size: v })} testId="design-logo-size" names={n('sizes')} />
          <Choice label={t('hub.builder.design.logoAlign')} value={h.logo_align} options={['start', 'center', 'end'] as const} onChange={(v) => setH({ logo_align: v })} testId="design-logo-align" names={n('align')} />
        </div>
        <ImageField label={t('hub.builder.design.hero')} value={imageUrl} onChange={onImage} testId="design-hero" />
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={h.show_logo} onChange={(e) => setH({ show_logo: e.target.checked })} data-testid="design-show-logo" />{t('hub.builder.design.showLogo')}</label>
          <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={h.show_project_name} onChange={(e) => setH({ show_project_name: e.target.checked })} data-testid="design-show-project" />{t('hub.builder.design.showProject')}</label>
        </div>
        <Choice label={t('hub.builder.design.background')} value={h.background} options={['none', 'soft', 'brand', 'image'] as const} onChange={(v) => setH({ background: v })} testId="design-background" names={n('backgrounds')} />
        <Input label={t('hub.builder.design.location')} value={h.location ?? ''} onChange={(e) => setH({ location: e.target.value || null })} data-testid="design-location" />
        <Input label={t('hub.builder.design.priceFrom')} type="number" min={0} value={h.price_from === null ? '' : String(h.price_from)} onChange={(e) => setH({ price_from: e.target.value === '' ? null : Number(e.target.value) })} data-testid="design-price" />
        <Input label={t('hub.builder.design.trust')} value={h.trust_note ?? ''} onChange={(e) => setH({ trust_note: e.target.value || null })} data-testid="design-trust" />
      </section>

      <section className="space-y-4">
        <h3 className="font-extrabold">{t('hub.builder.design.style')}</h3>
        <Swatches label={t('hub.builder.design.primary')} value={s.primary} onChange={(primary) => setS({ primary })} testId="design-primary" />
        <Swatches label={t('hub.builder.design.accent')} value={s.accent} onChange={(accent) => setS({ accent })} testId="design-accent" />
        {ratio < 4.5 && <p role="status" className="t-caption text-[var(--color-warning)]" data-testid="design-contrast">{t('hub.builder.design.lowContrast')}</p>}
        <div className="grid grid-cols-2 gap-2">
          <Choice label={t('hub.builder.design.surface')} value={s.surface} options={['card', 'flat'] as const} onChange={(v) => setS({ surface: v })} testId="design-surface" names={n('surfaces')} />
          <Choice label={t('hub.builder.design.theme')} value={s.theme} options={['light', 'brand', 'neutral'] as const} onChange={(v) => setS({ theme: v })} testId="design-theme" names={n('themes')} />
          <Choice label={t('hub.builder.design.radius')} value={s.radius} options={['none', 'sm', 'md', 'lg', 'xl'] as const} onChange={(v) => setS({ radius: v })} testId="design-radius" names={n('radii')} />
          <Choice label={t('hub.builder.design.inputSize')} value={s.input_size} options={['compact', 'comfortable', 'large'] as const} onChange={(v) => setS({ input_size: v })} testId="design-input-size" names={n('inputSizes')} />
          <Choice label={t('hub.builder.design.fieldSpacing')} value={s.field_spacing} options={['tight', 'normal', 'loose'] as const} onChange={(v) => setS({ field_spacing: v })} testId="design-field-spacing" names={n('spacing')} />
          <Choice label={t('hub.builder.design.sectionSpacing')} value={s.section_spacing} options={['tight', 'normal', 'loose'] as const} onChange={(v) => setS({ section_spacing: v })} testId="design-section-spacing" names={n('spacing')} />
          <Choice label={t('hub.builder.design.button')} value={s.button} options={['full', 'auto'] as const} onChange={(v) => setS({ button: v })} testId="design-button" names={n('buttons')} />
        </div>
      </section>
    </div>
  )
}
