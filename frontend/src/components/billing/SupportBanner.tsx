import { useTranslation } from 'react-i18next'
import { LifeBuoy } from 'lucide-react'
import { Button } from '@/components/ui'
import { endSupportSession } from '@/lib/support'
import { formatDateTime } from '@/lib/format'
import { useAuth } from '@/stores/auth'

/** Every screen of a support session says so, until it ends. */
export function SupportBanner() {
  const { t, i18n } = useTranslation()
  const impersonation = useAuth((s) => s.impersonation)
  const branding = useAuth((s) => s.branding)

  if (!impersonation) return null

  return (
    <div role="status" data-testid="support-banner" className="flex flex-wrap items-center gap-2 rounded-[var(--radius-md)] border border-[color-mix(in_srgb,var(--color-danger)_40%,transparent)] bg-[color-mix(in_srgb,var(--color-danger)_10%,transparent)] px-4 py-2.5 text-[0.9375rem]">
      <LifeBuoy className="size-4 shrink-0" aria-hidden />
      <span>{t('platformAdmin.supportBanner', { company: branding?.company_name ?? '', until: formatDateTime(impersonation.expires_at, i18n.language) })}</span>
      <Button
        size="sm"
        variant="danger"
        className="ms-auto"
        data-testid="support-stop"
        onClick={async () => {
          const back = await endSupportSession()
          window.location.assign(back ? '/platform' : '/platform/login')
        }}
      >
        {t('platformAdmin.endSupport')}
      </Button>
    </div>
  )
}
