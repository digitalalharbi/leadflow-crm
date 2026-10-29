import { useEffect } from 'react'
import { PUBLIC_ORIGIN } from '@/lib/routes'

/** The sharing image: the product's own mark at a size social previews accept. */
const SHARE_IMAGE = `${PUBLIC_ORIGIN}/brand/social-preview.png`

function upsert(selector: string, create: () => HTMLElement, set: (el: HTMLElement) => void): () => void {
  let el = document.head.querySelector<HTMLElement>(selector)
  const created = el === null

  if (!el) {
    el = create()
    document.head.appendChild(el)
  }

  const snapshot = el.cloneNode(true) as HTMLElement
  set(el)

  return () => {
    if (created) el?.remove()
    else if (el) {
      for (const attr of Array.from(el.attributes)) el.removeAttribute(attr.name)
      for (const attr of Array.from(snapshot.attributes)) el.setAttribute(attr.name, attr.value)
    }
  }
}

const meta = (attr: 'name' | 'property', key: string) => () => {
  const m = document.createElement('meta')
  m.setAttribute(attr, key)

  return m
}

/**
 * The tab title, description, indexing rule and sharing metadata for a public page, put back when it is left.
 *
 * The app as a whole is not for search engines (`noindex`); the marketing and legal pages are. They say so here, for as long
 * as one of them is on screen — with the one canonical address the page lives at, and Open Graph for link previews.
 */
export function usePublicMeta(title: string, description: string, path = '/', lang: 'ar' | 'en' = 'ar'): void {
  useEffect(() => {
    const previousTitle = document.title
    const url = `${PUBLIC_ORIGIN}${path === '/' ? '/' : path}`
    const undo = [
      upsert("meta[name='robots']", meta('name', 'robots'), (el) => el.setAttribute('content', 'index, follow')),
      upsert("meta[name='description']", meta('name', 'description'), (el) => el.setAttribute('content', description)),
      upsert("link[rel='canonical']", () => Object.assign(document.createElement('link'), { rel: 'canonical' }), (el) => el.setAttribute('href', url)),
      upsert("meta[property='og:title']", meta('property', 'og:title'), (el) => el.setAttribute('content', title)),
      upsert("meta[property='og:description']", meta('property', 'og:description'), (el) => el.setAttribute('content', description)),
      upsert("meta[property='og:url']", meta('property', 'og:url'), (el) => el.setAttribute('content', url)),
      upsert("meta[property='og:type']", meta('property', 'og:type'), (el) => el.setAttribute('content', 'website')),
      upsert("meta[property='og:site_name']", meta('property', 'og:site_name'), (el) => el.setAttribute('content', lang === 'en' ? 'Mohtam' : 'مهتم')),
      upsert("meta[property='og:locale']", meta('property', 'og:locale'), (el) => el.setAttribute('content', lang === 'en' ? 'en_US' : 'ar_SA')),
      upsert("meta[property='og:image']", meta('property', 'og:image'), (el) => el.setAttribute('content', SHARE_IMAGE)),
      upsert("meta[property='og:image:width']", meta('property', 'og:image:width'), (el) => el.setAttribute('content', '1200')),
      upsert("meta[property='og:image:height']", meta('property', 'og:image:height'), (el) => el.setAttribute('content', '630')),
      upsert("meta[property='og:image:alt']", meta('property', 'og:image:alt'), (el) => el.setAttribute('content', title)),
      upsert("meta[name='twitter:card']", meta('name', 'twitter:card'), (el) => el.setAttribute('content', 'summary_large_image')),
      upsert("meta[name='twitter:title']", meta('name', 'twitter:title'), (el) => el.setAttribute('content', title)),
      upsert("meta[name='twitter:description']", meta('name', 'twitter:description'), (el) => el.setAttribute('content', description)),
      upsert("meta[name='twitter:image']", meta('name', 'twitter:image'), (el) => el.setAttribute('content', SHARE_IMAGE)),
    ]

    document.title = title

    return () => {
      document.title = previousTitle
      undo.forEach((fn) => fn())
    }
  }, [title, description, path, lang])
}
