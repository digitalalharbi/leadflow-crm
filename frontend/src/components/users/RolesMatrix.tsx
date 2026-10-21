import { useQuery } from '@tanstack/react-query'
import { Check, Eye, FolderLock, UserRoundX } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Drawer, Skeleton } from '@/components/ui'
import { fetchRoles } from '@/features/roles'
import { cn } from '@/lib/cn'

/**
 * Who can do what, as a grid: a row per role, a column per area, and in each cell the actions that
 * role holds. It is read from the same table the API enforces, so what it shows is what happens.
 */
export function RolesMatrix({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const { data } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles, enabled: open, staleTime: 5 * 60_000 })

  return (
    <Drawer open={open} onClose={onClose} title={t('roles.matrixTitle')} description={t('roles.matrixHint')} size="lg" testId="roles-matrix">
      {!data ? (
        <Skeleton className="h-64" />
      ) : (
        <div className="scroll-x" data-testid="roles-matrix-grid">
          <table className="w-full border-collapse text-[0.875rem]">
            <thead>
              <tr>
                <th className="sticky start-0 bg-[var(--color-surface)] p-1.5 text-start">{t('users.role')}</th>
                {data.resources.map((resource) => (
                  <th key={resource} className="p-1.5 text-center font-medium text-[var(--color-ink-muted)]">{t(`roles.resource.${resource}`)}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.roles.map((role) => (
                <tr key={role.role} className="border-t border-[var(--color-line)]" data-testid={`role-row-${role.role}`}>
                  <th scope="row" className="sticky start-0 bg-[var(--color-surface)] p-1.5 text-start align-top">
                    <span className="block font-semibold">{t(`roles.${role.role}`)}</span>
                    <span className="flex flex-wrap gap-1">
                      {role.scoped && <Badge tone="info"><FolderLock className="size-3" aria-hidden />{t('roles.scoped')}</Badge>}
                      {role.external && <Badge tone="warning"><UserRoundX className="size-3" aria-hidden />{t('roles.external')}</Badge>}
                    </span>
                  </th>
                  {data.resources.map((resource) => {
                    const actions = role.grants[resource] ?? []

                    return (
                      <td key={resource} className={cn('p-1 text-center align-top', actions.length === 0 && 'text-[var(--color-ink-subtle)]')} data-testid={`cell-${role.role}-${resource}`} data-actions={actions.join(',')}>
                        {actions.length === 0 ? '—' : actions.length === data.actions.length ? (
                          <Check className="mx-auto size-4 text-[var(--brand)]" aria-label={t('roles.all')} />
                        ) : (
                          <span className="inline-flex flex-wrap justify-center gap-0.5">
                            {actions.map((action) => (
                              <span key={action} title={t(`roles.action.${action}`)} className="rounded bg-[var(--color-chrome-fill)] px-1 text-[0.75rem] font-semibold">
                                {action === 'view' ? <Eye className="inline size-3" aria-label={t('roles.action.view')} /> : t(`roles.action.${action}`)}
                              </span>
                            ))}
                          </span>
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Drawer>
  )
}
