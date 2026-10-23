import { Copy, Link2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Select } from '@/components/ui'
import { DEFAULT_SETTINGS, type FormField, type FormSettings } from '@/features/hub'
import { DIAL } from '@/lib/phoneCountries'
import type { TrackingLink } from '@/features/tracking'

const HIDDEN: { key: string; mapping: string; token: string }[] = [
  { key: 'project', mapping: 'tracking.project', token: '@project' },
  { key: 'unit', mapping: 'tracking.unit', token: '@unit' },
  { key: 'source', mapping: 'tracking.source', token: '@source' },
]

/** How the form is measured: which link opens it, the hidden fields the server fills from that link, the steps and the country, the dataLayer. */
export function TrackingPanel({ fields, settings, links, onSettings, onToggleHidden, onNewLink, onCopy }: {
  fields: FormField[]
  settings: FormSettings
  links: TrackingLink[]
  onSettings: (s: FormSettings) => void
  onToggleHidden: (preset: { key: string; mapping: string; token: string }, on: boolean) => void
  onNewLink: () => void
  onCopy: (url: string) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-5" data-testid="tracking-panel">
      <section>
        <h3 className="mb-1 font-extrabold">{t('hub.builder.tracking.links')}</h3>
        <p className="t-caption mb-2 text-[var(--color-ink-subtle)]">{t('hub.builder.tracking.linksHint')}</p>
        {links.length === 0 ? <p className="text-[0.9375rem]" data-testid="tracking-no-links">{t('hub.builder.tracking.noLinks')}</p> : (
          <ul className="space-y-1.5" data-testid="tracking-links">
            {links.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-2 rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5">
                <span className="min-w-0"><span className="block truncate text-[0.9375rem] font-semibold">{l.name}</span><span className="block truncate text-[0.8125rem] text-[var(--color-ink-subtle)]" dir="ltr">{l.landing_url ?? l.url}</span></span>
                <Button size="sm" variant="secondary" icon={<Copy className="size-4" />} onClick={() => onCopy(l.url)} data-testid={`copy-form-link-${l.token}`}>{t('tracking.copy')}</Button>
              </li>
            ))}
          </ul>
        )}
        <Button className="mt-2" size="sm" variant="secondary" icon={<Link2 className="size-4" />} onClick={onNewLink} data-testid="tracking-new-link">{t('hub.builder.tracking.newLink')}</Button>
      </section>

      <section>
        <h3 className="mb-1 font-extrabold">{t('hub.builder.tracking.hidden')}</h3>
        <p className="t-caption mb-2 text-[var(--color-ink-subtle)]">{t('hub.builder.tracking.hiddenHint')}</p>
        {HIDDEN.map((h) => {
          const on = fields.some((f) => f.mapping === h.mapping)

          return (
            <label key={h.key} className="flex items-center gap-2 py-1 text-[0.9375rem]">
              <input type="checkbox" className="size-5" checked={on} onChange={(e) => onToggleHidden(h, e.target.checked)} data-testid={`hidden-${h.key}`} />{t(`hub.builder.tracking.fill.${h.key}`)}
            </label>
          )
        })}
      </section>

      <section className="space-y-3">
        <Select label={t('hub.builder.tracking.mode')} value={settings.mode} onChange={(e) => onSettings({ ...settings, mode: e.target.value as 'single' | 'multi' })} data-testid="settings-mode">
          <option value="single">{t('hub.builder.tracking.single')}</option>
          <option value="multi">{t('hub.builder.tracking.multi')}</option>
        </Select>
        <Select label={t('hub.builder.tracking.country')} value={settings.default_country} onChange={(e) => onSettings({ ...settings, default_country: e.target.value })} data-testid="settings-country">
          {Object.keys(DIAL).map((c) => <option key={c} value={c}>{`${c} +${DIAL[c]}`}</option>)}
        </Select>
        <label className="flex items-start gap-2 text-[0.9375rem]"><input type="checkbox" className="mt-0.5 size-5" checked={settings.gtm.events} onChange={(e) => onSettings({ ...DEFAULT_SETTINGS, ...settings, gtm: { events: e.target.checked } })} data-testid="settings-gtm" />
          <span>{t('hub.builder.tracking.gtm')}<span className="t-caption block text-[var(--color-ink-subtle)]">{t('hub.builder.tracking.gtmHint')}</span></span></label>
      </section>
    </div>
  )
}
