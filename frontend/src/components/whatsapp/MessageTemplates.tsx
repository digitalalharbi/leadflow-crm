import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import {
  Badge, Button, Card, CardBody, CardHeader, Drawer, Input, Select, Skeleton, Textarea,
} from '@/components/ui'
import {
  createMessageTemplate, deleteMessageTemplate, fetchMessageTemplates,
  updateMessageTemplate, type TemplatePayload,
} from '@/features/conversations'
import type { MessageTemplate } from '@/types/api'

/**
 * The messages a workspace sends, and which of them may open a conversation.
 *
 * Meta lets a business message a customer freely for a day after the customer
 * last wrote, and after that only with a template it registered and had
 * approved in advance. Everything that sends on a workspace's behalf — a rep's
 * button, a rule — has to know which of its messages are the second kind, and
 * until now there was nowhere to say so: the screen that would have said it did
 * not exist, so a rule could only ever reach somebody who had already replied.
 *
 * Registering the twin is optional per message. Most of a workspace's messages
 * answer people and never need one.
 */
export function MessageTemplates() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [editing, setEditing] = useState<MessageTemplate | 'new' | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['message-templates', 'all'],
    queryFn: () => fetchMessageTemplates(true),
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['message-templates'] })
  }

  const toggle = useMutation({
    mutationFn: (template: MessageTemplate) =>
      updateMessageTemplate(template.id, { is_active: !template.is_active }),
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (id: string) => deleteMessageTemplate(id),
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-32" />

  const templates = data?.templates ?? []

  return (
    <Card>
      <CardHeader
        title={t('templates.title')}
        description={t('templates.hint')}
        action={
          <Button
            size="sm"
            variant="outline"
            icon={<Plus className="size-4" />}
            onClick={() => setEditing('new')}
            data-testid="new-template"
          >
            {t('templates.add')}
          </Button>
        }
      />
      <CardBody>
        <ul className="space-y-2" data-testid="message-templates">
          {templates.map((template) => (
            <li
              key={template.id}
              className="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--color-line)] pb-2 last:border-0 last:pb-0"
            >
              <span className="min-w-0">
                <span className="t-small font-medium">{template.name}</span>
                <span className="t-caption line-clamp-1 block">{template.body}</span>
              </span>

              <span className="flex items-center gap-2">
                {/*
                  In words, because the difference is not cosmetic: one of these
                  can reach a cold lead and the other will be refused.
                */}
                {template.can_start_conversation ? (
                  <Badge tone="success">{t('templates.canStart')}</Badge>
                ) : (
                  <Badge tone="neutral">{t('templates.replyOnly')}</Badge>
                )}

                <Button
                  size="sm"
                  variant={template.is_active ? 'outline' : 'secondary'}
                  onClick={() => toggle.mutate(template)}
                >
                  {template.is_active ? t('files.pause') : t('files.resume')}
                </Button>
                <Button
                  size="icon"
                  variant="ghost"
                  aria-label={t('common.edit')}
                  onClick={() => setEditing(template)}
                >
                  <Pencil className="size-4" aria-hidden />
                </Button>
                <Button
                  size="icon"
                  variant="ghost"
                  aria-label={t('common.delete')}
                  onClick={() => remove.mutate(template.id)}
                >
                  <Trash2 className="size-4" aria-hidden />
                </Button>
              </span>
            </li>
          ))}
        </ul>
      </CardBody>

      {editing && (
        <TemplateDrawer
          template={editing === 'new' ? null : editing}
          variables={data?.variables ?? ['name', 'agent', 'company', 'project']}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      )}
    </Card>
  )
}

function TemplateDrawer({
  template,
  variables,
  onClose,
  onSaved,
}: {
  template: MessageTemplate | null
  variables: string[]
  onClose: () => void
  onSaved: () => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [name, setName] = useState(template?.name ?? '')
  const [body, setBody] = useState(template?.body ?? '')
  const [metaName, setMetaName] = useState(template?.meta_template_name ?? '')
  const [metaLanguage, setMetaLanguage] = useState(template?.meta_language ?? 'ar')
  const [parameters, setParameters] = useState<string[]>(template?.meta_parameters ?? [])

  const save = useMutation({
    mutationFn: () => {
      const payload: TemplatePayload = {
        name,
        body,
        // Cleared together or not at all: a language and a parameter list with
        // no template name between them is a row that looks registered and is
        // not.
        meta_template_name: metaName.trim() || null,
        meta_language: metaName.trim() ? metaLanguage : null,
        meta_parameters: metaName.trim() ? parameters : null,
      }

      return template ? updateMessageTemplate(template.id, payload) : createMessageTemplate(payload)
    },
    onSuccess: () => {
      toast(t('templates.saved'), 'success')
      onSaved()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Drawer
      open
      onClose={onClose}
      testId="template-drawer"
      title={template ? t('templates.edit') : t('templates.add')}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button
            loading={save.isPending}
            disabled={!name.trim() || !body.trim()}
            onClick={() => save.mutate()}
            data-testid="save-template"
          >
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Input label={t('templates.name')} value={name} onChange={(e) => setName(e.target.value)} />

        <Textarea
          label={t('templates.body')}
          rows={5}
          value={body}
          onChange={(e) => setBody(e.target.value)}
        />
        <p className="t-caption">
          {t('templates.variables')}{' '}
          {variables.map((variable) => (
            <code key={variable} className="me-1.5" dir="ltr">{`{{${variable}}}`}</code>
          ))}
        </p>

        <fieldset className="space-y-3 rounded-[var(--radius-card)] border border-[var(--color-line)] p-3">
          <legend className="t-small px-1 font-medium">{t('templates.approvedTitle')}</legend>
          <p className="t-caption">{t('templates.approvedHint')}</p>

          <Input
            label={t('templates.metaName')}
            dir="ltr"
            placeholder="investment_intro_ar"
            value={metaName}
            onChange={(e) => setMetaName(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))}
            data-testid="meta-template-name"
          />

          {metaName.trim() !== '' && (
            <>
              <Select
                label={t('templates.metaLanguage')}
                value={metaLanguage}
                onChange={(e) => setMetaLanguage(e.target.value)}
              >
                <option value="ar">{t('files.arabic')}</option>
                <option value="en">{t('files.english')}</option>
                <option value="en_US">English (US)</option>
              </Select>

              {/*
                In the order the template declares them. {{1}} is the first of
                these, {{2}} the second — Meta substitutes by position and has
                no idea what the variables are called.
              */}
              <div className="space-y-2">
                <p className="t-small font-medium">{t('templates.parameters')}</p>
                {parameters.map((parameter, index) => (
                  <div key={index} className="flex items-center gap-2">
                    <span className="t-caption w-10 shrink-0" dir="ltr">{`{{${index + 1}}}`}</span>
                    <Select
                      className="flex-1"
                      value={parameter}
                      onChange={(e) =>
                        setParameters((current) =>
                          current.map((value, i) => (i === index ? e.target.value : value)),
                        )
                      }
                    >
                      {variables.map((variable) => (
                        <option key={variable} value={variable}>
                          {t(`templates.variable.${variable}`, { defaultValue: variable })}
                        </option>
                      ))}
                    </Select>
                    <Button
                      size="icon"
                      variant="ghost"
                      aria-label={t('common.delete')}
                      onClick={() => setParameters((current) => current.filter((_, i) => i !== index))}
                    >
                      <Trash2 className="size-4" aria-hidden />
                    </Button>
                  </div>
                ))}
                {parameters.length < variables.length && (
                  <Button
                    size="sm"
                    variant="outline"
                    icon={<Plus className="size-4" />}
                    onClick={() => setParameters((current) => [...current, variables[0]])}
                  >
                    {t('templates.addParameter')}
                  </Button>
                )}
              </div>
            </>
          )}
        </fieldset>
      </div>
    </Drawer>
  )
}
