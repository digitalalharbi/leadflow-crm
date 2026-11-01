import { CompanyExportCard } from '@/components/settings/CompanyExportCard'
import { SuppressionsPanel } from '@/components/settings/SuppressionsPanel'

/** Who may not be contacted, and what the company keeps about people. */
export function PrivacyTab() {
  return (
    <div className="space-y-4">
      <SuppressionsPanel />
      <CompanyExportCard />
    </div>
  )
}
