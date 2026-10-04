import type { ReactNode } from 'react'

export function PageHeader({
  title,
  subtitle,
  actions,
}: {
  title: string
  subtitle?: string
  actions?: ReactNode
}) {
  return (
    <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        {/*
          `t-page-title` rather than a size plus `tracking-tight`: the class is
          what zeroes letter-spacing in RTL, and tightening Arabic crowds the
          cursive joins. Every page's title was carrying -0.55px of it.
        */}
        <h1 className="t-page-title" data-testid="page-title">{title}</h1>
        {subtitle && <p className="t-caption mt-1">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
  )
}
