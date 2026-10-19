import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, Flame } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { fetchCompanyProjects, useCompanies, type CompanyMetrics } from '@/features/companies'
import { useCompany } from '@/stores/company'
import { Card, Skeleton } from '@/components/ui'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

/**
 * Holding → company → project, as the first thing on the dashboard.
 *
 * Two states of one strip. With no company chosen it is the holding: one tile
 * per company, each a way in. With one chosen it is that company: where you are,
 * a way back, and its projects with their own numbers, each a way into the
 * project's workspace. Three levels and one move per level — the same drill the
 * switcher makes, shown where somebody is already looking.
 *
 * Absent entirely for a workspace with no companies, so nobody who never asked
 * for this structure is shown a strip about it.
 */
export function HoldingOverview() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { data } = useCompanies()
  const selected = useCompany((s) => s.selected)
  const select = useCompany((s) => s.select)

  const companies = data?.companies ?? []

  const move = (id: string | null) => {
    select(id)
    void queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'companies' })
  }

  if (companies.length === 0) return null

  const current = companies.find((company) => company.id === selected)

  if (current) {
    return <CompanyLevel companyId={current.id} name={current.name} color={current.color} onBack={() => move(null)} canGoBack={!data?.restricted} />
  }

  const metrics = new Map((data?.metrics ?? []).map((row) => [row.id, row]))

  return (
    <section className="space-y-2" data-testid="holding-overview">
      <h2 className="text-[1.0625rem] font-semibold">{t('company.companiesOverview')}</h2>
      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
        {companies.map((company) => {
          const m = metrics.get(company.id)
          return (
            <button
              key={company.id}
              type="button"
              onClick={() => move(company.id)}
              data-testid={`holding-company-${company.id}`}
              className="pressable relative overflow-hidden rounded-[var(--radius-card)] bg-[var(--color-surface)] p-3 text-start shadow-[var(--shadow-card)]"
            >
              <span aria-hidden className="absolute inset-y-0 start-0 w-1" style={{ background: company.color ?? 'var(--brand)' }} />
              <span className="block truncate text-[1rem] font-semibold">{company.name}</span>
              <Tiles m={m} />
            </button>
          )
        })}
      </div>
    </section>
  )
}

function Tiles({ m }: { m?: CompanyMetrics }) {
  const { t } = useTranslation()
  return (
    <span className="mt-2 flex items-end justify-between gap-2">
      <span>
        <span className="block text-[1.375rem] font-semibold leading-none tabular-nums">{formatNumber(m?.open ?? 0)}</span>
        <span className="text-[0.8125rem] text-[var(--color-ink-muted)]">{t('company.open')}</span>
      </span>
      <span className={cn('flex items-center gap-1 text-[0.9375rem] tabular-nums', (m?.hot ?? 0) > 0 && 'text-[var(--color-warning)]')}>
        <Flame className="size-3.5" aria-hidden />
        {formatNumber(m?.hot ?? 0)}
      </span>
      <span className="text-[0.9375rem] tabular-nums text-[var(--color-ink-muted)]">
        {formatNumber(m?.conversion_rate ?? 0)}%
      </span>
    </span>
  )
}

function CompanyLevel({
  companyId, name, color, onBack, canGoBack,
}: {
  companyId: string
  name: string
  color: string | null
  onBack: () => void
  canGoBack: boolean
}) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({
    queryKey: ['company-projects', companyId],
    queryFn: () => fetchCompanyProjects(companyId),
  })

  return (
    <section data-testid="company-level">
      <Card>
        <div className="p-4">
          <nav aria-label="breadcrumb" className="flex items-center gap-1.5 text-[0.9375rem]">
            {canGoBack ? (
              <button type="button" onClick={onBack} className="text-[var(--color-ink-muted)] hover:underline" data-testid="back-to-holding">
                {t('company.holding')}
              </button>
            ) : (
              <span className="text-[var(--color-ink-muted)]">{t('company.holding')}</span>
            )}
            <ChevronLeft className="size-3.5 text-[var(--color-ink-subtle)] ltr:rotate-180" aria-hidden />
            <span className="flex items-center gap-1.5 font-semibold">
              <span aria-hidden className="size-2 rounded-full" style={{ background: color ?? 'var(--brand)' }} />
              {name}
            </span>
          </nav>

          {isLoading ? (
            <Skeleton className="mt-3 h-20" />
          ) : (data?.projects.length ?? 0) === 0 ? (
            <p className="t-caption mt-3">{t('company.noProjects')}</p>
          ) : (
            <ul className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3" data-testid="company-projects">
              {data?.projects.map((project) => (
                <li key={project.id}>
                  <Link
                    to={`/projects/${project.id}`}
                    data-testid={`company-project-${project.id}`}
                    className="pressable block rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] px-3 py-2"
                  >
                    <span className="block truncate text-[0.9375rem] font-semibold">{project.name}</span>
                    <span className="mt-1 flex items-center justify-between gap-2 text-[0.875rem] tabular-nums text-[var(--color-ink-muted)]">
                      <span>{formatNumber(project.open)} {t('company.open')}</span>
                      <span className={cn(project.hot > 0 && 'text-[var(--color-warning)]')}>
                        <Flame className="me-0.5 inline size-3" aria-hidden />
                        {formatNumber(project.hot)}
                      </span>
                      <span>{formatNumber(project.conversion_rate)}%</span>
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>
    </section>
  )
}
