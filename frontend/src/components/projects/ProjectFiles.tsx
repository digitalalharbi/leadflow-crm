import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, Trash2, Upload } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Select, Skeleton } from '@/components/ui'
import {
  deleteProjectFile, fetchProjectFiles, FILE_KINDS, updateProjectFile, uploadProjectFile,
  type FileKind,
} from '@/features/files'

/**
 * The material a project sells with.
 *
 * This is where the files the rest of the product reasons about come from, and
 * until now there was nowhere to put them: the chooser that picks a brochure
 * for a lead, the per-lead link whose opens feed the engagement score, and the
 * rule that sends one were all built against a table nobody could fill.
 *
 * Kind and language are asked for at upload rather than inferred from the
 * filename. They are what the chooser reads, and `عرض-استثماري-نهائي-2.pdf`
 * tells it nothing.
 */
export function ProjectFiles({ projectId }: { projectId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const inputRef = useRef<HTMLInputElement>(null)

  const [kind, setKind] = useState<FileKind>('brochure')
  const [language, setLanguage] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['project-files', projectId],
    queryFn: () => fetchProjectFiles(projectId),
  })

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['project-files', projectId] })

  const upload = useMutation({
    mutationFn: (file: File) => uploadProjectFile(projectId, { file, kind, language: language || null }),
    onSuccess: () => {
      toast(t('files.uploaded'), 'success')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const toggle = useMutation({
    mutationFn: ({ id, is_active }: { id: string; is_active: boolean }) =>
      updateProjectFile(projectId, id, { is_active }),
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (id: string) => deleteProjectFile(projectId, id),
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-40" />

  const files = data?.files ?? []

  return (
    <Card>
      <CardHeader title={t('files.projectTitle')} description={t('files.projectHint')} />
      <CardBody>
        <div className="flex flex-wrap items-end gap-2">
          <Select
            className="min-w-40"
            label={t('files.kindLabel')}
            value={kind}
            onChange={(e) => setKind(e.target.value as FileKind)}
          >
            {FILE_KINDS.map((value) => (
              <option key={value} value={value}>
                {t(`files.kind.${value}`)}
              </option>
            ))}
          </Select>

          <Select
            className="min-w-36"
            label={t('files.languageLabel')}
            value={language}
            onChange={(e) => setLanguage(e.target.value)}
          >
            {/*
              "Any" first, and it is a real answer rather than a missing one: a
              price list of numbers suits everybody, and demanding a translation
              would leave half the customers without a file at all.
            */}
            <option value="">{t('files.anyLanguage')}</option>
            <option value="ar">{t('files.arabic')}</option>
            <option value="en">{t('files.english')}</option>
          </Select>

          <input
            ref={inputRef}
            type="file"
            className="sr-only"
            accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.ppt,.pptx,.xls,.xlsx"
            onChange={(e) => {
              const file = e.target.files?.[0]

              if (file) upload.mutate(file)
              // Cleared, so choosing the same file twice fires the change again.
              e.target.value = ''
            }}
          />

          <Button
            icon={<Upload className="size-4" />}
            loading={upload.isPending}
            onClick={() => inputRef.current?.click()}
            data-testid="upload-project-file"
          >
            {t('files.upload')}
          </Button>
        </div>

        {files.length === 0 ? (
          <p className="t-caption mt-4" data-testid="project-files-empty">{t('files.none')}</p>
        ) : (
          <ul className="mt-4 space-y-2" data-testid="project-files">
            {files.map((file) => (
              <li
                key={file.id}
                className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--color-line)] pb-2 last:border-0 last:pb-0"
              >
                <span className="flex min-w-0 items-center gap-2">
                  <FileText className="size-4 shrink-0 text-[var(--color-ink-muted)]" aria-hidden />
                  <span className="t-small truncate">{file.name}</span>
                  <Badge tone="neutral">{t(`files.kind.${file.kind}`)}</Badge>
                  {file.language && <Badge tone="neutral">{file.language}</Badge>}
                </span>

                <span className="flex items-center gap-2">
                  {/*
                    Paused rather than deleted is the usual answer for a price
                    list that is out of date: the shares already issued against
                    it keep their open counts, and nobody is sent it again.
                  */}
                  <Button
                    size="sm"
                    variant={file.is_active ? 'outline' : 'secondary'}
                    onClick={() => toggle.mutate({ id: file.id, is_active: !file.is_active })}
                  >
                    {file.is_active ? t('files.pause') : t('files.resume')}
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t('common.delete')}
                    onClick={() => remove.mutate(file.id)}
                  >
                    <Trash2 className="size-4" aria-hidden />
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  )
}
