/**
 * A line with no axes: the shape of the last two weeks, and nothing else.
 *
 * For a card, where the question is "is this going up or has it gone quiet" and
 * a number alone cannot say. No scale, no labels — a chart a reader has to read
 * is not a sparkline — and a flat line is drawn flat and drawn faint, because a
 * project that stopped receiving leads should look like it.
 */
export function MiniTrend({
  values,
  color = 'var(--brand)',
  label,
}: {
  values: number[]
  color?: string
  /** Read out instead of the shape, for anybody who cannot see it. */
  label?: string
}) {
  const width = 96
  const height = 28
  const pad = 2
  const max = Math.max(...values, 1)
  const flat = values.every((v) => v === 0)

  const points = values.map((value, index) => {
    const x = pad + (index / Math.max(values.length - 1, 1)) * (width - pad * 2)
    const y = height - pad - (value / max) * (height - pad * 2)
    return [x, y] as const
  })

  const line = points.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  const area = `${line} L${points.at(-1)?.[0].toFixed(1)},${height} L${points[0]?.[0].toFixed(1)},${height} Z`

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      width={width}
      height={height}
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
      className={flat ? 'opacity-40' : undefined}
      data-testid="mini-trend"
    >
      <path d={area} fill={color} opacity={0.12} />
      <path d={line} fill="none" stroke={color} strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}
