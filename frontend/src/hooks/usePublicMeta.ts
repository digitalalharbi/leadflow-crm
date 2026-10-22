import { useEffect } from 'react'

/**
 * The tab title, description and indexing rule for a public page, put back when it is left.
 *
 * The app as a whole is not for search engines (`noindex`); the marketing and legal pages are. They say so
 * here, for as long as one of them is on screen, and the app's own rule returns when the visitor goes on.
 */
export function usePublicMeta(title: string, description: string): void {
  useEffect(() => {
    const previousTitle = document.title
    const robots = document.querySelector<HTMLMetaElement>("meta[name='robots']")
    const describe = document.querySelector<HTMLMetaElement>("meta[name='description']")
    const before = { robots: robots?.getAttribute('content') ?? null, describe: describe?.getAttribute('content') ?? null }

    document.title = title
    robots?.setAttribute('content', 'index, follow')
    describe?.setAttribute('content', description)

    return () => {
      document.title = previousTitle
      if (before.robots !== null) robots?.setAttribute('content', before.robots)
      if (before.describe !== null) describe?.setAttribute('content', before.describe)
    }
  }, [title, description])
}
