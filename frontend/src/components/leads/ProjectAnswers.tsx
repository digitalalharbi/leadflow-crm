import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ListChecks, Star } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { fetchLeadAnswers, saveLeadAnswers, type LeadQuestion } from '@/features/projectQuestions'
import { ApiError } from '@/lib/api'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Badge, Button, Card, CardBody, CardHeader, Input, Select, Skeleton } from '@/components/ui'
import type { Lead } from '@/types/api'

/**
 * What this project asked, and what the lead said.
 *
 * Absent when the project asks nothing: a card that says "no questions" on every
 * lead of every project that never configured any is noise on the screen people
 * look at most. Present, it is the part of qualification that belongs to *this*
 * project — and it says plainly what the answers did, because an answer that
 * silently changes who gets called first is one a rep will not trust.
 */
export function ProjectAnswers({ lead }: { lead: Lead }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const { data, isLoading } = useQuery({
    queryKey: ['lead-answers', lead.id],
    queryFn: () => fetchLeadAnswers(lead.id),
    enabled: Boolean(lead.project),
  })

  const [draft, setDraft] = useState<Record<string, string>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (!data) return
    setDraft(Object.fromEntries(data.questions.map((q) => [q.key, q.answer === null ? '' : String(q.answer)])))
  }, [data])

  const save = useMutation({
    mutationFn: () => {
      const answers: Record<string, unknown> = {}
      for (const q of data?.questions ?? []) {
        const raw = draft[q.key] ?? ''
        answers[q.key] = raw === '' ? null : q.type === 'number' ? Number(raw) : q.type === 'boolean' ? raw === 'true' : raw
      }
      return saveLeadAnswers(lead.id, answers)
    },
    onSuccess: () => {
      setErrors({})
      void queryClient.invalidateQueries({ queryKey: ['lead-answers', lead.id] })
      void queryClient.invalidateQueries({ queryKey: ['lead', lead.id] })
      toast(t('common.saved'), 'success')
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.errors) {
        setErrors(Object.fromEntries(Object.entries(caught.errors).map(([k, v]) => [k.replace('answers.', ''), v[0] ?? ''])))
      } else {
        toast(translateError(caught), 'error')
      }
    },
  })

  if (!lead.project) return null
  if (isLoading) return <Skeleton className="h-32" />
  if (!data || data.questions.length === 0) return null

  return (
    <Card data-testid="project-answers">
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            <ListChecks className="size-4 text-[var(--color-ink-muted)]" aria-hidden />
            {t('pq.card', { project: lead.project.name })}
          </span>
        }
        action={
          <span className="flex items-center gap-1.5">
            {data.is_priority && (
              <Badge tone="danger" testId="answers-priority">
                <Star className="me-1 size-3" aria-hidden />
                {t('temperature.priority')}
              </Badge>
            )}
            {data.wrong_fit && (
              <Badge tone="warning" testId="answers-wrong-fit">
                <AlertTriangle className="me-1 size-3" aria-hidden />
                {t('pq.wrongFit')}
              </Badge>
            )}
          </span>
        }
      />
      <CardBody className="space-y-3">
        {data.questions.map((question) => (
          <Field
            key={question.id}
            question={question}
            value={draft[question.key] ?? ''}
            error={errors[question.key]}
            onChange={(value) => setDraft((previous) => ({ ...previous, [question.key]: value }))}
          />
        ))}

        <Button onClick={() => save.mutate()} loading={save.isPending} data-testid="save-answers">
          {t('common.save')}
        </Button>
      </CardBody>
    </Card>
  )
}

function Field({
  question, value, error, onChange,
}: {
  question: LeadQuestion
  value: string
  error?: string
  onChange: (value: string) => void
}) {
  const { t } = useTranslation()
  const label = question.is_required ? `${question.label} *` : question.label
  const testId = `answer-${question.key}`

  if (question.type === 'select') {
    return (
      <Select label={label} value={value} error={error} data-testid={testId} onChange={(e) => onChange(e.target.value)}>
        <option value="">{t('pq.unanswered')}</option>
        {question.options.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </Select>
    )
  }

  if (question.type === 'boolean') {
    return (
      <Select label={label} value={value} error={error} data-testid={testId} onChange={(e) => onChange(e.target.value)}>
        <option value="">{t('pq.unanswered')}</option>
        <option value="true">{t('common.yes')}</option>
        <option value="false">{t('common.no')}</option>
      </Select>
    )
  }

  return (
    <Input
      label={label}
      value={value}
      error={error}
      inputMode={question.type === 'number' ? 'numeric' : undefined}
      data-testid={testId}
      onChange={(e) => onChange(question.type === 'number' ? e.target.value.replace(/[^\d.]/g, '') : e.target.value)}
    />
  )
}
