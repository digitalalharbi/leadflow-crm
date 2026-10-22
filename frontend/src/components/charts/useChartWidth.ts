import { useEffect, useRef, useState } from 'react'

/**
 * The plot's width in real pixels, so one SVG unit is one pixel.
 *
 * A fixed `viewBox` scaled to fit looks fine on a desktop and quietly ruins the
 * chart on a phone: a 720-unit box drawn 325px wide scales everything by 0.45,
 * so a 9px axis label renders at four pixels. The lines still look right, which
 * is why it survives review — nobody checks whether they can read the numbers.
 *
 * Measuring instead means text is the size it says it is at every width, and a
 * narrow chart gets fewer pixels rather than smaller type.
 */
export function useChartWidth(fallback = 720) {
  const ref = useRef<HTMLDivElement | null>(null)
  const [width, setWidth] = useState(fallback)

  useEffect(() => {
    const element = ref.current

    if (!element) return

    // ResizeObserver rather than a window listener: the chart also changes
    // width when a sidebar opens, which no resize event reports.
    const observer = new ResizeObserver(([entry]) => {
      const next = Math.round(entry.contentRect.width)

      if (next > 0) setWidth(next)
    })

    observer.observe(element)

    return () => observer.disconnect()
  }, [])

  return { ref, width }
}
