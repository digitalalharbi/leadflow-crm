import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Check, ChevronDown, Search, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet } from '@/lib/api'
import { can, useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { cn } from '@/lib/cn'

interface ProjectRow { id: string; name: string }

/**
 * Look at one project, everywhere. On a phone it opens as a sheet with a search, not a dropdown that overflows. Changing
 * it drops every cached answer first, so nothing from the last project shows while the new one loads.
 */
export function ProjectSwitcher() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const selected = useProject((s) => s.selected)
  const select = useProject((s) => s.select)
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')

  const { data } = useQuery({
    queryKey: ['project-switcher'],
    queryFn: () => apiGet<ProjectRow[] | { projects: ProjectRow[] }>('/projects', { per_page: 100 }),
    enabled: can.has(user, 'projects.view'),
    staleTime: 60_000,
  })

  const rows = Array.isArray(data) ? data : (data?.projects ?? [])

  if (rows.length < 2) return null

  const current = rows.find((row) => row.id === selected)
  const shown = rows.filter((row) => row.name.toLowerCase().includes(term.trim().toLowerCase()))

  const choose = (id: string | null) => {
    select(id)
    setOpen(false)
    setTerm('')
    void queryClient.resetQueries({ predicate: (query) => query.queryKey[0] !== 'project-switcher' })
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        data-testid="project-switcher"
        className="pressable flex min-h-11 max-w-[11rem] items-center gap-1.5 rounded-lg px-2 text-[0.9375rem] font-medium hover:bg-[var(--color-chrome-fill)]"
      >
        <Building2 className="size-4 shrink-0" aria-hidden />
        <span className="truncate">{current?.name ?? t('projectSwitcher.all')}</span>
        <ChevronDown className="size-4 shrink-0" aria-hidden />
      </button>

      {open && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-[var(--color-neutral-900)]/40 lg:items-center" role="dialog" aria-modal="true" aria-label={t('projectSwitcher.title')} onClick={(e) => e.target === e.currentTarget && setOpen(false)}>
          <div className="flex max-h-[85dvh] w-full flex-col rounded-t-[1.25rem] bg-[var(--color-surface)] pb-[env(safe-area-inset-bottom)] lg:max-w-md lg:rounded-[1.25rem]" data-testid="project-sheet">
            <div className="flex items-center justify-between px-4 pt-3">
              <h2 className="text-[1.0625rem] font-bold">{t('projectSwitcher.title')}</h2>
              <button type="button" aria-label={t('common.close')} onClick={() => setOpen(false)} className="grid size-11 place-items-center"><X className="size-5" aria-hidden /></button>
            </div>
            <div className="relative px-4 pb-2">
              <Search className="pointer-events-none absolute start-7 top-3.5 size-4 text-[var(--color-ink-muted)]" aria-hidden />
              <input type="search" autoFocus value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t('projectSwitcher.search')} aria-label={t('projectSwitcher.search')} className="field ps-9" data-testid="project-search" />
            </div>
            <ul className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
              {[{ id: null as string | null, name: t('projectSwitcher.all') }, ...shown].map((row) => (
                <li key={row.id ?? 'all'}>
                  <button type="button" onClick={() => choose(row.id)} data-testid={`project-option-${row.id ?? 'all'}`} className={cn('flex min-h-12 w-full items-center justify-between gap-2 rounded-lg px-3 text-start text-[1rem] hover:bg-[var(--color-surface-muted)]', row.id === selected && 'font-semibold')}>
                    <span className="min-w-0 truncate">{row.name}</span>
                    {row.id === selected && <Check className="size-5 shrink-0 text-[var(--brand)]" aria-hidden />}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </>
  )
}
