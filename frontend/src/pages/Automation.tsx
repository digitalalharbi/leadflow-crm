import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Sparkles, Zap } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { SimulationPanel } from '@/components/automation/SimulationPanel'
import {
  createRule, deleteRule, fetchAutomationSummary, fetchInsights, fetchRules, fetchSchema, fetchTemplates,
  installTemplate, previewRule, updateRule, type AutomationRule, type HistoryResult,
  duplicateRule,
} from '@/features/automation'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import {
  Badge, Button, Card, CardBody, Drawer, EmptyState, ErrorState, Modal,
  PageHeader, SkeletonCards, Tabs,
} from '@/components/ui'
import { AutomationActivityChart, ChartCard, ChartLegend, SERIES } from '@/components/charts'
import { CentreSummary } from '@/components/automation/CentreSummary'
import { RulesTable } from '@/components/automation/RulesTable'
import { ActionHistory } from '@/components/automation/ActionHistory'
import { AgentsPanel, RoutinesPanel, ScoringCard } from '@/components/automation/CentrePanels'
import { RuleEditor } from '@/features/automation/RuleEditor'
import { RuleFlow } from '@/components/automation/RuleFlow'

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
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [editing, setEditing] = useState<AutomationRule | 'new' | null>(null)
  const [previewing, setPreviewing] = useState<AutomationRule | null>(null)
  const [confirmDelete, setConfirmDelete] = useState<AutomationRule | null>(null)

  const schema = useQuery({ queryKey: ['automation-schema'], queryFn: fetchSchema })
  const [tab, setTab] = useState<'rules' | 'history' | 'routines' | 'agents'>('rules')
  const [days, setDays] = useState(7)
  const [search, setSearch] = useState('')
  const [showAllTemplates, setShowAllTemplates] = useState(false)
  const [historyResult, setHistoryResult] = useState<HistoryResult | ''>('')
  const navigate = useNavigate()
  const summary = useQuery({ queryKey: ['automation-summary', days], queryFn: () => fetchAutomationSummary(days) })
  const insights = useQuery({ queryKey: ['automation-insights', days], queryFn: () => fetchInsights(Math.max(7, days)) })
  const rules = useQuery({ queryKey: ['automation-rules'], queryFn: fetchRules })
  const templates = useQuery({ queryKey: ['automation-templates'], queryFn: fetchTemplates })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['automation-rules'] })
    void queryClient.invalidateQueries({ queryKey: ['automation-templates'] })
    void queryClient.invalidateQueries({ queryKey: ['automation-summary'] })
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

  const duplicate = useMutation({
    mutationFn: (id: string) => duplicateRule(id),
    onSuccess: (result) => {
      refresh()
      toast(t('automation.duplicated'), 'success')
      // Straight into the copy: duplicating is the first half of editing, and
      // it arrives switched off, so the next thing anybody does is change it.
      setEditing(result.rule)
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
  const shownRules = (rules.data?.rules ?? []).filter((rule) =>
    search.trim() === '' ? true : rule.name.includes(search.trim()),
  )

  function drill(target: HistoryResult | 'rules' | 'leads' | '') {
    if (target === 'rules') {
      document.querySelector('[data-testid="rules-list"]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })

      return
    }

    if (target === 'leads') {
      navigate('/leads')

      return
    }

    setHistoryResult(target)
    setTab('history')
  }

  return (
    <div className="space-y-5">
      <PageHeader
        title={t('automation.title')}
        subtitle={t('automation.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Tabs
              testId="automation-tabs"
              value={tab}
              onChange={(key) => setTab(key as typeof tab)}
              items={[
                { key: 'rules', label: t('automation.tabs.rules') },
                { key: 'history', label: t('automation.tabs.history') },
                { key: 'routines', label: t('automation.tabs.routines') },
                { key: 'agents', label: t('automation.tabs.agents') },
              ]}
            />
            <Button icon={<Plus className="size-4" aria-hidden />} onClick={() => setEditing('new')}>
              {t('automation.newRule')}
            </Button>
          </div>
        }
      />

      {tab === 'rules' && (
        <>
          <div className="flex items-center justify-between gap-2">
            <h2 className="t-h3">{t('automation.summaryTitle')}</h2>
            <div className="flex gap-1 rounded-full bg-[var(--color-chrome-fill)] p-1" role="group" aria-label={t('automation.period')}>
              {[1, 7, 30].map((d) => (
                <button
                  key={d}
                  type="button"
                  onClick={() => setDays(d)}
                  aria-pressed={days === d}
                  className={days === d ? 'rounded-full bg-[var(--color-surface)] px-3 py-1 text-[0.875rem] font-semibold shadow-[var(--shadow-card)]' : 'rounded-full px-3 py-1 text-[0.875rem] text-[var(--color-ink-muted)]'}
                >
                  {t(`automation.period_${d}`)}
                </button>
              ))}
            </div>
          </div>

          {summary.data ? <CentreSummary data={summary.data} onDrill={drill} /> : <SkeletonCards count={4} />}

          {/*
            Which rules fired, which did not apply and which failed, day by
            day: a workspace with twelve rules and a flat line has a problem
            the cards alone would not show.
          */}
          {insights.data && insights.data.summary.runs > 0 && (
            <ChartCard
              title={t('charts.automation.title')}
              description={t('charts.automation.hint')}
              height={190}
              testId="automation-activity"
              legend={
                <ChartLegend
                  items={[
                    { key: 'applied', label: t('charts.automation.applied'), color: SERIES[0] },
                    { key: 'skipped', label: t('charts.automation.skipped'), color: SERIES[1] },
                    { key: 'failed', label: t('charts.automation.failed'), color: 'var(--color-status-lost)' },
                  ]}
                />
              }
              table={{
                head: [t('charts.automation.rule'), t('charts.automation.applied'), t('charts.automation.skipped'), t('charts.automation.failed')],
                rows: insights.data.by_rule.map((row) => [row.rule, row.applied, row.skipped, row.failed]),
              }}
            >
              <AutomationActivityChart days={insights.data.daily} />
            </ChartCard>
          )}

          <section className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="t-h3">{t('automation.rulesTitle')}</h2>
              <input
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('automation.searchRules')}
                className="field !h-10 w-64 max-w-full"
                data-testid="rules-search"
              />
            </div>

            {rules.isLoading ? (
              <SkeletonCards count={3} />
            ) : (rules.data?.rules.length ?? 0) === 0 ? (
              <Card>
                <CardBody>
                  <EmptyState icon={<Zap className="size-6" aria-hidden />} title={t('automation.empty.title')} body={t('automation.empty.description')} />
                </CardBody>
              </Card>
            ) : (
              <RulesTable
                rules={shownRules}
                busyId={toggle.isPending ? toggle.variables?.rule.id : null}
                onToggle={(rule) => toggle.mutate({ rule, enabled: !rule.is_enabled })}
                onPreview={setPreviewing}
                onEdit={setEditing}
                onDuplicate={(rule) => duplicate.mutate(rule.id)}
                onDelete={setConfirmDelete}
              />
            )}
          </section>

          {/* One press each: ready rules, installed switched off so a person reads them first. */}
          {uninstalled.length > 0 && (
            <section className="space-y-3" data-testid="template-gallery">
              <h2 className="t-h3 flex items-center gap-2">
                <Sparkles className="size-4 text-[var(--brand)]" aria-hidden />
                {t('automation.templatesTitle')}
              </h2>
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {(showAllTemplates ? uninstalled : uninstalled.slice(0, 9)).map((template) => (
                  <Card key={template.key}>
                    <CardBody className="flex h-full flex-col gap-2.5">
                      <div className="flex items-start justify-between gap-2">
                        <h3 className="text-[1.0625rem] font-semibold">{template.name}</h3>
                        <Badge tone="neutral">{t(`automation.trigger.${template.trigger}`)}</Badge>
                      </div>
                      <RuleFlow trigger={template.trigger} conditions={template.conditions} actions={template.actions} />
                      <div className="mt-auto flex justify-end">
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
              {uninstalled.length > 9 && (
                <div className="flex justify-center">
                  <Button size="sm" variant="ghost" onClick={() => setShowAllTemplates((v) => !v)} data-testid="toggle-templates">
                    {showAllTemplates ? t('automation.fewerTemplates') : t('automation.allTemplates', { count: uninstalled.length })}
                  </Button>
                </div>
              )}
            </section>
          )}

          <ScoringCard />
        </>
      )}

      {tab === 'history' && <ActionHistory key={historyResult} initialResult={historyResult} />}
      {tab === 'routines' && <RoutinesPanel />}
      {tab === 'agents' && <AgentsPanel />}

      {editing && schema.data && (
        <RuleEditor
          rule={editing === 'new' ? null : editing}
          schema={schema.data}
          saving={save.isPending}
          onCancel={() => setEditing(null)}
          onSave={(payload) => save.mutate({ rule: editing, payload })}
        />
      )}

      {/*
        What a rule would do, in a drawer: it is something to read against the
        rule list, and the next question after reading it is almost always about
        the rule beside it.
      */}
      <Drawer
        open={previewing !== null}
        onClose={() => setPreviewing(null)}
        testId="automation-preview-drawer"
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
        {previewing && <div className="mt-5 border-t border-[var(--color-border)] pt-4"><SimulationPanel ruleId={previewing.id} /></div>}
      </Drawer>

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
