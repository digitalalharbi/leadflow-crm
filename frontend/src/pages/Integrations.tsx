import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Check, Copy, FileUp, KeyRound, RefreshCw, Sparkles, Upload,
} from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { IntegrationGrid } from '@/components/integrations/IntegrationGrid'
import { SheetSources } from '@/components/integrations/SheetSources'
import { apiGet, apiPatch, apiPost, http } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatDateTime, formatNumber, formatLastSeen } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Drawer, EmptyState, ErrorState,
  Modal, PageHeader, Select, Skeleton, Table, TableWrap, Td, Th, Tr,
} from '@/components/ui'
import type { IntegrationCard, SyncResult } from '@/types/api'
import { cn } from '@/lib/cn'
import { ProviderMark } from '@/components/integrations/ProviderMark'
import { WhatsappSettingsCard } from '@/components/whatsapp/WhatsappSettingsCard'
import { ChartCard, IngestionChart, IngestionSources } from '@/components/charts'
import { fetchIngestion } from '@/features/ingestion'

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
  /** Detail starts closed: the grid above is the answer, this is the evidence. */
  const [detailOpen, setDetailOpen] = useState(false)
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

  const { data: cards, isLoading, isError, error, refetch } = useQuery({
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

  const [credentialsFor, setCredentialsFor] = useState<IntegrationCard | null>(null)
  const [syncResult, setSyncResult] = useState<{ provider: string; result: SyncResult } | null>(null)

  /**
   * Ask the source now, rather than waiting for the five-minute poll.
   *
   * Without it a workspace saves a token, sees nothing change, and cannot tell
   * a wrong token from a quiet afternoon — so the first thing anybody does is
   * paste it again.
   */
  const sync = useMutation({
    mutationFn: (id: string) => apiPost<{ result: SyncResult }>(`/integrations/${id}/sync`, {}),
    onSuccess: (response, id) => {
      const card = cards?.find((c) => c.integration?.id === id)
      setSyncResult({ provider: card?.provider ?? '', result: response.result })
      void queryClient.invalidateQueries({ queryKey: ['integrations'] })
      void queryClient.invalidateQueries({ queryKey: ['ingestion', 30] })
    },
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

      {/* Leads that arrive in a spreadsheet, on the same page as every other
          source — because that is what it is. */}
      <div className="mb-3">
        <SheetSources onImportFile={() => setImportOpen(true)} />
      </div>

      {/* Every source on one screen, above everything else: the question
          anybody opens this page with is whether anything is still arriving. */}
      <div className="mb-3">
        <IntegrationGrid />
      </div>

      {/*
        Everything below is detail, and detail is one press away rather than a
        screen to scroll past.

        The question anybody opens this page with is whether leads are still
        arriving, and the grid above answers it in the first viewport. The
        chart, the connection cards, the webhook secrets and the delivery log
        are what you open *after* the grid has told you something is wrong —
        so they start closed.
      */}
      <div className="mb-3">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setDetailOpen((open) => !open)}
          data-testid="toggle-integration-detail"
          aria-expanded={detailOpen}
        >
          {detailOpen ? t('integrations.hideDetail') : t('integrations.showDetail')}
        </Button>
      </div>

      {detailOpen && (
        <div className="space-y-3" data-testid="integration-detail">
      {/*
        Arrivals come first, above the list of connections.
        The list cannot answer the question anybody actually opens this page
        with — is anything still coming in? A form that stopped posting eleven
        days ago still shows a green badge and a valid secret.
      */}
      <div className="mb-4">
        <IngestionCard />
      </div>

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
          {cards?.map((card) => (
            /*
              `min-w-0`, or the page scrolls sideways on a phone.

              A grid item's automatic minimum size is its min-content width, and
              one of these cards holds a webhook URL that does not wrap. The
              single column grew to 689px inside a 343px screen, so the whole
              integrations page scrolled horizontally and the cards sat mostly
              off-screen. The `truncate` on the URL could not help: the column
              had already been sized to fit it.
            */
            <Card key={card.provider} className="flex min-w-0 flex-col">
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
                          {formatLastSeen(card.integration.last_synced_at, i18n.language)}
                        </dd>
                      </div>
                    )}
                  </dl>
                )}

                {/*
                  Why it is failing, on the page whose job is to say so.

                  The card showed a red dot and the word "خطأ" and nothing else,
                  so an admin learned that something was wrong and had to go to
                  the data-quality screen to find out what. The provider's own
                  words, not ours: "invalid OAuth access token" tells somebody
                  what to go and fix, and anything we paraphrase it into does not.
                */}
                {card.integration?.last_error && (
                  <p
                    /*
                      `auto`, so the browser takes the direction from the text
                      itself. A provider's message is almost always English, and
                      inside an RTL paragraph its trailing full stop jumps to the
                      front of the wrapped line — "…has expired" / ".on Monday,
                      06-Oct-26" — which reads as a typo in our product rather
                      than as Meta's own words. An Arabic message still reads
                      right-to-left.
                    */
                    dir="auto"
                    className="notice notice-danger mt-3 text-start text-[0.75rem]"
                    data-testid={`integration-error-${card.provider}`}
                  >
                    {card.integration.last_error}
                  </p>
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
                <div className="space-y-2">
                  {/*
                    A pulled source has no webhook URL to hand out, so offering
                    one is noise; a pushed source has nothing to poll, so a
                    "sync now" button on it would do nothing and say nothing.
                    They are different integrations and they get different
                    buttons.
                  */}
                  {!card.pulls_leads && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="w-full"
                      loading={connect.isPending && connect.variables === card.provider}
                      onClick={() => connect.mutate(card.provider)}
                    >
                      {card.integration ? t('integrations.generateEndpoint') : t('integrations.connect')}
                    </Button>
                  )}

                  {card.required_credentials.length > 0 && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="w-full"
                      icon={<KeyRound className="size-4" />}
                      onClick={() => setCredentialsFor(card)}
                      data-testid={`credentials-${card.provider}`}
                    >
                      {card.integration?.configured_credentials.length
                        ? t('integrations.editCredentials')
                        : t('integrations.addCredentials')}
                    </Button>
                  )}

                  {card.pulls_leads && card.integration && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="w-full"
                      icon={<RefreshCw className="size-4" />}
                      loading={sync.isPending && sync.variables === card.integration.id}
                      onClick={() => sync.mutate(card.integration!.id)}
                      data-testid={`sync-${card.provider}`}
                    >
                      {t('integrations.syncNow')}
                    </Button>
                  )}
                </div>
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

        </div>
      )}

      {/* The secret is shown exactly once; the dialog says so plainly. */}
      <CredentialsModal
        card={credentialsFor}
        onClose={() => setCredentialsFor(null)}
        onSaved={() => {
          setCredentialsFor(null)
          void queryClient.invalidateQueries({ queryKey: ['integrations'] })
        }}
      />

      <Modal
        open={Boolean(syncResult)}
        onClose={() => setSyncResult(null)}
        title={t('integrations.syncResult')}
        footer={<Button onClick={() => setSyncResult(null)}>{t('common.close')}</Button>}
      >
        {syncResult && (
          <div className="space-y-3">
            <p className="t-small">
              {t(`platforms.${syncResult.provider}`, { defaultValue: syncResult.provider })}
            </p>

            {syncResult.result.error ? (
              /*
                The provider's own words, not ours. "Could not connect" sends an
                admin to their network; "(#190) access token expired" sends them
                to the one place that can fix it.
              */
              <p className="t-small rounded-[var(--radius-control)] bg-[var(--color-danger-soft)] p-3 text-[var(--color-danger)]">
                {syncResult.result.error}
              </p>
            ) : (
              <dl className="grid grid-cols-2 gap-3">
                {(['fetched', 'created', 'duplicates', 'skipped', 'rejected', 'failed'] as const).map((key) => (
                  <div key={key}>
                    <dt className="t-caption">{t(`integrations.sync_${key}`)}</dt>
                    <dd className="tabular text-[0.9375rem] font-semibold" dir="ltr">
                      {formatNumber(syncResult.result[key], i18n.language)}
                    </dd>
                  </div>
                ))}
              </dl>
            )}
          </div>
        )}
      </Modal>

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

/**
 * Whether leads are still arriving, and how many of them are the same lead.
 *
 * The headline is the count and the duplicate share side by side. A rate on
 * its own is unreadable — 50% of two events is not the same problem as 50% of
 * four hundred — so the total is stated beside it rather than under a hover.
 */
function IngestionCard() {
  const { t, i18n } = useTranslation()

  const { data, isLoading } = useQuery({
    queryKey: ['ingestion', 30],
    queryFn: () => fetchIngestion(30),
  })

  const summary = data?.summary
  const empty = !isLoading && (summary?.total ?? 0) === 0

  return (
    <ChartCard
      title={t('charts.ingestion.title')}
      description={t('charts.ingestion.hint')}
      height={200}
      loading={isLoading}
      empty={empty}
      emptyMessage={t('charts.ingestion.empty')}
      testId="ingestion-card"
      table={
        data && {
          head: [t('charts.journey.when'), t('charts.ingestion.accepted'), t('charts.ingestion.duplicates')],
          rows: data.daily.map((day) => [day.date, day.accepted, day.duplicates]),
        }
      }
      footer={
        summary && summary.total > 0 ? (
          <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
            <span className="t-caption">
              {t('charts.ingestion.total')}{' '}
              <span className="tabular t-body font-medium" dir="ltr">
                {formatNumber(summary.total, i18n.language)}
              </span>
            </span>
            <span className="t-caption">
              {t('charts.ingestion.duplicateRate')}{' '}
              <span className="tabular t-body font-medium" dir="ltr">
                {summary.duplicate_rate === null
                  ? t('charts.ingestion.noRate')
                  : `${summary.duplicate_rate}%`}
              </span>
            </span>
          </div>
        ) : undefined
      }
    >
      <div className="space-y-2">
        <IngestionChart days={data?.daily ?? []} />
        {data && data.by_provider.length > 0 && (
          <div className="border-t border-[var(--color-line)] pt-3">
            <p className="t-card-title px-5 pb-0.5">{t('charts.ingestion.sources')}</p>
            <p className="t-caption px-5 pb-2">{t('charts.ingestion.sourcesHint')}</p>
            <IngestionSources sources={data.by_provider} />
          </div>
        )}
      </div>
    </ChartCard>
  )
}

/**
 * Where an access token is actually entered.
 *
 * The page named the credentials each source needs and offered no way to
 * supply them, so every pull-based provider — TikTok, Snapchat, LinkedIn,
 * Google Sheets — could be "connected" and could never deliver a lead. The
 * only route in was an API call the customer was never told about.
 *
 * Values are write-only. The server returns which keys are set and never what
 * they are, so a saved secret shows as saved and an empty field means "leave
 * it alone" rather than "clear it".
 */
function CredentialsModal({
  card,
  onClose,
  onSaved,
}: {
  card: IntegrationCard | null
  onClose: () => void
  onSaved: () => void
}) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [values, setValues] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: async () => {
      const filled = Object.fromEntries(
        Object.entries(values).filter(([, value]) => value.trim() !== ''),
      )

      // A provider with no integration row yet has to be created before it can
      // be given anything to hold.
      const id =
        card?.integration?.id ??
        (await apiPost<{ integration: { id: string } }>('/integrations', { provider: card?.provider }))
          .integration.id

      return apiPatch(`/integrations/${id}`, { credentials: filled })
    },
    onSuccess: () => {
      setValues({})
      toast(t('integrations.credentialsSaved'), 'success')
      onSaved()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    /*
      A source's settings are a drawer, not a dialog: the grid of sources stays
      visible beside it, which is how somebody checks one source's keys against
      the state of the rest without losing their place.
    */
    <Drawer
      open={Boolean(card)}
      onClose={onClose}
      testId="credentials-drawer"
      title={t('integrations.credentialsTitle')}
      description={t('integrations.credentialsHint')}
      footer={
        <>
          <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
          <Button loading={save.isPending} onClick={() => save.mutate()} data-testid="save-credentials">
            {t('common.save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {card?.required_credentials.map((key) => {
          const stored = card.integration?.configured_credentials.includes(key)

          return (
            <label key={key} className="block">
              <span className="t-small mb-1 block font-medium" dir="ltr">{key}</span>
              <input
                type="password"
                autoComplete="off"
                dir="ltr"
                data-testid={`credential-${key}`}
                placeholder={stored ? t('integrations.credentialStored') : ''}
                value={values[key] ?? ''}
                onChange={(event) => setValues((previous) => ({ ...previous, [key]: event.target.value }))}
                className="w-full rounded-[var(--radius-control)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-[0.875rem]"
              />
            </label>
          )
        })}
      </div>
    </Drawer>
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
