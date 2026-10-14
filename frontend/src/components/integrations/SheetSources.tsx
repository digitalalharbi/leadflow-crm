import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, RefreshCw, Sheet, Trash2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { formatLastSeen } from '@/lib/format'
import {
  Badge, Button, Card, CardBody, CardHeader, Input, Modal, Select, Skeleton,
} from '@/components/ui'
import {
  connectSheet, disconnectSheet, fetchSheets, fetchSpreadsheets, fetchTabs,
  previewSheet, syncSheet, type SheetSource,
} from '@/features/sheets'

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
            <Button size="sm" variant="outline" onClick={() => setOpen(true)} data-testid="connect-sheet">
              {t('sheets.connect')}
            </Button>
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

      {open && <ConnectSheet onClose={() => setOpen(false)} onConnected={() => { setOpen(false); refresh() }} />}
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

/** Pick a spreadsheet, pick a tab, map the columns, look before you import. */
function ConnectSheet({ onClose, onConnected }: { onClose: () => void; onConnected: () => void }) {
  const { t } = useTranslation()
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [search, setSearch] = useState('')
  const [spreadsheet, setSpreadsheet] = useState<{ id: string; name: string } | null>(null)
  const [tab, setTab] = useState('')
  const [mapping, setMapping] = useState<Record<string, string>>({})

  const { data: files } = useQuery({
    queryKey: ['sheets-files', search],
    queryFn: () => fetchSpreadsheets(search),
    retry: false,
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
        mapping,
      }),
    onSuccess: () => {
      toast(t('sheets.connected'), 'success')
      onConnected()
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  // A sheet with no way to contact anybody is not a lead source.
  const ready = Boolean(spreadsheet && tab && (mapping.phone || mapping.email))

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
        <Input
          label={t('sheets.spreadsheet')}
          value={search}
          data-testid="sheet-search"
          onChange={(event) => setSearch(event.target.value)}
        />

        <ul className="max-h-40 space-y-1 overflow-y-auto" data-testid="spreadsheet-choices">
          {files?.spreadsheets.map((file) => (
            <li key={file.id}>
              <button
                type="button"
                aria-pressed={spreadsheet?.id === file.id}
                onClick={() => { setSpreadsheet({ id: file.id, name: file.name }); setTab(''); }}
                className="t-small w-full text-start hover:underline"
              >
                {file.name}
              </button>
            </li>
          ))}
        </ul>

        {spreadsheet && (
          <Select label={t('sheets.tab')} value={tab} data-testid="sheet-tab" onChange={(e) => setTab(e.target.value)}>
            <option value="">—</option>
            {tabs?.tabs.map((name) => <option key={name} value={name}>{name}</option>)}
          </Select>
        )}

        {preview && (
          <>
            <div className="grid gap-3 sm:grid-cols-2">
              {['full_name', 'phone', 'email', 'city', 'project', 'campaign_name'].map((field) => (
                <Select
                  key={field}
                  label={t(`sheets.field.${field}`)}
                  value={mapping[field] ?? ''}
                  data-testid={`map-${field}`}
                  onChange={(event) => setMapping((prev) => ({ ...prev, [field]: event.target.value }))}
                >
                  <option value="">—</option>
                  {preview.headers.map((header) => <option key={header} value={header}>{header}</option>)}
                </Select>
              ))}
            </div>

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
  onConnect,
  onImportFile,
}: {
  reason: string
  canConnect: boolean
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
        <Button
          size="sm"
          disabled={!canConnect}
          title={canConnect ? undefined : reason}
          onClick={onConnect}
          data-testid="empty-connect-sheet"
        >
          {t('sheets.connect')}
        </Button>

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
