import { cn } from '@/lib/cn'

/**
 * The official مهتم logo — the one place the product draws it.
 *
 * The files are the brand kit's own SVGs, unmodified (a test fails the build if one ever differs
 * from `brand/Mohtam-Brand-Kit`). Nothing here redraws the mark, sets the name as text, or
 * re-proportions anything: the component only chooses which official file to show, and how large.
 *
 *  - variant `primary`  — light surfaces (emerald ring, pine name, copper dot)
 *  - variant `reverse`  — pine / dark surfaces (limestone ring and name, light copper dot)
 *  - variant `on-green` — emerald surfaces (white, light copper dot)
 *  - variant `mono`     — one colour, black; `mono-white` is the same in white, over photographs
 *  - variant `auto`     — `primary` in the light theme and `reverse` in the dark one, for a surface that changes with it
 *
 *  - layout `horizontal` — mark and name side by side; the default, 96px wide at the least
 *  - layout `stacked`    — mark over name
 *  - layout `mark`       — the ring and dot alone, 20px at the least; a collapsed sidebar, an app icon
 */
export type LogoVariant = 'primary' | 'reverse' | 'on-green' | 'mono' | 'mono-white' | 'auto'
export type LogoLayout = 'horizontal' | 'stacked' | 'mark'

const files = import.meta.glob('/src/assets/brand/logo/*/*.svg', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

/** Variant → the kit's folder name. */
const FOLDER: Record<Exclude<LogoVariant, 'auto'>, string> = {
  primary: 'primary',
  reverse: 'reverse',
  'on-green': 'on-green',
  mono: 'mono-black',
  'mono-white': 'mono-white',
}

/** The smallest height each layout may be drawn at: 96px wide for the horizontal, 20px for the mark. */
const MIN_HEIGHT: Record<LogoLayout, number> = { horizontal: 44, stacked: 64, mark: 20 }
const DEFAULT_HEIGHT: Record<LogoLayout, number> = { horizontal: 44, stacked: 96, mark: 32 }

interface Parsed {
  inner: string
  ratio: number
}

const cache = new Map<string, Parsed>()

function parse(variant: Exclude<LogoVariant, 'auto'>, layout: LogoLayout): Parsed {
  const key = `${variant}/${layout}`
  const hit = cache.get(key)

  if (hit) return hit

  const folder = FOLDER[variant]
  const raw = files[`/src/assets/brand/logo/${folder}/mohtam-${layout}-${folder}.svg`]

  if (!raw) throw new Error(`There is no official logo for ${key}`)

  const box = /viewBox="0 0 ([\d.]+) ([\d.]+)"/.exec(raw)
  const ratio = box ? Number(box[1]) / Number(box[2]) : 1
  // The file's fixed pixel size goes; the wrapper sizes it. The drawing itself is untouched.
  const inner = raw.replace(/ width="[^"]*" height="[^"]*"/, ' width="100%" height="100%" focusable="false" aria-hidden="true"')
  const parsed = { inner, ratio }

  cache.set(key, parsed)

  return parsed
}

function Drawing({ variant, layout, px, className }: { variant: Exclude<LogoVariant, 'auto'>; layout: LogoLayout; px: number; className?: string }) {
  const { inner, ratio } = parse(variant, layout)

  return (
    <span className={cn('inline-block shrink-0 leading-none', className)} style={{ height: px, width: px * ratio }} data-variant={variant}>
      <span aria-hidden className="block size-full [&>svg]:block [&>svg]:size-full" dangerouslySetInnerHTML={{ __html: inner }} />
    </span>
  )
}

export function Logo({
  variant = 'primary',
  layout = 'horizontal',
  height,
  className,
}: {
  variant?: LogoVariant
  layout?: LogoLayout
  /** Height in pixels; never drawn below the kit's minimum for the layout. */
  height?: number
  className?: string
}) {
  const px = Math.max(height ?? DEFAULT_HEIGHT[layout], MIN_HEIGHT[layout])

  return (
    <span role="img" aria-label="مهتم" data-testid="logo" data-variant={variant} data-layout={layout} className={cn('inline-flex shrink-0 leading-none', className)}>
      {/* The name for anything that reads text rather than looks at the drawing. */}
      <span className="sr-only">مهتم</span>
      {variant === 'auto' ? (
        <>
          <Drawing variant="primary" layout={layout} px={px} className="dark:hidden" />
          <Drawing variant="reverse" layout={layout} px={px} className="hidden dark:inline-block" />
        </>
      ) : (
        <Drawing variant={variant} layout={layout} px={px} />
      )}
    </span>
  )
}
