import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Check, ChevronsUpDown, Network } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useCompanies } from '@/features/companies'
import { useCompany } from '@/stores/company'
import { Dropdown, DropdownItem } from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * Which company the whole product is looking at.
 *
 * Appears only when there is something to choose: a workspace with no
 * companies has no switcher, and a person held to a single company has a name
 * and no menu. A control that offers one option is a control that says
 * "there is more, and you cannot have it" — which is true, and not worth a
 * chip in the header.
 *
 * Changing it refetches everything, because every list and every figure
 * answers to it. Partial refreshes would leave a dashboard on one company
 * beside a lead list on another.
 */
export function CompanySwitcher() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { data } = useCompanies()
  const selected = useCompany((s) => s.selected)
  const select = useCompany((s) => s.select)

  const companies = data?.companies ?? []
  const current = companies.find((company) => company.id === selected)

  // A choice that no longer exists — the company was deactivated, or this
  // person was taken out of it — is dropped rather than sent on every request.
  useEffect(() => {
    if (data && selected && !current) {
      select(null)
      void queryClient.invalidateQueries()
    }
  }, [data, selected, current, select, queryClient])

  if (companies.length === 0) return null

  const choose = (id: string | null) => {
    if (id === selected) return
    select(id)
    // Everything but the list of companies itself, which is the one answer that
    // does not depend on the choice.
    void queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'companies' })
  }

  // One company, and nothing to switch to: just say where you are.
  if (companies.length === 1 && data?.restricted) {
    return (
      <span
        className="hidden items-center gap-1.5 px-2 text-[0.9375rem] font-medium text-[var(--color-ink-muted)] sm:inline-flex"
        data-testid="company-label"
      >
        <Network className="size-4" aria-hidden />
        {companies[0].name}
      </span>
    )
  }

  return (
    <Dropdown
      trigger={({ toggle }) => (
        <button
          type="button"
          onClick={toggle}
          data-testid="company-switcher"
          aria-label={t('company.switch')}
          className="pressable flex h-9 items-center gap-2 rounded-[var(--radius-control)] bg-[var(--color-surface)] px-3 text-[0.9375rem] font-medium shadow-[var(--shadow-card)]"
        >
          <span
            aria-hidden
            className="size-2 rounded-full"
            style={{ background: current?.color ?? 'var(--brand)' }}
          />
          <span className="max-w-40 truncate">{current?.name ?? t('company.all')}</span>
          <ChevronsUpDown className="size-3.5 text-[var(--color-ink-subtle)]" aria-hidden />
        </button>
      )}
    >
      {({ close }) => (
        <>
          {!data?.restricted && (
            <DropdownItem
              onClick={() => {
                choose(null)
                close()
              }}
            >
              <span className="flex w-full items-center justify-between gap-3" data-testid="company-option-all">
                {t('company.all')}
                {!selected && <Check className="size-4" aria-hidden />}
              </span>
            </DropdownItem>
          )}
          {companies.map((company) => (
            <DropdownItem
              key={company.id}
              onClick={() => {
                choose(company.id)
                close()
              }}
            >
              <span
                className={cn('flex w-full items-center justify-between gap-3')}
                data-testid={`company-option-${company.id}`}
              >
                <span className="flex items-center gap-2">
                  <span aria-hidden className="size-2 rounded-full" style={{ background: company.color ?? 'var(--brand)' }} />
                  {company.name}
                </span>
                {selected === company.id && <Check className="size-4" aria-hidden />}
              </span>
            </DropdownItem>
          ))}
        </>
      )}
    </Dropdown>
  )
}
