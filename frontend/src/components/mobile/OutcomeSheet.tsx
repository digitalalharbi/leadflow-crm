import { useEffect, useState } from 'react'
import { WifiOff } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import type { Outcome } from '@/features/work'
import { Button, Modal, Textarea } from '@/components/ui'
import { cn } from '@/lib/cn'
import { defaultExtras, extrasComplete, OutcomeDetails, type OutcomeExtras } from '@/components/work/OutcomeDetails'

/**
 * The choices in the order a rep thinks of them after a call: reached them or not, how they felt, what was
 * agreed, then the endings. (The laptop's list keeps its own order, and its keyboard numbers.)
 */
export const SHEET_OUTCOMES: Outcome[] = ['contacted', 'no_answer', 'interested', 'not_interested', 'meeting_booked', 'call_later', 'reservation', 'won', 'lost']

/**
 * What came of it, in one tap, from the bottom of the screen.
 *
 * Nine outcomes as large targets, an
 * optional note, and one button that saves and moves to the next lead. Saving is refused while a save is
 * in flight and while there is no connection — in words, here, rather than queued to be sent behind the
 * rep's back — so an outcome can never be recorded twice.
 */
export function OutcomeSheet({
  open,
  onClose,
  onSave,
  pending,
  online,
  hasNext = true,
}: {
  open: boolean
  onClose: () => void
  onSave: (choice: Outcome, note: string, extras: OutcomeExtras) => boolean
  pending: boolean
  online: boolean
  /** When there is no lead after this one the button says only "save". */
  hasNext?: boolean
}) {
  const { t } = useTranslation()
  const [choice, setChoice] = useState<Outcome | null>(null)
  const [note, setNote] = useState('')
  const [extras, setExtras] = useState<OutcomeExtras>({})

  // A sheet opened again is a fresh one, whichever way the last was left.
  useEffect(() => {
    if (!open) {
      setChoice(null)
      setNote('')
      setExtras({})
    }
  }, [open])

  const close = () => {
    if (pending) return
    setChoice(null)
    setNote('')
    onClose()
  }

  // A sale without its value, a loss without its reason, a visit without its time: not saved half-told.
  const blocked = pending || !online || choice === null || !extrasComplete(choice, extras)

  return (
    <Modal
      open={open}
      onClose={close}
      title={t('mobileSales.outcome.title')}
      description={t('mobileSales.outcome.hint')}
      footer={
        <Button
          size="lg"
          className="w-full"
          loading={pending}
          disabled={blocked}
          data-testid="outcome-save"
          onClick={() => {
            if (choice) onSave(choice, note, extras)
          }}
        >
          {pending ? t('mobileSales.outcome.saving') : hasNext ? t('mobileSales.outcome.save') : t('mobileSales.outcome.saveOnly')}
        </Button>
      }
    >
      <div className="space-y-3" data-testid="outcome-sheet">
        {!online && (
          <p role="alert" className="notice notice-warning flex items-start gap-2" data-testid="outcome-offline">
            <WifiOff className="mt-0.5 size-4 shrink-0" aria-hidden />
            {t('mobileSales.outcome.offline')}
          </p>
        )}

        <div role="radiogroup" aria-label={t('mobileSales.outcome.title')} className="grid grid-cols-2 gap-2">
          {SHEET_OUTCOMES.map((value) => (
            <button
              key={value}
              type="button"
              role="radio"
              aria-checked={choice === value}
              data-testid={`outcome-${value}`}
              onClick={() => {
                setChoice(value)
                setExtras(defaultExtras(value))
              }}
              className={cn(
                'pressable min-h-14 rounded-2xl px-3 py-2 text-[1rem] font-semibold',
                choice === value
                  ? 'bg-[var(--brand)] text-[var(--brand-contrast)]'
                  : 'bg-[var(--color-surface-muted)] text-[var(--color-ink)] ring-1 ring-inset ring-[var(--color-line)]',
              )}
            >
              {t(`work.outcome.${value}`)}
            </button>
          ))}
        </div>

        <OutcomeDetails choice={choice} value={extras} onChange={setExtras} />

        <Textarea
          label={t('mobileSales.outcome.note')}
          rows={2}
          value={note}
          data-testid="outcome-note"
          onChange={(e) => setNote(e.target.value)}
        />
      </div>
    </Modal>
  )
}
