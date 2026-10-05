import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Sparkles, Trash2, Zap } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import {
  createRule, deleteRule, fetchRules, fetchSchema, fetchTemplates, installTemplate,
  previewRule, updateRule, type AutomationRule,
} from '@/features/automation'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, EmptyState, ErrorState, Modal,
  PageHeader, SkeletonCards,
} from '@/components/ui'
import { RuleEditor } from '@/features/automation/RuleEditor'

/**
 * The rules screen.
 *
 * Two things earn their place here over a plain list. The gallery, because a
 * workspace facing an empty form writes nothing and keeps doing the work by
 * hand. And preview, because a rule acts on real customers unattended — being
 * able to see what it would match before switching it on is the difference
 * between a feature people use and one they disable after the first surprise.
 */
export function AutomationPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [editing, setEditing] = useState<AutomationRule | 'new' | null>(null)
  const [previewing, setPreviewing] = useState<AutomationRule | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<AutomationRule | null>(null)

  const schema = useQuery({ queryKey: ['automation-schema'], queryFn: fetchSchema })
  const rules = useQuery({ queryKey: ['automation-rules'], queryFn: fetchRules })
  const templates = useQuery({ queryKey: ['automation-templates'], queryFn: fetchTemplates })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['automation-rules'] })
    void queryClient.invalidateQueries({ queryKey: ['automation-templates'] })
  }

  const install = useMutation({
    mutationFn: installTemplate,
    onSuccess: () => {
      refresh()
      // Says "added, switched off" rather than "added": somebody who assumes
      // it is live will not come back to turn it on.
      toast(t('automation.templateInstalled'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const toggle = useMutation({
    mutationFn: ({ rule, enabled }: { rule: AutomationRule; enabled: boolean }) =>
      updateRule(rule.id, { is_enabled: enabled }),
    onSuccess: (_result, { enabled }) => {
      refresh()
      toast(t(enabled ? 'automation.enabled' : 'automation.disabled'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const save = useMutation({
    mutationFn: ({ rule, payload }: { rule: AutomationRule | 'new'; payload: Record<string, unknown> }) =>
      rule === 'new' ? createRule(payload) : updateRule(rule.id, payload),
    onSuccess: () => {
      setEditing(null)
      refresh()
      toast(t('automation.saved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (rule: AutomationRule) => deleteRule(rule.id),
    onSuccess: () => {
      setConfirmDelete(null)
      refresh()
      toast(t('automation.deleted'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const preview = useQuery({
    queryKey: ['automation-preview', previewing?.id],
    queryFn: () => previewRule(previewing!.id),
    enabled: previewing !== null,
  })

  if (rules.isError) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('automation.title')} />
        <ErrorState message={translateError(rules.error)} onRetry={() => void rules.refetch()} />
      </div>
    )
  }

  const uninstalled = (templates.data?.templates ?? []).filter((template) => !template.installed)

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('automation.title')}
        subtitle={t('automation.subtitle')}
        actions={
          <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing('new')}>
            {t('automation.newRule')}
          </Button>
        }
      />

      {/* The gallery comes first while the workspace has nothing. */}
      {uninstalled.length > 0 && (
        <section className="space-y-3" data-testid="template-gallery">
          <h2 className="t-h3 flex items-center gap-2">
            <Sparkles className="size-4 text-[var(--brand)]" aria-hidden />
            {t('automation.templatesTitle')}
          </h2>
          <p className="t-caption">{t('automation.templatesHint')}</p>

          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {uninstalled.map((template) => (
              <Card key={template.key}>
                <CardBody className="flex h-full flex-col gap-3">
                  <div className="space-y-1">
                    <h3 className="t-body font-medium">{template.name}</h3>
                    <p className="t-caption">{template.description}</p>
                  </div>
                  <div className="mt-auto flex items-center justify-between gap-2">
                    <Badge tone="neutral">{t(`automation.trigger.${template.trigger}`)}</Badge>
                    <Button
                      size="sm"
                      variant="outline"
                      loading={install.isPending && install.variables === template.key}
                      data-testid={`install-${template.key}`}
                      onClick={() => install.mutate(template.key)}
                    >
                      {t('automation.useTemplate')}
                    </Button>
                  </div>
                </CardBody>
              </Card>
            ))}
          </div>
        </section>
      )}

      <section className="space-y-3">
        <h2 className="t-h3">{t('automation.rulesTitle')}</h2>

        {rules.isLoading ? (
          <SkeletonCards count={3} />
        ) : (rules.data?.rules.length ?? 0) === 0 ? (
          <Card>
            <CardBody>
              <EmptyState
                icon={<Zap className="size-6" aria-hidden />}
                title={t('automation.empty.title')}
                body={t('automation.empty.description')}
              />
            </CardBody>
          </Card>
        ) : (
          <div className="space-y-2" data-testid="rules-list">
            {rules.data?.rules.map((rule) => (
              <Card key={rule.id}>
                <CardBody className="flex flex-wrap items-center justify-between gap-3">
                  <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="t-body font-medium">{rule.name}</span>
                      <Badge tone={rule.is_enabled ? 'success' : 'neutral'}>
                        {t(rule.is_enabled ? 'automation.on' : 'automation.off')}
                      </Badge>
                      <Badge tone="neutral">{t(`automation.trigger.${rule.trigger}`)}</Badge>
                    </div>
                    <p className="t-caption">
                      {[
                        t('automation.conditionCount', { count: rule.conditions.length }),
                        t('automation.actionCount', { count: rule.actions.length }),
                      ].join(' · ')}
                      {rule.last_matched_at &&
                        ` · ${t('automation.lastFired', {
                          when: formatDateTime(rule.last_matched_at, i18n.language),
                        })}`}
                    </p>
                  </div>

                  <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <Button size="sm" variant="ghost" onClick={() => setPreviewing(rule)}>
                      {t('automation.preview')}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setEditing(rule)}>
                      {t('automation.edit')}
                    </Button>
                    <Button
                      size="sm"
                      variant={rule.is_enabled ? 'secondary' : 'primary'}
                      data-testid={`toggle-${rule.id}`}
                      onClick={() => toggle.mutate({ rule, enabled: !rule.is_enabled })}
                    >
                      {t(rule.is_enabled ? 'automation.turnOff' : 'automation.turnOn')}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      aria-label={t('automation.delete')}
                      icon={<Trash2 className="size-4" aria-hidden />}
                      onClick={() => setConfirmDelete(rule)}
                    />
                  </div>
                </CardBody>
              </Card>
            ))}
          </div>
        )}
      </section>

      {editing && schema.data && (
        <RuleEditor
          rule={editing === 'new' ? null : editing}
          schema={schema.data}
          saving={save.isPending}
          onCancel={() => setEditing(null)}
          onSave={(payload) => save.mutate({ rule: editing, payload })}
        />
      )}

      <Modal
        open={previewing !== null}
        onClose={() => setPreviewing(null)}
        title={t('automation.previewTitle', { name: previewing?.name ?? '' })}
      >
        {preview.isLoading ? (
          <SkeletonCards count={1} />
        ) : preview.data ? (
          <div className="space-y-3" data-testid="preview-result">
            <p className="t-body">
              {t('automation.previewMatched', { count: preview.data.would_match })}{' '}
              {t('automation.previewSampled', { count: preview.data.sampled })}
            </p>
            {preview.data.examples.length > 0 && (
              <ul className="space-y-1">
                {preview.data.examples.map((example) => (
                  <li key={example.id} className="t-caption">
                    {example.full_name}
                  </li>
                ))}
              </ul>
            )}
            <p className="t-caption">{t('automation.previewNote')}</p>
          </div>
        ) : null}
      </Modal>

      <Modal
        open={confirmDelete !== null}
        onClose={() => setConfirmDelete(null)}
        title={t('automation.deleteTitle')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmDelete(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={remove.isPending}
              onClick={() => confirmDelete && remove.mutate(confirmDelete)}
            >
              {t('automation.delete')}
            </Button>
          </>
        }
      >
        <p className="t-body">{t('automation.deleteBody', { name: confirmDelete?.name ?? '' })}</p>
      </Modal>
    </div>
  )
}
