import { useMemo } from 'react'

/**
 * The visual on the login screen.
 *
 * It draws the product's claim rather than decorating around it: scattered
 * points are interests arriving, faint lines are the paths they travel, and a
 * few of them resolve into larger, brighter points — qualified opportunities.
 * Most do not, which is the honest shape of a sales funnel and the reason the
 * product exists.
 *
 * Deterministic, not random: a layout that reshuffles on every page load reads
 * as a screensaver. The seed is fixed so this is a composition someone chose.
 *
 * It takes its colour from `currentColor`, which is what lets one drawing work
 * on both sides of the theme: brand ink on a pale panel in light mode, white on
 * the saturated gradient in dark. The opacities below are tuned for the first
 * case, because that is the one a tinted mark has to survive — white at a
 * quarter opacity over a light brand colour is the version nobody could see.
 */
export function BrandField({ className }: { className?: string }) {
  const { nodes, links } = useMemo(buildField, [])

  return (
    <svg
      viewBox="0 0 400 560"
      preserveAspectRatio="xMidYMid slice"
      className={className}
      aria-hidden
    >
      {/* Paths first, so the points sit on top of them. */}
      {links.map((link, i) => (
        <line
          key={`l-${i}`}
          x1={link.x1}
          y1={link.y1}
          x2={link.x2}
          y2={link.y2}
          stroke="currentColor"
          strokeWidth="1"
          opacity={link.opacity}
          className="brand-field-link"
          style={{ animationDelay: `${link.delay}ms` }}
        />
      ))}

      {/*
        The points that made it to the end carry a ring. It is the one piece of
        emphasis in the picture, and it earns its place: without it the final
        column is just two slightly bigger dots, and the drawing stops saying
        that something was decided.
      */}
      {nodes
        .filter((node) => node.resolved)
        .map((node, i) => (
          <circle
            key={`h-${i}`}
            cx={node.x}
            cy={node.y}
            r={node.r + 6}
            fill="none"
            stroke="currentColor"
            strokeWidth="0.75"
            opacity={0.32 * legible(node.y)}
            className="brand-field-node"
            style={{ animationDelay: `${node.delay + 180}ms` }}
          />
        ))}

      {nodes.map((node, i) => (
        <circle
          key={`n-${i}`}
          cx={node.x}
          cy={node.y}
          r={node.r}
          fill="currentColor"
          opacity={node.opacity}
          className="brand-field-node"
          style={{ animationDelay: `${node.delay}ms` }}
        />
      ))}
    </svg>
  )
}

/**
 * How much of a mark survives at a given height.
 *
 * The drawing has to stop before it reaches the tagline printed over its lower
 * third. The falloff is part of the composition rather than a mask laid over
 * the finished picture, so the whole drawing — where the points are, how bright
 * they are, where it ends — is decided in this one function pair and nowhere
 * else.
 */
function legible(y: number) {
  const fade = (y - 300) / 230

  return 1 - Math.min(Math.max(fade, 0), 1) * 0.82
}

/**
 * A small deterministic generator — a seeded LCG rather than Math.random, so
 * the composition is the same every time anyone looks at it.
 */
function buildField() {
  let seed = 20_260_401
  const rand = () => {
    seed = (seed * 1_664_525 + 1_013_904_223) % 4_294_967_296

    return seed / 4_294_967_296
  }

  const nodes: Array<{
    x: number
    y: number
    r: number
    opacity: number
    delay: number
    resolved: boolean
  }> = []
  const links: Array<{
    x1: number
    y1: number
    x2: number
    y2: number
    opacity: number
    delay: number
  }> = []

  // Five columns, thinning as they advance — the funnel, drawn as density
  // rather than as a shape with "funnel" written on it.
  const columns = [14, 10, 7, 4, 2]
  const points: Array<Array<{ x: number; y: number }>> = []

  columns.forEach((count, column) => {
    const x = 56 + column * 72
    const spread = 440 - column * 48
    const top = (560 - spread) / 2
    const layer: Array<{ x: number; y: number }> = []

    for (let i = 0; i < count; i++) {
      const y = top + (spread / Math.max(count - 1, 1)) * i + (rand() - 0.5) * 18
      layer.push({ x: x + (rand() - 0.5) * 16, y })
    }

    points.push(layer)
  })

  points.forEach((layer, column) => {
    const advanced = column / (points.length - 1)

    layer.forEach((point, i) => {
      nodes.push({
        x: point.x,
        y: point.y,
        // Points grow and brighten as they advance: a qualified lead is worth
        // more than a new one, and the picture should say so.
        r: 1.8 + advanced * 3.4,
        opacity: (0.32 + advanced * 0.5) * legible(point.y),
        delay: column * 90 + i * 24,
        resolved: column === points.length - 1,
      })

      // Each point links forward to its nearest neighbour in the next column.
      const next = points[column + 1]

      if (next) {
        const target = next.reduce((closest, candidate) =>
          Math.abs(candidate.y - point.y) < Math.abs(closest.y - point.y) ? candidate : closest,
        )

        links.push({
          x1: point.x,
          y1: point.y,
          x2: target.x,
          y2: target.y,
          opacity: (0.16 + advanced * 0.22) * legible((point.y + target.y) / 2),
          delay: column * 90 + i * 24,
        })
      }
    })
  })

  return { nodes, links }
}
