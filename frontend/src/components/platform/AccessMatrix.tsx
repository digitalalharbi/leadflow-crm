import { useQuery } from '@tanstack/react-query'
import { Check, Minus } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { Skeleton } from '@/components/ui'

interface Matrix {
  roles: string[]
  rows: Array<{ module: string; enabled: boolean; roles: Record<string, boolean> }>
}

/** Who can reach what in this company: the chain the API applies, read out. Project limits narrow it further for each person. */
export function AccessMatrix({ tenantId }: { tenantId: string }) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({
    queryKey: ['platform-access-matrix', tenantId],
    queryFn: () => apiGet<Matrix>(`/super-admin/tenants/${tenantId}/access-matrix`),
  })

  if (isLoading || !data) return <Skeleton className="h-32" />

  return (
    <section data-testid="access-matrix">
      <h4 className="label-caps mb-1">{t('platformAdmin.matrix')}</h4>
      <p className="t-caption mb-2">{t('platformAdmin.matrixHint')}</p>
      <div className="overflow-x-auto rounded-lg border border-[var(--color-line)]">
        <table className="w-full min-w-[40rem] text-[0.875rem]">
          <thead>
            <tr className="border-b border-[var(--color-line)]">
              <th className="sticky start-0 bg-[var(--color-surface)] p-2 text-start font-semibold">{t('platformAdmin.modules')}</th>
              {data.roles.map((role) => <th key={role} className="p-2 font-semibold">{t(`roles.${role}`, { defaultValue: role })}</th>)}
            </tr>
          </thead>
          <tbody>
            {data.rows.map((row) => (
              <tr key={row.module} className="border-b border-[var(--color-line)] last:border-0" data-enabled={row.enabled}>
                <td className="sticky start-0 bg-[var(--color-surface)] p-2 font-medium">{t(`platformAdmin.module.${row.module}`)}</td>
                {data.roles.map((role) => (
                  <td key={role} className="p-2 text-center">
                    {row.roles[role] ? <Check className="mx-auto size-4 text-[var(--brand)]" aria-label={t('common.yes')} /> : <Minus className="mx-auto size-4 text-[var(--color-ink-subtle)]" aria-label={t('common.no')} />}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
