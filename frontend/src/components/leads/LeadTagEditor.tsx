import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Tag, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchLeadTags, setLeadTags } from '@/features/leads'
import { useApiError } from '@/hooks/useApiError'
import { useUi } from '@/stores/ui'

/** As the server keeps them: ten a lead, thirty-two characters a tag. */
export const MAX_TAGS = 10
export const MAX_TAG_LENGTH = 32

/**
 * The team's own labels on a lead, as chips. Enter adds, × removes; the suggestions are the tags already in use, so
 * "VIP" is not written three ways. Without edit rights the chips are read-only.
 */
export function LeadTagEditor({ leadId, tags, editable }: { leadId: string; tags: string[]; editable: boolean }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState('')
  const [adding, setAdding] = useState(false)

  const suggestions = useQuery({ queryKey: ['lead-tags'], queryFn: fetchLeadTags, enabled: editable && adding, staleTime: 60_000 })

  const save = useMutation({
    mutationFn: (next: string[]) => setLeadTags(leadId, next),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['lead', leadId] })
      void queryClient.invalidateQueries({ queryKey: ['lead-tags'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const add = () => {
    const tag = draft.trim().replace(/\s+/g, ' ').slice(0, MAX_TAG_LENGTH)
    setDraft('')
    if (tag === '' || tags.some((existing) => existing.toLowerCase() === tag.toLowerCase())) return
    if (tags.length >= MAX_TAGS) {
      toast(t('tags.full', { count: MAX_TAGS }), 'error')
      return
    }
    save.mutate([...tags, tag])
  }

  if (!editable && tags.length === 0) return null

  return (
    <div className="mt-2 flex flex-wrap items-center gap-1.5" data-testid="lead-tags" aria-label={t('tags.label')}>
      <Tag className="size-3.5 text-[var(--color-ink-muted)]" aria-hidden />
      {tags.map((tag) => (
        <span
          key={tag}
          className="inline-flex items-center gap-1 rounded-full bg-[var(--brand-soft)] px-2.5 py-0.5 text-[0.8125rem] font-medium text-[var(--brand)]"
          data-testid="lead-tag"
        >
          {tag}
          {editable && (
            <button
              type="button"
              className="-me-1 rounded-full p-0.5 hover:bg-[color-mix(in_srgb,var(--brand)_15%,transparent)]"
              aria-label={t('tags.remove', { tag })}
              disabled={save.isPending}
              onClick={() => save.mutate(tags.filter((existing) => existing !== tag))}
            >
              <X className="size-3" aria-hidden />
            </button>
          )}
        </span>
      ))}
      {editable && tags.length < MAX_TAGS && (adding ? (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            add()
          }}
        >
          <input
            autoFocus
            value={draft}
            maxLength={MAX_TAG_LENGTH}
            list="lead-tag-suggestions"
            placeholder={t('tags.placeholder')}
            aria-label={t('tags.add')}
            className="field !h-9 w-40"
            data-testid="lead-tag-input"
            onChange={(event) => setDraft(event.target.value)}
            onBlur={() => {
              if (draft.trim() !== '') add()
              setAdding(false)
            }}
            onKeyDown={(event) => {
              if (event.key === 'Escape') {
                setDraft('')
                setAdding(false)
              }
            }}
          />
          <datalist id="lead-tag-suggestions">
            {(suggestions.data?.tags ?? [])
              .filter((s) => !tags.some((existing) => existing.toLowerCase() === s.tag.toLowerCase()))
              .map((s) => <option key={s.tag} value={s.tag} />)}
          </datalist>
        </form>
      ) : (
        <button
          type="button"
          className="inline-flex h-7 items-center gap-1 rounded-full border border-dashed border-[var(--color-line)] px-2.5 text-[0.8125rem] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
          data-testid="lead-tag-add"
          onClick={() => setAdding(true)}
        >
          <Plus className="size-3" aria-hidden />
          {t('tags.add')}
        </button>
      ))}
    </div>
  )
}
