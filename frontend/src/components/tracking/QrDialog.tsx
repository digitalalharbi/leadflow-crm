import { useQuery } from '@tanstack/react-query'
import { Download, Printer } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Skeleton } from '@/components/ui'
import { fetchLinkQr, type TrackingLink } from '@/features/tracking'

/**
 * The link as a code to print: an SVG, so it stays sharp on a flyer and on a billboard alike.
 * What it encodes is the short link, so editing the destination later never invalidates a printed one.
 */
export function QrDialog({ link, onClose }: { link: TrackingLink | null; onClose: () => void }) {
  const { t } = useTranslation()
  const { data: svg } = useQuery({ queryKey: ['tracking-qr', link?.id], queryFn: () => fetchLinkQr(link!.id), enabled: link !== null })

  const href = svg ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}` : undefined

  const print = () => {
    if (!svg) return
    const w = window.open('', '_blank', 'width=480,height=620')
    if (!w) return
    w.document.write(`<html><head><title>${link?.name ?? ''}</title></head><body style="font-family:sans-serif;text-align:center;margin:24px"><h2>${link?.name ?? ''}</h2>${svg}<p dir="ltr" style="color:#555">${link?.url ?? ''}</p></body></html>`)
    w.document.close()
    w.focus()
    w.print()
  }

  return (
    <Modal
      open={link !== null}
      onClose={onClose}
      title={link ? `${t('tracking.qr.title')} — ${link.name}` : ''}
      size="sm"
      footer={
        <>
          <a
            href={href}
            download={`qr-${link?.token ?? 'link'}.svg`}
            data-testid="qr-download"
            className="inline-flex items-center gap-1.5 rounded-full border border-[var(--color-border)] px-4 py-2 text-[0.9375rem] font-semibold"
            aria-disabled={!svg}
          >
            <Download className="size-4" aria-hidden />{t('tracking.qr.download')}
          </a>
          <Button icon={<Printer className="size-4" />} onClick={print} disabled={!svg} data-testid="qr-print">{t('tracking.qr.print')}</Button>
        </>
      }
    >
      <div className="grid place-items-center py-2" data-testid="qr-dialog">
        {svg ? (
          <img src={href} alt={t('tracking.qr.alt', { name: link?.name ?? '' })} className="size-64 rounded-xl bg-white p-2" data-testid="qr-image" />
        ) : (
          <Skeleton className="size-64" />
        )}
        {link && <p className="t-caption mt-2 max-w-full truncate" dir="ltr">{link.url}</p>}
      </div>
    </Modal>
  )
}
