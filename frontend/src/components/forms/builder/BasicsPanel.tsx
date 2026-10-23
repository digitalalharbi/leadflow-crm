import { useTranslation } from 'react-i18next'
import { Input, Select, Textarea } from '@/components/ui'
import type { LandingForm } from '@/features/hub'

export type Basics = Pick<LandingForm, 'name' | 'headline' | 'description' | 'benefits' | 'cta_label' | 'consent_required' | 'consent_text' | 'privacy_url'> & { project_id: string }

/** The words of the page: what the form is called, what it says above the questions, the button, and the consent. */
export function BasicsPanel({ basics, projects, onChange }: { basics: Basics; projects: { id: string; name: string }[]; onChange: (patch: Partial<Basics>) => void }) {
  const { t } = useTranslation()

  return (
    <div className="space-y-4" data-testid="basics-panel">
      <Input label={t('hub.forms.name')} value={basics.name} onChange={(e) => onChange({ name: e.target.value })} data-testid="form-name" />
      <Select label={t('tracking.project')} value={basics.project_id} onChange={(e) => onChange({ project_id: e.target.value })} data-testid="form-project">
        <option value="">—</option>
        {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
      </Select>
      <Input label={t('hub.forms.headline')} value={basics.headline ?? ''} onChange={(e) => onChange({ headline: e.target.value })} data-testid="form-headline" />
      <Textarea label={t('hub.forms.description')} rows={2} value={basics.description ?? ''} onChange={(e) => onChange({ description: e.target.value })} data-testid="form-description" />
      <Textarea label={t('hub.forms.benefits')} hint={t('hub.forms.benefitsHint')} rows={3} value={(basics.benefits ?? []).join('\n')} onChange={(e) => onChange({ benefits: e.target.value.split('\n').slice(0, 6) })} data-testid="form-benefits" />
      <Input label={t('hub.forms.cta')} value={basics.cta_label ?? ''} onChange={(e) => onChange({ cta_label: e.target.value })} data-testid="form-cta" />
      <label className="flex items-center gap-2 text-[0.9375rem]"><input type="checkbox" className="size-5" checked={basics.consent_required} onChange={(e) => onChange({ consent_required: e.target.checked })} data-testid="form-consent" />{t('hub.forms.consentRequired')}</label>
      <Input label={t('hub.forms.consentText')} value={basics.consent_text ?? ''} onChange={(e) => onChange({ consent_text: e.target.value })} />
      <Input label={t('hub.forms.privacyUrl')} dir="ltr" placeholder="https://" value={basics.privacy_url ?? ''} onChange={(e) => onChange({ privacy_url: e.target.value })} />
    </div>
  )
}
