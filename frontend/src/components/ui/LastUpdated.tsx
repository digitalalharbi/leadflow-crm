import { useTranslation } from 'react-i18next'
import { formatRelative } from '@/lib/format'

/** How fresh what is on screen really is. */
export function LastUpdated({ at }: { at: number | undefined }) {
  const { t, i18n } = useTranslation()

  if (!at) return null

  return <p className="t-caption text-[var(--color-ink-subtle)]" data-testid="last-updated">{t('common.lastUpdated', { when: formatRelative(new Date(at).toISOString(), i18n.language) })}</p>
}
