import { createContext, useContext, type ReactNode } from 'react'
import { createPortal } from 'react-dom'

/**
 * Where the page title lives.
 *
 * In the reference the title row spans the whole frame, above both the icon
 * rail and the cards — it is not part of the content column. Every page renders
 * its own `PageHeader` as a child of that column, so the shell offers a slot
 * above the rail and the header portals into it: pages keep writing
 * `<PageHeader …/>` where they always did, and the frame still has the shape
 * the design asks for.
 *
 * Without a slot — a unit test, the login screen — it renders in place.
 */
const SlotContext = createContext<HTMLElement | null>(null)

export const PageHeaderSlotProvider = SlotContext.Provider

export function PageHeader({
  title,
  titleMuted,
  subtitle,
  actions,
}: {
  title: string
  /** The second half of a greeting, in the quieter ink: "مرحبًا بعودتك، " + "مدير". */
  titleMuted?: string
  subtitle?: string
  actions?: ReactNode
}) {
  const slot = useContext(SlotContext)

  const header = (
    // A div, not a <header>: this lands beside the shell's own banner, and two
    // banner landmarks on one page is two answers to "where is the site header".
    <div className="flex flex-wrap items-center justify-between gap-3 px-1 lg:px-2" data-testid="page-header">
      <div className="min-w-0">
        {/*
          `t-page-title` rather than a size plus `tracking-tight`: the class is
          what zeroes letter-spacing in RTL, and tightening Arabic crowds the
          cursive joins.
        */}
        <h1 className="t-page-title" data-testid="page-title">
          {title}
          {titleMuted && <span className="font-normal text-[var(--color-ink-muted)]"> {titleMuted}</span>}
        </h1>
        {subtitle && (
          <p className="t-caption mt-0.5" data-testid="page-subtitle">
            {subtitle}
          </p>
        )}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )

  return slot ? createPortal(header, slot) : header
}
