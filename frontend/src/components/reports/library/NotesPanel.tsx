import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Button, Drawer, Skeleton, Textarea } from '@/components/ui'
import { fetchNotes, saveNote, type ReportNoteItem } from '@/features/reportLibrary'
import type { ReportShare } from '@/features/reportShares'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import { useUi } from '@/stores/ui'

const KINDS: ReportNoteItem['kind'][] = ['executive', 'marketing', 'sales', 'period']

/** What the team writes for the reader: four short notes, plain text. Editing keeps the old version, with who wrote it. */
export function NotesPanel({ share, onClose }: { share: ReportShare | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const { data, isLoading } = useQuery({ queryKey: ['report-notes', share?.id], queryFn: () => fetchNotes(share!.id), enabled: share !== null })
  const [draft, setDraft] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: ({ kind }: { kind: ReportNoteItem['kind'] }) => saveNote(share!.id, kind, draft[kind] ?? data?.notes.find((n) => n.kind === kind)?.body ?? ''),
    onSuccess: (result, { kind }) => {
      toast(t('library.done.saved'), 'success')
      queryClient.setQueryData(['report-notes', share?.id], result)
      setDraft((d) => { const { [kind]: _gone, ...rest } = d; return rest })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Drawer open={share !== null} onClose={onClose} title={t('notesPanel.title', { name: share?.name ?? '' })} size="lg" testId="notes-panel">
      {isLoading || !data ? <Skeleton className="h-40" /> : (
        <div className="space-y-5">
          <p className="t-caption">{t('notesPanel.hint')}</p>
          {KINDS.map((kind) => {
            const current = data.notes.find((n) => n.kind === kind)
            const value = draft[kind] ?? current?.body ?? ''

            return (
              <div key={kind} className="space-y-1.5" data-testid={`note-${kind}`}>
                <Textarea label={t(`notesPanel.kind.${kind}`)} value={value} rows={3} maxLength={2000} data-testid={`note-input-${kind}`} onChange={(e) => setDraft((d) => ({ ...d, [kind]: e.target.value }))} />
                <div className="flex items-center justify-between gap-2">
                  <p className="t-caption">{current ? `${t('notesPanel.by', { name: current.author ?? '—' })} · ${formatLastSeen(current.at, i18n.language)}` : ''}</p>
                  <Button size="sm" loading={save.isPending} disabled={(draft[kind] ?? current?.body ?? '') === (current?.body ?? '')} onClick={() => save.mutate({ kind })} data-testid={`note-save-${kind}`}>{t('notesPanel.save')}</Button>
                </div>
              </div>
            )
          })}
          <div>
            <h3 className="t-small mb-1 font-semibold">{t('notesPanel.history')}</h3>
            {data.history.length === 0 ? <p className="t-caption">{t('notesPanel.empty')}</p> : (
              <ul className="space-y-1.5" data-testid="note-history">
                {data.history.map((n) => (
                  <li key={n.id} className="rounded-lg bg-[var(--color-surface-muted)] px-3 py-1.5 text-[0.875rem]">
                    <span className="t-caption block">{t(`notesPanel.kind.${n.kind}`)} · {t('notesPanel.by', { name: n.author ?? '—' })} · {formatLastSeen(n.at, i18n.language)}</span>
                    <span className="whitespace-pre-wrap" dir="auto">{n.body}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Drawer>
  )
}
