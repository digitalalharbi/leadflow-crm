import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Check, Copy, FileUp, Sparkles, Upload,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { apiGet, apiPost, http } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, EmptyState, ErrorState,
  Modal, PageHeader, Select, Skeleton, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import type { IntegrationCard } from '@/types/api'
import { cn } from '@/lib/cn'
import { ProviderMark } from '@/components/integrations/ProviderMark'
import { WhatsappSettingsCard } from '@/components/whatsapp/WhatsappSettingsCard'

const STATUS_LABEL = {
  connected: 'integrations.connected',
  not_connected: 'integrations.notConnected',
  configuration_required: 'integrations.configurationRequired',
  error: 'integrations.error',
} as const

export function IntegrationsPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [secret, setSecret] = useState<{ url: string; secret: string } | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const demoMode = useAuth((s) => s.demoMode)

  /*
   * Sales-demo helper. Pushes a lead through the real ingestion pipeline so a
   * prospect watches it appear in the CRM, rather than being told it would.
   * The button only exists outside production; the route does too.
   */
  const simulate = useMutation({
    mutationFn: () =>
      apiPost<{ leads: Array<{ campaign_name: string }> }>('/demo/simulate-lead', {}),
    onSuccess: (result) => {
      const campaign = result.leads[0]?.campaign_name ?? ''
      toast(t('integrations.simulated', { campaign }), 'success')

      void queryClient.invalidateQueries({ queryKey: ['leads'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['webhook-logs'] })
      void queryClient.invalidateQueries({ queryKey: ['pipeline'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['integrations'],
    queryFn: () => apiGet<{ integrations: IntegrationCard[] }>('/integrations').then((r) => r.integrations),
  })

  const { data: logs } = useQuery({
    queryKey: ['webhook-logs'],
    queryFn: () =>
      http
        .get('/webhook-logs', { params: { per_page: 10 } })
        .then((r) => r.data.data as {
          id: string; provider: string; status: string; error: string | null
          received_at: string; lead: { id: string; full_name: string } | null
        }[]),
  })

  const connect = useMutation({
    mutationFn: (provider: string) => apiPost<{ integration: { id: string } }>('/integrations', { provider }),
    onSuccess: async (result, provider) => {
      // A webhook provider is useless without an endpoint, so issue one straight away.
      if (provider === 'generic_webhook' || provider === 'google_ads') {
        const endpoint = await apiPost<{ endpoint: { url: string }; secret: string }>(
          `/integrations/${result.integration.id}/webhooks`,
          {},
        )
        setSecret({ url: endpoint.endpoint.url, secret: endpoint.secret })
      }

      void queryClient.invalidateQueries({ queryKey: ['integrations'] })
      toast(t('integrations.savedMsg'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <>
      <PageHeader
        title={t('integrations.title')}
        subtitle={t('integrations.subtitle')}
        actions={
          <>
            {demoMode && (
              <Button
                variant="outline"
                icon={<Sparkles className="size-4" />}
                loading={simulate.isPending}
                onClick={() => simulate.mutate()}
                title={t('integrations.simulateHint')}
                data-testid="simulate-lead"
              >
                {simulate.isPending ? t('integrations.simulating') : t('integrations.simulate')}
              </Button>
            )}
            <Button variant="outline" icon={<FileUp className="size-4" />} onClick={() => setImportOpen(true)}>
              {t('integrations.importCsv')}
            </Button>
          </>
        }
      />

      {/* WhatsApp is the only integration a customer configures themselves end
          to end, so it gets a full card rather than a tile in the grid. */}
      <div className="mb-4">
        <WhatsappSettingsCard />
      </div>

      {isError ? (
        <Card><ErrorState message={translateError(error)} onRetry={() => void refetch()} /></Card>
      ) : isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-40" />)}
        </div>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data?.map((card) => (
            <Card key={card.provider} className="flex flex-col">
              <CardBody className="flex-1">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex min-w-0 items-center gap-2.5">
                    <ProviderMark provider={card.provider} />
                    <div className="min-w-0">
                      <h3 className="t-card-title truncate">
                        {t(`platforms.${card.provider}`, {
                          defaultValue: card.provider === 'generic_webhook' ? 'Webhook' : card.provider,
                        })}
                      </h3>
                      {/* Status as words next to the name, not only as a chip —
                          "needs setup" is the thing an admin is scanning for. */}
                      <p className="t-caption">{t(STATUS_LABEL[card.status])}</p>
                    </div>
                  </div>
                  <span
                    className={cn(
                      'mt-1 size-2 shrink-0 rounded-full',
                      card.status === 'connected' && 'bg-[var(--color-success)]',
                      card.status === 'configuration_required' && 'bg-[var(--color-warning)]',
                      card.status === 'error' && 'bg-[var(--color-danger)]',
                      card.status === 'not_connected' && 'bg-[var(--color-border-strong)]',
                    )}
                    aria-hidden
                  />
                </div>

                {card.integration && (
                  <dl className="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                    <div>
                      <dt className="t-caption text-[0.6875rem]">{t('integrations.leadsReceived')}</dt>
                      <dd className="tabular text-[0.9375rem] font-semibold" dir="ltr">
                        {formatNumber(card.integration.leads_received, i18n.language)}
                      </dd>
                    </div>
                    {card.integration.last_synced_at && (
                      <div>
                        <dt className="t-caption text-[0.6875rem]">{t('integrations.lastSync')}</dt>
                        <dd className="t-small">
                          {formatRelative(card.integration.last_synced_at, i18n.language)}
                        </dd>
                      </div>
                    )}
                  </dl>
                )}

                {card.required_credentials.length > 0 && card.status !== 'connected' && (
                  <p className="mt-2 text-[0.75rem] text-[var(--color-ink-subtle)]">
                    {t('integrations.credentialsNeeded')}: {card.required_credentials.join(', ')}
                  </p>
                )}

                {card.integration?.webhook_endpoints?.map((endpoint) => (
                  <div key={endpoint.id} className="mt-3 rounded-[var(--radius-control)] bg-[var(--color-surface-muted)] p-2.5">
                    <p className="text-[0.6875rem] font-medium text-[var(--color-ink-muted)]">
                      {t('integrations.webhookUrl')}
                    </p>
                    <div className="mt-1 flex items-center gap-1.5">
                      <code className="min-w-0 flex-1 truncate text-[0.6875rem]" dir="ltr">{endpoint.url}</code>
                      <CopyButton value={endpoint.url} />
                    </div>
                  </div>
                ))}
              </CardBody>

              <div className="border-t border-[var(--color-line)] p-3">
                {/*
                  Outline, not primary. Eight full-width accent buttons turned
                  the page into a wall of green shouting at an admin who is
                  reading status, not deciding to connect eight networks at once.
                */}
                <Button
                  variant="outline"
                  size="sm"
                  className="w-full"
                  loading={connect.isPending && connect.variables === card.provider}
                  onClick={() => connect.mutate(card.provider)}
                >
                  {card.integration ? t('integrations.generateEndpoint') : t('integrations.connect')}
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Card className="mt-5 overflow-hidden">
        <CardHeader title={t('integrations.logs')} />
        {!logs || logs.length === 0 ? (
          <EmptyState title={t('integrations.noLogs')} className="py-10" />
        ) : (
          <TableWrap>
            <Table>
              <thead>
                <tr>
                  <Th>{t('integrations.receivedAt')}</Th>
                  <Th>{t('integrations.provider')}</Th>
                  <Th>{t('integrations.result')}</Th>
                  <Th>{t('integrations.lead')}</Th>
                </tr>
              </thead>
              <tbody>
                {logs.map((log) => (
                  <Tr key={log.id}>
                    <Td className="whitespace-nowrap">{formatDateTime(log.received_at, i18n.language)}</Td>
                    <Td>{log.provider}</Td>
                    <Td>
                      <Badge
                        tone={
                          log.status === 'accepted' ? 'success' : log.status === 'duplicate' ? 'info' : 'danger'
                        }
                      >
                        {log.status}
                      </Badge>
                      {log.error && (
                        <p className="mt-0.5 max-w-60 truncate text-[0.75rem] text-[var(--color-ink-muted)]">
                          {log.error}
                        </p>
                      )}
                    </Td>
                    <Td>{log.lead?.full_name ?? '—'}</Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          </TableWrap>
        )}
      </Card>

      {/* The secret is shown exactly once; the dialog says so plainly. */}
      <Modal
        open={Boolean(secret)}
        onClose={() => setSecret(null)}
        title={t('integrations.webhookUrl')}
        description={t('integrations.secretOnce')}
        footer={<Button onClick={() => setSecret(null)}>{t('common.close')}</Button>}
      >
        <div className="space-y-4">
          <Field label={t('integrations.webhookUrl')} value={secret?.url ?? ''} />
          <Field label={t('integrations.webhookSecret')} value={secret?.secret ?? ''} />
          <pre
            dir="ltr"
            className="overflow-x-auto rounded-[var(--radius-control)] bg-[var(--color-ink)] p-3 text-[0.6875rem] leading-relaxed text-[var(--color-ink-inverted)]"
          >
{`curl -X POST '${secret?.url ?? ''}' \\
  -H 'Content-Type: application/json' \\
  -H 'X-Webhook-Secret: ${secret?.secret ?? ''}' \\
  -d '{"name":"Ahmed","mobile":"0512345678","city":"Riyadh"}'`}
          </pre>
        </div>
      </Modal>

      <ImportModal open={importOpen} onClose={() => setImportOpen(false)} />
    </>
  )
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="mb-1.5 text-[0.8125rem] font-medium">{label}</p>
      <div className="flex items-center gap-2">
        <code
          dir="ltr"
          className="min-w-0 flex-1 truncate rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] px-3 py-2 text-[0.75rem]"
        >
          {value}
        </code>
        <CopyButton value={value} />
      </div>
    </div>
  )
}

function CopyButton({ value }: { value: string }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  return (
    <button
      type="button"
      aria-label={copied ? t('common.copied') : t('common.copy')}
      onClick={() => {
        void navigator.clipboard.writeText(value)
        setCopied(true)
        setTimeout(() => setCopied(false), 1800)
      }}
      className={cn(
        'grid size-8 shrink-0 place-items-center rounded-[var(--radius-control)] border border-[var(--color-border)]',
        copied ? 'text-[var(--color-status-won)]' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-sunken)]',
      )}
    >
      {copied ? <Check className="size-4" aria-hidden /> : <Copy className="size-4" aria-hidden />}
    </button>
  )
}

/** Upload -> preview -> map -> import, in one dialog. */
function ImportModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const fileRef = useRef<HTMLInputElement>(null)

  const [step, setStep] = useState<'upload' | 'map' | 'done'>('upload')
  const [importId, setImportId] = useState('')
  const [headers, setHeaders] = useState<string[]>([])
  const [targets, setTargets] = useState<string[]>([])
  const [mapping, setMapping] = useState<Record<string, string>>({})
  const [result, setResult] = useState<{ imported: number; duplicates: number; failed: number } | null>(null)

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData()
      body.append('file', file)

      const response = await http.post('/imports', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })

      return response.data.data as {
        import: { id: string }
        headers: string[]
        suggested_mapping: Record<string, string>
        target_fields: string[]
      }
    },
    onSuccess: (data) => {
      setImportId(data.import.id)
      setHeaders(data.headers)
      setMapping(data.suggested_mapping)
      setTargets(data.target_fields)
      setStep('map')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const process = useMutation({
    mutationFn: () => apiPost(`/imports/${importId}/process`, { mapping }),
    onSuccess: async () => {
      const detail = await apiGet<{
        import: { imported_rows: number; duplicate_rows: number; failed_rows: number }
      }>(`/imports/${importId}`)

      setResult({
        imported: detail.import.imported_rows,
        duplicates: detail.import.duplicate_rows,
        failed: detail.import.failed_rows,
      })
      setStep('done')

      void queryClient.invalidateQueries({ queryKey: ['leads'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  function reset() {
    setStep('upload')
    setImportId('')
    setHeaders([])
    setMapping({})
    setResult(null)
    onClose()
  }

  return (
    <Modal
      open={open}
      onClose={reset}
      title={t('imports.title')}
      size="lg"
      footer={
        step === 'map' ? (
          <>
            <Button variant="ghost" onClick={reset}>{t('common.cancel')}</Button>
            <Button
              loading={process.isPending}
              disabled={Object.keys(mapping).length === 0}
              onClick={() => process.mutate()}
            >
              {t('imports.startImport')}
            </Button>
          </>
        ) : (
          <Button onClick={reset}>{t('common.close')}</Button>
        )
      }
    >
      {step === 'upload' && (
        <div className="py-6 text-center">
          <input
            ref={fileRef}
            type="file"
            accept=".csv,text/csv"
            className="sr-only"
            onChange={(e) => {
              const file = e.target.files?.[0]
              if (file) upload.mutate(file)
            }}
          />
          <Button
            size="lg"
            loading={upload.isPending}
            icon={<Upload className="size-4" />}
            onClick={() => fileRef.current?.click()}
          >
            {t('imports.upload')}
          </Button>
          <p className="mt-3 text-[0.8125rem] text-[var(--color-ink-muted)]">{t('imports.uploadHint')}</p>
        </div>
      )}

      {step === 'map' && (
        <div className="space-y-3">
          <p className="text-[0.8125rem] text-[var(--color-ink-muted)]">{t('imports.mappingHint')}</p>

          {headers.map((header) => (
            <div key={header} className="flex items-center gap-3">
              <code className="min-w-0 flex-1 truncate rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] px-3 py-2 text-[0.8125rem]">
                {header}
              </code>
              <Select
                value={mapping[header] ?? ''}
                onChange={(e) => {
                  const next = { ...mapping }
                  if (e.target.value) next[header] = e.target.value
                  else delete next[header]
                  setMapping(next)
                }}
                className="w-48 shrink-0"
                aria-label={header}
              >
                <option value="">{t('imports.ignore')}</option>
                {targets.map((field) => (
                  <option key={field} value={field}>
                    {t(`leads.${field}`, { defaultValue: field })}
                  </option>
                ))}
              </Select>
            </div>
          ))}
        </div>
      )}

      {step === 'done' && result && (
        <div className="py-6 text-center">
          <div className="mx-auto mb-4 grid size-12 place-items-center rounded-full bg-[color-mix(in_srgb,var(--color-status-won)_12%,transparent)] text-[var(--color-status-won)]">
            <Check className="size-6" aria-hidden />
          </div>
          <h3 className="text-[0.9375rem] font-semibold">{t('imports.done')}</h3>
          <div className="mt-4 flex justify-center gap-6 text-[0.8125rem]">
            <div>
              <p className="tabular text-xl font-semibold text-[var(--color-status-won)]">{result.imported}</p>
              <p className="text-[var(--color-ink-muted)]">{t('imports.imported')}</p>
            </div>
            <div>
              <p className="tabular text-xl font-semibold text-[var(--color-status-new)]">{result.duplicates}</p>
              <p className="text-[var(--color-ink-muted)]">{t('imports.duplicates')}</p>
            </div>
            <div>
              <p className="tabular text-xl font-semibold text-[var(--color-status-lost)]">{result.failed}</p>
              <p className="text-[var(--color-ink-muted)]">{t('imports.failed')}</p>
            </div>
          </div>
        </div>
      )}
    </Modal>
  )
}
