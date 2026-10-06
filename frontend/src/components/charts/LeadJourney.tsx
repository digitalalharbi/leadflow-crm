import { useTranslation } from 'react-i18next'
import { Check, X } from 'lucide-react'
import { formatDateTime } from '@/lib/format'
import { humaniseDuration } from './duration'
import { SERIES } from './palette'
import { cn } from '@/lib/cn'

/** Only the fields the journey is made of — so a test can state one. */
export interface JourneyLead {
  created_at: string
  first_contact_at: string | null
  qualified_at: string | null
  won_at: string | null
  lost_at: string | null
}

/**
 * `closed` is the fourth milestone of a lead that is still open.
 *
 * Deriving it from `won_at ?? lost_at` and falling back to "lost" would print
 * فقد — lost — under every live lead in the workspace, greyed out but spelled
 * out, as the named thing it is heading towards. An unfinished journey ends at
 * "not closed yet", which is the truth and is also not an accusation.
 */
export type MilestoneKey = 'arrived' | 'firstContact' | 'qualified' | 'won' | 'lost' | 'closed'

export interface Milestone {
  key: MilestoneKey
  at: string | null
  /** Seconds since the previous reached milestone. Null at the first one. */
  sincePrevious: number | null
}

export interface Journey {
  milestones: Milestone[]
  /** Seconds the lead has sat at its latest milestone, if it is still open. */
  waitingSeconds: number | null
  totalSeconds: number
}

/**
 * The same four milestones the velocity chart measures across the workspace,
 * for one lead: arrived, first contact, qualified, closed.
 *
 * They are deliberately the same four. A manager who reads "the median lead
 * waits twelve minutes for a first call" and a rep who opens a lead that waited
 * three days should be looking at the same spine, or the two screens are
 * describing different companies.
 *
 * `now` is a parameter rather than a call to `Date.now()` so that the waiting
 * time is testable at all. A function that reads the clock itself can only be
 * tested by mocking the clock.
 */
export function buildJourney(lead: JourneyLead, now: Date): Journey {
  const closedAt = lead.won_at ?? lead.lost_at ?? null
  const closedKey: MilestoneKey = lead.won_at ? 'won' : lead.lost_at ? 'lost' : 'closed'

  const steps: { key: MilestoneKey; at: string | null }[] = [
    { key: 'arrived', at: lead.created_at },
    { key: 'firstContact', at: lead.first_contact_at },
    { key: 'qualified', at: lead.qualified_at },
    { key: closedKey, at: closedAt },
  ]

  let previous: number | null = null
  const milestones = steps.map(({ key, at }) => {
    const time = at ? Date.parse(at) : null

    /*
     * A gap is only measured between two milestones that both happened, and
     * only when the clock ran forwards. Timestamps can be written out of order
     * — a rep marks a lead qualified, then backdates the first call — and a
     * negative gap drawn as a bar is worse than no bar: it is a bar pointing
     * the wrong way with a confident number beside it.
     */
    const since =
      time !== null && previous !== null && time >= previous ? (time - previous) / 1000 : null

    if (time !== null) previous = time

    return { key, at, sincePrevious: since }
  })

  const start = Date.parse(lead.created_at)
  const last = previous ?? start
  const open = closedAt === null
  const waiting = open ? Math.max(0, (now.getTime() - last) / 1000) : null
  const total = Math.max(0, ((open ? now.getTime() : last) - start) / 1000)

  return { milestones, waitingSeconds: waiting, totalSeconds: total }
}

/**
 * Where this lead's time actually went.
 *
 * A status badge says where a lead is. It does not say that it has been sitting
 * there since Tuesday, which is the only part anybody can act on this
 * afternoon — so the waiting time is the one figure here that is stated twice.
 *
 * Vertical, not a horizontal stepper: the labels are Arabic phrases and the
 * dates are long, and a horizontal strip either truncates them or rotates them.
 * It also means the layout does not change at all between a phone and a desk.
 */
export function LeadJourney({ lead, now = new Date() }: { lead: JourneyLead; now?: Date }) {
  const { t, i18n } = useTranslation()
  const journey = buildJourney(lead, now)

  const reached = journey.milestones.filter((m) => m.at !== null)

  /*
   * The whole journey is TRACK pixels tall, and each step takes its share of
   * them. MIN keeps a step that took seconds from vanishing into a hairline —
   * which means the shortest steps are drawn slightly long, so the duration is
   * also written next to every one of them in words. The bar is for the shape;
   * the text is for the value.
   */
  const TRACK = 150
  const MIN = 14
  const height = (seconds: number) =>
    journey.totalSeconds > 0
      ? Math.min(Math.max((seconds / journey.totalSeconds) * TRACK, MIN), TRACK)
      : MIN

  return (
    <ol className="space-y-0 px-5 py-4" data-testid="lead-journey">
      {journey.milestones.map((milestone, index) => {
        const done = milestone.at !== null
        const isLastReached = done && reached.at(-1)?.key === milestone.key
        const outcome = milestone.key === 'won' || milestone.key === 'lost'

        return (
          <li key={milestone.key} className="grid grid-cols-[auto_1fr] gap-x-3">
            <div className="flex flex-col items-center">
              <Node milestone={milestone} done={done} outcome={outcome} />

              {/*
                The connector carries the gap. It is the only part of this
                component that encodes a magnitude, so it is the only part
                drawn to scale: its height is this step's share of the whole
                journey, and the shares therefore add up to the elapsed time
                rather than to nothing in particular.
              */}
              {index < journey.milestones.length - 1 && (
                <Connector
                  seconds={journey.milestones[index + 1]?.sincePrevious ?? null}
                  height={height}
                  pending={isLastReached && journey.waitingSeconds !== null}
                  waitingSeconds={journey.waitingSeconds}
                />
              )}
            </div>

            {/*
              An unreached milestone is set back by weight and colour, never by
              opacity. `opacity-60` took the caption under it from 4.97:1 to
              2.36:1 against the light surface — below the 3:1 floor, let alone
              4.5:1 — and the text it was fading is "لم يحدث بعد", which is the
              only thing that row has to say.
            */}
            <div className="min-w-0 pb-6">
              <p
                className={cn(
                  't-small font-medium',
                  !done && 'font-normal text-[var(--color-ink-muted)]',
                )}
              >
                {t(`charts.journey.${milestone.key}`)}
              </p>

              {done ? (
                <>
                  {/*
                    No dir="ltr" here. The string is "6 أكتوبر 2026 · 21:57" —
                    mostly Arabic words — and forcing an LTR paragraph makes the
                    bidi algorithm reorder them. Every other date on this page
                    is rendered without it; this one was the odd one out.
                  */}
                  <p className="t-caption tabular-nums">
                    {formatDateTime(milestone.at, i18n.language)}
                  </p>
                  {milestone.sincePrevious !== null && (
                    <p className="t-caption mt-0.5">
                      {t('charts.journey.after', {
                        duration: humaniseDuration(milestone.sincePrevious, t),
                      })}
                    </p>
                  )}
                </>
              ) : (
                <p className="t-caption">
                  {isLastReachedBefore(journey, milestone) && journey.waitingSeconds !== null
                    ? t('charts.journey.waitingHere', {
                        duration: humaniseDuration(journey.waitingSeconds, t),
                      })
                    : t('charts.journey.notYet')}
                </p>
              )}
            </div>
          </li>
        )
      })}
    </ol>
  )
}

/** True for the first milestone the lead has not reached — where it is stuck. */
function isLastReachedBefore(journey: Journey, milestone: Milestone): boolean {
  const next = journey.milestones.find((m) => m.at === null)

  return next?.key === milestone.key
}

function Node({
  milestone,
  done,
  outcome,
}: {
  milestone: Milestone
  done: boolean
  outcome: boolean
}) {
  if (!done) {
    /*
      A hollow ring, not a filled dot: the shape says "nothing here yet"
      without the colour having to be read. It was dashed at first, which at
      fourteen pixels does not read as a dashed circle at all — it reads as a
      cog, and a settings icon in the middle of a timeline is a worse problem
      than the one the dashes were solving.
    */
    return (
      <span
        className="size-3.5 shrink-0 rounded-full border-2 border-[var(--color-border-strong)]"
        aria-hidden
      />
    )
  }

  if (outcome) {
    const won = milestone.key === 'won'

    return (
      <span
        data-chart-mark
        className="grid size-5 shrink-0 place-items-center rounded-full text-white"
        style={{ background: won ? 'var(--color-success)' : 'var(--color-danger)' }}
        aria-hidden
      >
        {/* The icon, not the colour, is what carries won versus lost. */}
        {won ? <Check className="size-3" strokeWidth={3} /> : <X className="size-3" strokeWidth={3} />}
      </span>
    )
  }

  return (
    <span
      data-chart-mark
      className="size-3.5 shrink-0 rounded-full ring-2 ring-[var(--color-surface)]"
      style={{ background: SERIES[0] }}
      aria-hidden
    />
  )
}

function Connector({
  seconds,
  height,
  pending,
  waitingSeconds,
}: {
  seconds: number | null
  height: (seconds: number) => number
  pending: boolean
  waitingSeconds: number | null
}) {
  const measured = seconds !== null
  const px = measured ? height(seconds) : pending ? height(waitingSeconds ?? 0) : 14

  return (
    <span
      className="my-1 block w-0.5 flex-1 rounded-full bg-[var(--color-line)]"
      style={{ minHeight: `${px}px` }}
      aria-hidden
    >
      {measured ? (
        <span data-chart-mark className="block h-full w-full rounded-full" style={{ background: SERIES[0] }} />
      ) : pending ? (
        /*
          Striped, because this stretch is still running. A solid bar would
          claim a finished duration, and the reader cannot tell a measured gap
          from a clock that is still ticking by length alone.
        */
        <span
          data-chart-mark
          className="block h-full w-full rounded-full"
          style={{
            background: `repeating-linear-gradient(135deg, var(--color-border-strong) 0 2px, transparent 2px 5px)`,
          }}
        />
      ) : null}
    </span>
  )
}
