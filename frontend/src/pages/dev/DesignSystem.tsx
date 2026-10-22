import { BrandLockup, Logo, type LogoVariant } from '@/components/brand'
import { ActionCard, Donut, KpiCard, PipelineFunnel, RankBars, RingProgress, StatusPill, ProgressBar, type StatusKey } from '@/components/visual'
import { Users, Phone } from 'lucide-react'
import { Badge, Button, Card, CardBody, CardHeader, Input, Select, StatusBadge, TemperatureBadge, Textarea } from '@/components/ui'

/**
 * The design system, on one page, in the running app.
 *
 * It exists so a component can be judged against its neighbours rather than
 * against the screen it happens to live on — which is how six slightly
 * different card titles get into a product. Local only: it is not a feature,
 * and a customer finding it would be reading our notes.
 */
export function DesignSystemPage() {
  return (
    <div className="space-y-8 pb-16">
      <header>
        <h1 className="t-display">نظام التصميم</h1>
        <p className="t-small mt-1 text-[var(--color-ink-muted)]">
          مرجع داخلي — غير متاح خارج بيئة التطوير.
        </p>
      </header>

      <Section title="العلامة">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {([
            ['primary', 'bg-[var(--color-canvas)]'],
            ['reverse', 'bg-[var(--chrome-1)]'],
            ['on-green', 'bg-[var(--chrome-active)]'],
            ['mono', 'bg-[var(--color-canvas)]'],
          ] as Array<[LogoVariant, string]>).map(([variant, surface]) => (
            <div key={variant} className={`grid place-items-center gap-4 rounded-[var(--radius-card)] p-6 ${surface}`}>
              <Logo variant={variant} layout="horizontal" />
              <Logo variant={variant} layout="stacked" />
              <Logo variant={variant} layout="mark" />
              <p className="t-caption">{variant}</p>
            </div>
          ))}
        </div>

        <div className="mt-6 flex flex-wrap items-center gap-8">
          <BrandLockup size="lg" />
        </div>
      </Section>

      <Section title="الألوان">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
          {[
            ['--brand', 'Brand'],
            ['--brand-hover', 'Brand hover'],
            ['--brand-soft', 'Brand soft'],
            ['--color-ink', 'Ink'],
            ['--color-ink-muted', 'Muted'],
            ['--color-border', 'Border'],
            ['--color-success', 'Success'],
            ['--color-warning', 'Warning'],
            ['--color-danger', 'Danger'],
            ['--color-info', 'Info'],
            ['--accent', 'Copper'],
            ['--accent-text', 'Copper on light'],
            ['--chrome-1', 'Pine'],
            ['--chrome-2', 'Pine 2'],
          ].map(([token, label]) => (
            <Swatch key={token} token={token} label={label} />
          ))}
        </div>

        <p className="t-caption mt-5 mb-2">سلسلة الرسوم</p>
        <div className="grid grid-cols-5 gap-3">
          {Array.from({ length: 5 }, (_, i) => (
            <Swatch key={i} token={`--chart-${i + 1}`} label={`chart-${i + 1}`} />
          ))}
        </div>
      </Section>

      <Section title="المقاييس الطباعية">
        <div className="space-y-3">
          <p className="t-display">عرض — 2,481 عميل محتمل</p>
          <p className="t-page-title">عنوان الصفحة</p>
          <p className="t-section-title">عنوان القسم</p>
          <p className="t-card-title">عنوان البطاقة</p>
          <p className="t-body">نص أساسي — هذا هو حجم النص الذي يُقرأ في معظم الشاشات.</p>
          <p className="t-small">نص صغير للتفاصيل الثانوية.</p>
          <p className="t-caption">تعليق</p>
          <div className="flex items-baseline gap-3">
            <span className="t-metric">2,481</span>
            <span className="t-caption">عميل محتمل</span>
          </div>
        </div>
      </Section>

      <Section title="العناصر البصرية">
        <div className="grid gap-6 lg:grid-cols-3">
          <Donut
            centerValue="120"
            centerLabel="Leads"
            segments={[
              { key: 'a', label: 'Meta', value: 60, color: 'var(--brand)' },
              { key: 'b', label: 'Google', value: 40, color: 'var(--color-status-won)' },
              { key: 'c', label: 'Other', value: 20, color: 'var(--color-status-lost)' },
            ]}
          />
          <PipelineFunnel
            steps={[
              { key: 'n', label: 'New', count: 120 },
              { key: 'c', label: 'Contacted', count: 80 },
              { key: 'q', label: 'Qualified', count: 32 },
              { key: 'w', label: 'Won', count: 9 },
            ]}
          />
          <RankBars
            items={[
              { key: 'a', label: 'Meta A', value: 54, note: '41%' },
              { key: 'b', label: 'Meta B', value: 31, note: '71%' },
              { key: 'c', label: 'Google', value: 12 },
            ]}
          />
        </div>
        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          <KpiCard label="Total Leads" value="1,240" icon={Users} />
          <ActionCard icon={Phone} title="Call now" count={7} tone="bad" to="/work" />
          <div className="flex items-center gap-4">
            <RingProgress value={72} tone="good">72</RingProgress>
            <ProgressBar value={45} className="w-32" />
          </div>
        </div>
        <div className="mt-6 flex flex-wrap gap-2">
          {(['healthy', 'attention', 'risk', 'overdue', 'pending', 'completed', 'active', 'paused', 'today'] as StatusKey[]).map((k) => (
            <StatusPill key={k} status={k} />
          ))}
        </div>
      </Section>

      <Section title="الأزرار">
        <div className="flex flex-wrap items-center gap-2">
          <Button>أساسي</Button>
          <Button variant="secondary">ثانوي</Button>
          <Button variant="outline">محدد</Button>
          <Button variant="ghost">شفاف</Button>
          <Button variant="danger">خطر</Button>
          <Button loading>جارٍ</Button>
          <Button disabled>معطل</Button>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Button size="sm">صغير</Button>
          <Button size="md">متوسط</Button>
          <Button size="lg">كبير</Button>
        </div>
      </Section>

      <Section title="الشارات">
        <div className="flex flex-wrap items-center gap-2">
          {(['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'] as const).map((s) => (
            <StatusBadge key={s} status={s} />
          ))}
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {(['hot', 'warm', 'cold'] as const).map((t) => (
            <TemperatureBadge key={t} temperature={t} />
          ))}
          <Badge tone="success">نجاح</Badge>
          <Badge tone="warning">تحذير</Badge>
          <Badge tone="danger">خطأ</Badge>
          <Badge tone="info">معلومة</Badge>
          <Badge>محايد</Badge>
        </div>
      </Section>

      <Section title="الحقول">
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label="حقل نصي" placeholder="اكتب هنا" />
          <Input label="حقل به خطأ" error="هذا الحقل مطلوب" />
          <Select label="قائمة">
            <option>خيار أول</option>
            <option>خيار ثانٍ</option>
          </Select>
          <Textarea label="نص طويل" placeholder="اكتب هنا" />
        </div>
      </Section>
    </div>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} />
      <CardBody>{children}</CardBody>
    </Card>
  )
}

function Swatch({ token, label }: { token: string; label: string }) {
  return (
    <div>
      <div
        className="h-12 rounded-[var(--radius-control)] border border-[var(--color-line)]"
        style={{ background: `var(${token})` }}
      />
      <p className="t-caption mt-1.5 truncate" dir="ltr">{label}</p>
    </div>
  )
}
