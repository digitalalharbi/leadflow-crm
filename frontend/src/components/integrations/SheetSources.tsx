import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, Sheet, Trash2 } from 'lucide-react'
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
 */
export function SheetSources() {
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

  return (
    <Card>
      <CardHeader
        title={t('sheets.title')}
        description={t('sheets.hint')}
        action={
          connection?.connected ? (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)} data-testid="connect-sheet">
              {t('sheets.connect')}
            </Button>
          ) : undefined
        }
      />
      <CardBody>
        {!connection?.connected && (
          <p className="t-caption" data-testid="sheets-not-connected">
            {/*
              Two different problems with one symptom, kept apart: the platform
              has no OAuth client (ours), or this workspace has not completed
              the grant (theirs). Showing the first as a customer error is how
              support tickets get opened against the wrong team.
            */}
            {connection?.oauth_configured ? t('sheets.notConnected') : t('sheets.oauthMissing')}
          </p>
        )}

        {connection?.connected && (data?.sources ?? []).length === 0 && (
          <p className="t-caption">{t('sheets.none')}</p>
        )}

        <ul className="space-y-3" data-testid="sheet-sources">
          {data?.sources.map((source) => (
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
