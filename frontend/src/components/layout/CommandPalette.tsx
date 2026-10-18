import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Building2, Contact2, Search, User2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { search } from '@/features/search'
import { useDebounced } from '@/hooks/useDebounced'
import { Modal } from '@/components/ui'
import { cn } from '@/lib/cn'

/**
 * One box, everything findable.
 *
 * A rep with a customer on the phone has the number and nothing else. Making
 * them choose a screen, then a filter, then type is three decisions between a
 * ringing phone and a name — and what people do instead is answer without
 * knowing who it is.
 *
 * The number is almost never in the format we stored it in, which the server
 * handles: the same search the leads list uses, so the two cannot disagree
 * about what a phone number matches while somebody is waiting.
 */
export function CommandPalette() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const [open, setOpen] = useState(false)
  const [term, setTerm] = useState('')
  const [highlighted, setHighlighted] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)

  const debounced = useDebounced(term, 200)

  const { data } = useQuery({
    queryKey: ['search', debounced],
    queryFn: () => search(debounced),
    enabled: open && debounced.trim().length >= 2,
  })

  /** Flattened, because arrow keys move through what is on screen, not groups. */
  const rows = useMemo(() => {
    if (!data) return []

    return [
      ...data.leads.map((lead) => ({
        key: `lead-${lead.id}`,
        to: `/leads/${lead.id}`,
        icon: <Contact2 className="size-4" aria-hidden />,
        label: lead.name,
        hint: lead.phone ?? undefined,
      })),
      ...data.projects.map((project) => ({
        key: `project-${project.id}`,
        to: `/projects`,
        icon: <Building2 className="size-4" aria-hidden />,
        label: project.name,
        hint: undefined,
      })),
      ...data.people.map((person) => ({
        key: `person-${person.id}`,
        to: `/users`,
        icon: <User2 className="size-4" aria-hidden />,
        label: person.name,
        hint: person.role ?? undefined,
      })),
    ]
  }, [data])

  // Opening is global; everything else only matters while it is open.
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setOpen((previous) => !previous)
      }
    }

    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [])

  useEffect(() => {
    setHighlighted(0)
  }, [debounced])

  useEffect(() => {
    if (open) {
      // The point of a palette is typing immediately; a box you have to click
      // into first is a slower version of the page you were already on.
      requestAnimationFrame(() => inputRef.current?.focus())
    } else {
      setTerm('')
    }
  }, [open])

  function go(to: string) {
    setOpen(false)
    navigate(to)
  }

  function onInputKey(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setHighlighted((i) => Math.min(i + 1, Math.max(rows.length - 1, 0)))
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlighted((i) => Math.max(i - 1, 0))
    } else if (event.key === 'Enter' && rows[highlighted]) {
      event.preventDefault()
      go(rows[highlighted].to)
    }
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={t('search.open')}
        data-testid="open-command-palette"
        className="chrome-circle"
      >
        <Search className="size-[1.125rem]" aria-hidden />
      </button>

      <Modal open={open} onClose={() => setOpen(false)} title={t('search.title')}>
        <div className="space-y-3">
          <input
            ref={inputRef}
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            onKeyDown={onInputKey}
            placeholder={t('search.placeholder')}
            data-testid="command-palette-input"
            className="h-11 w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface-sunken)] px-3 text-[0.9375rem] outline-none focus:border-[var(--brand)]"
          />

          {debounced.trim().length >= 2 && rows.length === 0 && (
            <p className="t-caption px-1 py-3">{t('search.nothing')}</p>
          )}

          {rows.length > 0 && (
            <ul className="max-h-80 overflow-y-auto" data-testid="command-palette-results">
              {rows.map((row, index) => (
                <li key={row.key}>
                  <button
                    type="button"
                    onMouseEnter={() => setHighlighted(index)}
                    onClick={() => go(row.to)}
                    className={cn(
                      'flex w-full items-center gap-3 rounded-[var(--radius-control)] px-3 py-2.5 text-start',
                      index === highlighted
                        ? 'bg-[var(--color-surface-sunken)]'
                        : 'hover:bg-[var(--color-surface-sunken)]',
                    )}
                  >
                    <span className="text-[var(--color-ink-subtle)]">{row.icon}</span>
                    <span className="min-w-0 flex-1 truncate text-[0.875rem]">{row.label}</span>
                    {row.hint && (
                      <span className="t-caption shrink-0 tabular-nums">
                        <bdi dir="ltr">{row.hint}</bdi>
                      </span>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Modal>
    </>
  )
}
