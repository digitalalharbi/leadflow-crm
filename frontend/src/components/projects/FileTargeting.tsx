import { useTranslation } from 'react-i18next'
import { AUDIENCES, FILE_STAGES, type Audience } from '@/features/files'
import { Input, Select } from '@/components/ui'
import { cn } from '@/lib/cn'

export interface Targeting {
  campaign: string
  stages: string[]
  audience: Audience | ''
}

export const NO_TARGETING: Targeting = { campaign: '', stages: [], audience: '' }

/**
 * Who a file is for, beyond its language.
 *
 * Everything here is optional and empty means "anybody" — which is every file
 * uploaded before this existed, and the right answer for most of them. It is
 * filled in only for the brochure made for one campaign, the price sheet that
 * only makes sense once somebody is qualified, or the deck for investors, so
 * that choosing is a matter of matching and not of a rep remembering.
 */
export function FileTargeting({ value, onChange }: { value: Targeting; onChange: (next: Targeting) => void }) {
  const { t } = useTranslation()

  return (
    <div className="space-y-3" data-testid="file-targeting">
      <div className="grid gap-3 sm:grid-cols-2">
        <Input
          label={t('files.campaign')}
          hint={t('files.campaignHint')}
          value={value.campaign}
          data-testid="file-campaign"
          onChange={(e) => onChange({ ...value, campaign: e.target.value })}
        />
        <Select
          label={t('files.audience')}
          value={value.audience}
          data-testid="file-audience"
          onChange={(e) => onChange({ ...value, audience: e.target.value as Targeting['audience'] })}
        >
          <option value="">{t('files.anyone')}</option>
          {AUDIENCES.map((audience) => (
            <option key={audience} value={audience}>{t(`files.audienceOf.${audience}`)}</option>
          ))}
        </Select>
      </div>

      <fieldset className="space-y-1.5">
        <legend className="t-small font-medium">{t('files.stages')}</legend>
        <p className="t-caption">{t('files.stagesHint')}</p>
        <div className="flex flex-wrap gap-1.5" data-testid="file-stages">
          {FILE_STAGES.map((stage) => {
            const chosen = value.stages.includes(stage)
            return (
              <button
                key={stage}
                type="button"
                aria-pressed={chosen}
                data-testid={`file-stage-${stage}`}
                onClick={() =>
                  onChange({
                    ...value,
                    stages: chosen ? value.stages.filter((s) => s !== stage) : [...value.stages, stage],
                  })
                }
                className={cn(
                  'pressable rounded-full border px-2.5 py-0.5 text-[0.75rem]',
                  chosen
                    ? 'border-[var(--brand)] bg-[var(--brand)] text-[var(--brand-ink)]'
                    : 'border-[var(--color-border)] text-[var(--color-ink-muted)]',
                )}
              >
                {t(`status.${stage}`)}
              </button>
            )
          })}
        </div>
      </fieldset>
    </div>
  )
}
