import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, Columns3, Copy, RefreshCw, Sheet, Trash2, Zap } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Modal, Select, Skeleton,
} from '@/components/ui'
import {
  appsScriptFor, confidenceLevel, connectSheet, disconnectGoogle, disconnectSheet, fetchPickerCredentials, fetchSheets,
  fetchTabs, previewSheet, remapSheet, startGoogleAuthorization, syncSheet, type MappingChoice, type SheetSource,
} from '@/features/sheets'
import { cn } from '@/lib/cn'
import { pickSpreadsheet } from '@/lib/googlePicker'

/**
 * Leads that arrive in a spreadsheet.
 *
 * The flow is four steps and no developer: pick the spreadsheet, pick the tab,
 * say which column is which, and look at ten real rows mapped before anything
 * is imported. After that the schedule does the work, and this screen only has
 * to explain itself when something breaks.
 *
 * `onImportFile` is the page's CSV importer. The empty state offers it because
 * somebody who has not connected Google still has the rows — usually in a file
 * on their machine — and the worst thing an empty integration card can do is
 * leave them with nothing to press.
 */
export function SheetSources({ onImportFile }: { onImportFile?: () => void }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [open, setOpen] = useState(false)
  const [reviewing, setReviewing] = useState<SheetSource | null>(null)
  const [instant, setInstant] = useState<SheetSource | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['sheets'], queryFn: fetchSheets })

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['sheets'] })

  const sync = useMutation({
    mutationFn: (id: string) => syncSheet(id),
    onSuccess: () => {
      toast(t('sheets.syncQueued'), 'success')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  /*
   * Leaves the app on purpose.
   *
   * `window.location` rather than a new tab: a consent screen opened in a tab
   * the browser blocks, or that the person closes without noticing, is a flow
   * that silently does nothing. Google sends them back to this same screen
   * with the answer in the query string.
   */
  const authorize = useMutation({
    mutationFn: startGoogleAuthorization,
    onSuccess: ({ url }) => { window.location.href = url },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const forget = useMutation({
    mutationFn: disconnectGoogle,
    onSuccess: () => {
      toast(t('sheets.disconnected'), 'success')
      refresh()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const remove = useMutation({
    mutationFn: (id: string) => disconnectSheet(id),
    onSuccess: refresh,
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  if (isLoading) return <Skeleton className="h-40" />

  const connection = data?.connection
  const sources = data?.sources ?? []

  return (
    <Card>
      <CardHeader
        title={t('sheets.title')}
        description={t('sheets.hint')}
        action={
          /* The empty state carries its own, so this would be the same button
             twice a few pixels apart. */
          connection?.connected && sources.length > 0 ? (
            <span className="flex items-center gap-2">
              <Button size="sm" variant="outline" onClick={() => setOpen(true)} data-testid="connect-sheet">
                {t('sheets.connect')}
              </Button>
              {/* Hands the grant back to Google, so "disconnect" is true. */}
              <Button
                size="sm"
                variant="ghost"
                loading={forget.isPending}
                onClick={() => forget.mutate()}
                data-testid="disconnect-google"
              >
                {t('sheets.disconnectGoogle')}
              </Button>
            </span>
          ) : undefined
        }
      />
      <CardBody>
        {sources.length === 0 && (
          <SheetsEmptyState
            /*
              Two different problems with one symptom, kept apart: the platform
              has no OAuth client (ours), or this workspace has not completed
              the grant (theirs). Showing the first as a customer error is how
              support tickets get opened against the wrong team.
            */
            reason={
              connection?.connected
                ? t('sheets.none')
                : connection?.oauth_configured
                  ? t('sheets.notConnected')
                  : t('sheets.oauthMissing')
            }
            /*
              Only when the workspace actually holds a Google token. With an
              OAuth client but no grant, the picker opens onto an API call that
              cannot succeed — an error dressed as a next step. The reason text
              beside it already says which of the two is missing, and the other
              two buttons still work.
            */
            canConnect={Boolean(connection?.connected)}
            /*
             * Two different buttons behind one position: with a Google account
             * connected this picks a spreadsheet, and without one it starts the
             * grant that makes picking possible. They were the same button and
             * it only ever did the first.
             */
            canAuthorize={Boolean(connection?.oauth_configured) && !connection?.connected}
            authorizing={authorize.isPending}
            onAuthorize={() => authorize.mutate()}
            onConnect={() => setOpen(true)}
            onImportFile={onImportFile}
          />
        )}

        <ul className="space-y-3" data-testid="sheet-sources">
          {sources.map((source) => (
            <li key={source.id} className="border-b border-[var(--color-line)] pb-3 last:border-0 last:pb-0">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="t-small inline-flex items-center gap-1.5 font-medium">
                    <Sheet className="size-4" />
                    {source.spreadsheet_name ?? source.spreadsheet_id}
                    <span className="t-caption">· {source.sheet_name}</span>
                  </p>
                  <p className="t-caption">
                    <LastRun source={source} />
                  </p>
                </div>

                <div className="flex items-center gap-2">
                  <Badge tone={HEALTH_TONE[source.health.state]} testId={`sheet-${source.sheet_name}-health`}>
                    {t(`sheets.state.${source.health.state}`)}
                  </Badge>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('sheets.syncNow')}
                    loading={sync.isPending}
                    onClick={() => sync.mutate(source.id)}
                  >
                    <RefreshCw className="size-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('sheets.disconnect')}
                    onClick={() => remove.mutate(source.id)}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              </div>

              <MappingSummary
                source={source}
                onReview={() => setReviewing(source)}
                onInstant={() => setInstant(source)}
              />

              {/* The rows the sheet refused, so somebody can fix the sheet. */}
              {(source.last_run?.failures?.length ?? 0) > 0 && (
                <ul className="mt-1.5 space-y-0.5" data-testid="sheet-failures">
                  {source.last_run?.failures?.slice(0, 5).map((failure) => (
                    <li key={failure.row} className="t-caption text-[var(--color-danger)]">
                      {t('sheets.rowRefused', { row: failure.row, reason: failure.reason })}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      </CardBody>

      {reviewing && (
        <ReviewMapping
          source={reviewing}
          onClose={() => setReviewing(null)}
          onSaved={() => { setReviewing(null); refresh() }}
        />
      )}

      {instant && <InstantSync source={instant} onClose={() => setInstant(null)} />}

      {open && (
        <ConnectSheet
          pickerConfigured={Boolean(connection?.picker_configured)}
          onClose={() => setOpen(false)}
          onConnected={() => { setOpen(false); refresh() }}
        />
      )}
    </Card>
  )

  function LastRun({ source }: { source: SheetSource }) {
    const run = source.last_run

    if (!run) return <>{t('sheets.neverSynced')}</>

    return (
      <>
        {t('sheets.lastRun', {
          when: run.started_at ? formatLastSeen(run.started_at, i18n.language) : '—',
          imported: run.imported,
          duplicates: run.duplicates,
          failed: run.failed,
        })}
      </>
    )
  }
}

const HEALTH_TONE: Record<string, 'success' | 'danger' | 'warning' | 'neutral'> = {
  connected: 'success',
  error: 'danger',
  never_synced: 'warning',
  paused: 'neutral',
}

/**
 * Pick a spreadsheet, pick a tab, map the columns, look before you import.
 *
 * The first step is Google's own file picker rather than a list of the account's
 * spreadsheets. The grant is `drive.file`: it covers files a person chose and
 * nothing else in their Drive, so there is no list to show — and choosing a file
 * in the picker is also the act that puts it inside the grant. Everything after
 * that step is unchanged.
 */
function ConnectSheet({
  pickerConfigured,
  onClose,
  onConnected,
}: {
  pickerConfigured: boolean
  onClose: () => void
  onConnected: () => void
}) {
  const { t, i18n } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [spreadsheet, setSpreadsheet] = useState<{ id: string; name: string } | null>(null)
  const [tab, setTab] = useState('')
  const [mapping, setMapping] = useState<Record<string, string>>({})

  const choose = useMutation({
    mutationFn: async () => {
      const credentials = await fetchPickerCredentials()

      return pickSpreadsheet(credentials, i18n.language)
    },
    onSuccess: (file) => {
      // Cancelled is not an error, and says nothing.
      if (!file) return

      setSpreadsheet(file)
      setTab('')
      setMapping({})
    },
    onError: (caught) => {
      // The script itself being blocked — an extension, a network policy — is a
      // different problem from the API refusing, and the person can fix it.
      if (caught instanceof Error && caught.message.startsWith('picker_')) {
        toast(t('sheets.pickerBlocked'), 'error')

        return
      }

      toast(translateError(caught), 'error')
    },
  })

  const { data: tabs } = useQuery({
    queryKey: ['sheets-tabs', spreadsheet?.id],
    queryFn: () => fetchTabs(spreadsheet!.id),
    enabled: Boolean(spreadsheet),
    retry: false,
  })

  const { data: preview } = useQuery({
    queryKey: ['sheets-preview', spreadsheet?.id, tab, mapping],
    queryFn: () => previewSheet({ spreadsheet_id: spreadsheet!.id, sheet_name: tab, mapping }),
    enabled: Boolean(spreadsheet && tab),
    retry: false,
  })

  const save = useMutation({
    mutationFn: () =>
      connectSheet({
        spreadsheet_id: spreadsheet!.id,
        spreadsheet_name: spreadsheet!.name,
        sheet_name: tab,
        // Only what the person chose; the server works out the rest.
        mapping: Object.fromEntries(Object.entries(mapping).filter(([, value]) => value)),
      }),
    onSuccess: () => {
      toast(t('sheets.connected'), 'success')
      onConnected()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  // A sheet with no way to contact anybody is not a lead source.
  const ready = Boolean(spreadsheet && tab && (preview?.guess.usable || mapping.phone || mapping.email))

  return (
    <Modal
      open
      onClose={onClose}
      title={t('sheets.connect')}
      size="lg"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button disabled={!ready} loading={save.isPending} onClick={() => save.mutate()} data-testid="save-sheet">
            {t('sheets.import')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] border border-[var(--color-line)] p-3">
          <div className="min-w-0">
            <p className="t-small font-medium">{t('sheets.spreadsheet')}</p>
            <p className="t-caption truncate" data-testid="picked-spreadsheet">
              {spreadsheet?.name ?? (pickerConfigured ? t('sheets.pickHint') : t('sheets.pickerMissing'))}
            </p>
          </div>

          <Button
            variant={spreadsheet ? 'outline' : 'primary'}
            size="sm"
            loading={choose.isPending}
            disabled={!pickerConfigured}
            onClick={() => choose.mutate()}
            data-testid="choose-spreadsheet"
          >
            {spreadsheet ? t('sheets.chooseAnother') : t('sheets.choose')}
          </Button>
        </div>

        {spreadsheet && (
          <Select label={t('sheets.tab')} value={tab} data-testid="sheet-tab" onChange={(e) => setTab(e.target.value)}>
            <option value="">—</option>
            {tabs?.tabs.map((name) => <option key={name} value={name}>{name}</option>)}
          </Select>
        )}

        {preview && (
          <>
            {/*
              Worked out from the header and ten real rows. Only a column that
              is genuinely ambiguous asks for a choice; everything else is
              shown with how sure the guess is, and every unknown column is
              kept as a custom field rather than dropped.
            */}
            <MappingReview
              mapping={{ ...preview.guess.mapping, ...Object.fromEntries(Object.entries(mapping).filter(([, v]) => v)) }}
              confidence={preview.guess.confidence}
              ambiguous={preview.guess.ambiguous.filter((choice) => !mapping[choice.field])}
              custom={Object.keys(preview.guess.custom)}
              onChoose={(field, header) => setMapping((prev) => ({ ...prev, [field]: header ?? '' }))}
            />

            {/*
              Ten real rows, mapped. The step that stops a mistake becoming four
              thousand leads with the city in the name field.
            */}
            <div>
              <p className="t-small mb-1 font-medium">{t('sheets.preview')}</p>
              <ul className="space-y-1" data-testid="sheet-preview">
                {preview.mapped.slice(0, 5).map((row, index) => (
                  <li key={index} className="t-caption">
                    {Object.entries(row).filter(([, value]) => value).map(([field, value]) => `${t(`sheets.field.${field}`)}: ${value}`).join(' · ') || '—'}
                  </li>
                ))}
              </ul>
            </div>
          </>
        )}
      </div>
    </Modal>
  )
}

const LEVEL_STYLE: Record<string, string> = {
  certain: 'bg-[color-mix(in_srgb,var(--brand)_12%,transparent)] text-[var(--brand)]',
  likely: 'bg-[var(--color-chrome-fill)] text-[var(--color-ink-muted)]',
  check: 'bg-[color-mix(in_srgb,var(--color-warning)_15%,transparent)] text-[color-mix(in_srgb,var(--color-warning)_80%,black)]',
}

/**
 * The mapping as a picture: which column feeds which field and how sure we
 * are, the one or two choices only a person can make, and the columns kept as
 * custom fields. Nothing in a sheet is dropped, and this is where that shows.
 */
function MappingReview({
  mapping,
  confidence,
  ambiguous,
  custom,
  onChoose,
}: {
  mapping: Record<string, string>
  confidence: Record<string, number>
  ambiguous: MappingChoice[]
  custom: string[]
  onChoose?: (field: string, header: string | null) => void
}) {
  const { t } = useTranslation()
  const mapped = Object.entries(mapping).filter(([, header]) => header)
  const total = mapped.length + custom.length

  return (
    <div className="space-y-3" data-testid="mapping-review">
      <div>
        <div className="mb-1 flex items-center justify-between text-[0.75rem] text-[var(--color-ink-muted)]">
          <span>{t('sheets.mapping.known', { count: mapped.length })}</span>
          <span>{t('sheets.mapping.customCount', { count: custom.length })}</span>
        </div>
        <div className="flex h-2 gap-0.5 overflow-hidden rounded-full bg-[var(--color-chrome-fill)]" dir="ltr">
          {total > 0 && <span className="h-full bg-[var(--brand)]" data-chart-mark style={{ width: `${(mapped.length / total) * 100}%` }} />}
          {custom.length > 0 && <span className="h-full flex-1 bg-[var(--color-ink-subtle)]" data-chart-mark />}
        </div>
      </div>

      {ambiguous.length > 0 && onChoose && (
        <div className="space-y-2 rounded-xl bg-[color-mix(in_srgb,var(--color-warning)_10%,transparent)] p-3" data-testid="mapping-ambiguous">
          <p className="text-[0.8125rem] font-semibold">{t('sheets.mapping.chooseTitle')}</p>
          {ambiguous.map((choice) => (
            <Select
              key={choice.field}
              label={t(`sheets.field.${choice.field}`)}
              value={mapping[choice.field] ?? ''}
              data-testid={`choose-${choice.field}`}
              onChange={(event) => onChoose(choice.field, event.target.value || null)}
            >
              {choice.candidates.map((header) => <option key={header} value={header}>{header}</option>)}
            </Select>
          ))}
        </div>
      )}

      <ul className="grid gap-1.5 sm:grid-cols-2" data-testid="mapping-fields">
        {mapped.map(([field, header]) => {
          const level = confidenceLevel(confidence[field])

          return (
            <li key={field} className="flex min-w-0 items-center gap-2 rounded-xl bg-[var(--color-surface-muted)] px-2.5 py-1.5" data-testid={`mapped-${field}`}>
              <span className="w-20 shrink-0 truncate text-[0.75rem] font-semibold">{t(`sheets.field.${field}`, { defaultValue: field })}</span>
              <ArrowRight className="size-3 shrink-0 text-[var(--color-ink-subtle)] rtl:-scale-x-100" aria-hidden />
              <span className="min-w-0 flex-1 truncate text-[0.75rem] text-[var(--color-ink-muted)]" dir="auto">{header}</span>
              <span className={cn('shrink-0 rounded-full px-1.5 py-0.5 text-[0.625rem] font-medium', LEVEL_STYLE[level])}>
                {t(`sheets.mapping.level.${level}`)}
              </span>
            </li>
          )
        })}
      </ul>

      {custom.length > 0 && (
        <div data-testid="mapping-custom">
          <p className="mb-1 text-[0.75rem] font-semibold">{t('sheets.mapping.customTitle')}</p>
          <ul className="flex flex-wrap gap-1">
            {custom.map((header) => (
              <li key={header} className="rounded-full bg-[var(--color-chrome-fill)] px-2 py-0.5 text-[0.6875rem]" dir="auto">{header}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

/** One line under a connected sheet: what is mapped, and what still wants a person. */
function MappingSummary({ source, onReview, onInstant }: { source: SheetSource; onReview: () => void; onInstant: () => void }) {
  const { t, i18n } = useTranslation()
  const mapped = Object.values(source.mapping ?? {}).filter(Boolean).length

  return (
    <div className="mt-2 flex flex-wrap items-center gap-1.5" data-testid={`sheet-${source.sheet_name}-mapping`}>
      <button type="button" onClick={onReview} className="inline-flex items-center gap-1 rounded-full bg-[var(--color-chrome-fill)] px-2.5 py-1 text-[0.6875rem] hover:text-[var(--color-ink)]">
        <Columns3 className="size-3" aria-hidden />
        {t('sheets.mapping.summary', { mapped, custom: source.custom_columns.length })}
      </button>
      {source.ambiguous.length > 0 && (
        <button
          type="button"
          onClick={onReview}
          className="rounded-full bg-[color-mix(in_srgb,var(--color-warning)_15%,transparent)] px-2.5 py-1 text-[0.6875rem] font-medium"
          data-testid="mapping-needs-choice"
        >
          {t('sheets.mapping.needsChoice', { field: t(`sheets.field.${source.ambiguous[0].field}`) })}
        </button>
      )}
      <button type="button" onClick={onInstant} className="inline-flex items-center gap-1 rounded-full bg-[var(--color-chrome-fill)] px-2.5 py-1 text-[0.6875rem] hover:text-[var(--color-ink)]" data-testid="sheet-instant">
        <Zap className={cn('size-3', source.last_push_at && 'text-[var(--brand)]')} aria-hidden />
        {source.last_push_at
          ? t('sheets.instant.on', { when: formatLastSeen(source.last_push_at, i18n.language) })
          : t('sheets.instant.off')}
      </button>
    </div>
  )
}

function ReviewMapping({ source, onClose, onSaved }: { source: SheetSource; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()
  const [choices, setChoices] = useState<Record<string, string | null>>({})

  const save = useMutation({
    mutationFn: () => remapSheet(source.id, choices),
    onSuccess: () => { toast(t('sheets.mapping.saved'), 'success'); onSaved() },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  return (
    <Modal
      open
      onClose={onClose}
      title={t('sheets.mapping.title')}
      description={t('sheets.mapping.hint')}
      size="lg"
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button disabled={Object.keys(choices).length === 0} loading={save.isPending} onClick={() => save.mutate()} data-testid="save-mapping">
            {t('common.save')}
          </Button>
        </>
      }
    >
      <MappingReview
        mapping={{ ...source.mapping, ...Object.fromEntries(Object.entries(choices).filter(([, v]) => v)) as Record<string, string> }}
        confidence={source.confidence}
        ambiguous={source.ambiguous.length > 0 ? source.ambiguous : []}
        custom={source.custom_columns}
        onChoose={(field, header) => setChoices((prev) => ({ ...prev, [field]: header }))}
      />
    </Modal>
  )
}

/**
 * Seconds rather than a minute: the sheet tells us itself when a row is added.
 * Three lines of Apps Script and one trigger, done once per sheet by whoever
 * owns it — Google offers no push for sheets otherwise.
 */
function InstantSync({ source, onClose }: { source: SheetSource; onClose: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const script = appsScriptFor(source.ping_url)

  return (
    <Modal open onClose={onClose} title={t('sheets.instant.title')} description={t('sheets.instant.hint')} size="lg">
      <ol className="mb-3 space-y-1.5 text-[0.8125rem]">
        {(['open', 'paste', 'trigger'] as const).map((step, index) => (
          <li key={step} className="flex items-start gap-2">
            <span className="grid size-5 shrink-0 place-items-center rounded-full bg-[var(--brand)] text-[0.6875rem] font-bold text-[var(--brand-contrast)]">{index + 1}</span>
            {t(`sheets.instant.step.${step}`)}
          </li>
        ))}
      </ol>
      <div className="mb-1.5 flex justify-end">
        <Button
          size="sm"
          variant="outline"
          icon={<Copy className="size-3.5" />}
          onClick={() => void navigator.clipboard?.writeText(script).then(() => toast(t('sheets.instant.copied'), 'success'))}
        >
          {t('sheets.instant.copy')}
        </Button>
      </div>
      <pre className="overflow-x-auto rounded-xl bg-[var(--color-surface-muted)] p-3 font-mono text-[0.6875rem] leading-relaxed" dir="ltr" data-testid="apps-script">{script}</pre>
    </Modal>
  )
}

/**
 * What the card says when there is nothing in it yet.
 *
 * An integration card with no integrations used to be one grey sentence in a
 * full-width panel — accurate, and a dead end: it named a blocker and offered
 * no way past it. This gives the three things somebody in that state can
 * actually do. Connecting is the real answer; importing a file is the answer
 * for today, before anyone has approved an OAuth grant; and the mapping example
 * answers the question that stops people connecting at all, which is what the
 * product will do with their columns.
 *
 * It is laid out as one row rather than a stack: the card is full width, and a
 * short sentence alone in it is exactly the hole this is here to close.
 */
function SheetsEmptyState({
  reason,
  canConnect,
  canAuthorize,
  authorizing,
  onAuthorize,
  onConnect,
  onImportFile,
}: {
  reason: string
  canConnect: boolean
  canAuthorize: boolean
  authorizing: boolean
  onAuthorize: () => void
  onConnect: () => void
  onImportFile?: () => void
}) {
  const { t } = useTranslation()
  const [example, setExample] = useState(false)

  return (
    <div
      className="flex flex-wrap items-center justify-between gap-3"
      data-testid="sheets-empty"
    >
      <div className="flex min-w-0 items-start gap-2.5">
        <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]">
          <Sheet className="size-[1.125rem]" aria-hidden />
        </span>
        <div className="min-w-0">
          <p className="t-small font-medium">{t('sheets.empty.title')}</p>
          {/* The honest reason, not a slogan — it is what decides which of the
              buttons beside it is the one that will work. */}
          <p className="t-caption">{reason}</p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {/*
          Offered even when the platform has no OAuth client, and disabled with
          the reason showing. Hiding it would leave the card looking as though
          connecting a sheet were not a feature.
        */}
        {canAuthorize ? (
          <Button
            size="sm"
            loading={authorizing}
            onClick={onAuthorize}
            data-testid="authorize-google"
          >
            {t('sheets.authorize')}
          </Button>
        ) : (
          <Button
            size="sm"
            disabled={!canConnect}
            title={canConnect ? undefined : reason}
            onClick={onConnect}
            data-testid="empty-connect-sheet"
          >
            {t('sheets.connect')}
          </Button>
        )}

        {onImportFile && (
          <Button size="sm" variant="outline" onClick={onImportFile} data-testid="empty-import-file">
            {t('sheets.empty.importFile')}
          </Button>
        )}

        <Button
          size="sm"
          variant="ghost"
          onClick={() => setExample(true)}
          data-testid="empty-see-mapping"
        >
          {t('sheets.empty.seeMapping')}
        </Button>
      </div>

      {example && <MappingExample onClose={() => setExample(false)} />}
    </div>
  )
}

/** The columns a sheet usually has, beside the field each one becomes. */
const EXAMPLE_FIELDS = ['full_name', 'phone', 'city', 'project', 'notes'] as const

/**
 * The question that stops people connecting, answered before they connect.
 *
 * It is deliberately an example and not a configuration screen: the real
 * mapping is chosen per sheet inside ConnectSheet, against that sheet's own
 * headers. This is here so somebody can see what they are agreeing to first.
 */
function MappingExample({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()

  return (
    <Modal open onClose={onClose} title={t('sheets.empty.mappingTitle')} description={t('sheets.empty.mappingHint')}>
      <ul className="space-y-1.5" data-testid="mapping-example">
        {EXAMPLE_FIELDS.map((field) => (
          <li
            key={field}
            className="flex items-center justify-between gap-3 rounded-[var(--radius-control)] bg-[var(--color-surface-sunken)] px-3 py-2"
          >
            <span className="t-small truncate">{t(`sheets.empty.column.${field}`)}</span>
            {/* Points from the sheet's column to the field it becomes, which
                is rightwards in English and leftwards in Arabic. */}
            <ArrowRight className="size-3.5 shrink-0 text-[var(--color-ink-subtle)] rtl:rotate-180" aria-hidden />
            <span className="t-small shrink-0 font-medium">{t(`sheets.field.${field}`)}</span>
          </li>
        ))}
      </ul>
    </Modal>
  )
}
