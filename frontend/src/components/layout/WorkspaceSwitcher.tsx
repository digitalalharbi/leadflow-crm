import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { HOME } from '@/lib/routes'
import { useTranslation } from 'react-i18next'
import { Check } from 'lucide-react'
import { apiGet, apiPost, tokenStorage } from '@/lib/api'
import { useApiError } from '@/hooks/useApiError'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { Button } from '@/components/ui'

interface Workspace {
  id: string
  name: string
  role: string
  current: boolean
}

/**
 * The companies this person belongs to, when there is more than one. Switching asks the server for a session in the
 * other company — the server checks the membership; the browser never says which company it is in.
 */
export function WorkspaceSwitcher({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const restore = useAuth((s) => s.restore)
  const impersonation = useAuth((s) => s.impersonation)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const { data } = useQuery({
    queryKey: ['workspaces'],
    queryFn: () => apiGet<{ workspaces: Workspace[] }>('/auth/workspaces'),
    enabled: !impersonation,
    staleTime: 60_000,
  })

  // The account in the other company may have its own second factor; being signed in here does not prove it.
  const [needsCode, setNeedsCode] = useState<string | null>(null)
  const [code, setCode] = useState('')

  const switchTo = useMutation({
    mutationFn: ({ id, code: entered }: { id: string; code?: string }) => apiPost<{ token?: string; mfa_required?: boolean }>(`/auth/workspaces/${id}/switch`, entered ? { code: entered } : undefined),
    onSuccess: async (result, { id }) => {
      if (!result.token) {
        setNeedsCode(id)
        setCode('')

        return
      }

      tokenStorage.set(result.token)
      useProject.getState().select(null)
      onDone()
      await restore()
      window.location.assign(HOME)
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

  const workspaces = data?.workspaces ?? []

  if (workspaces.length < 2) return null

  return (
    <div className="border-t border-[var(--color-line)] px-3 py-2" role="group" aria-label={t('account.workspaces')} data-testid="workspace-switcher">
      <p className="mb-1 text-[0.8125rem] text-[var(--color-ink-subtle)]">{t('account.workspaces')}</p>
      <ul className="space-y-0.5">
        {workspaces.map((workspace) => (
          <li key={workspace.id}>
            <button
              type="button"
              disabled={workspace.current || switchTo.isPending}
              onClick={() => { setNeedsCode(null); switchTo.mutate({ id: workspace.id }) }}
              data-testid={`workspace-${workspace.id}`}
              data-current={workspace.current}
              className="flex w-full items-center justify-between gap-2 rounded-[var(--radius-control)] px-2 py-1.5 text-start text-[0.9375rem] hover:bg-[var(--color-surface-muted)] disabled:cursor-default"
            >
              <span className="min-w-0">
                <span className="block truncate font-medium">{workspace.name}</span>
                <span className="block text-[0.8125rem] text-[var(--color-ink-subtle)]">{t(`roles.${workspace.role}`, { defaultValue: workspace.role })}</span>
              </span>
              {workspace.current && <Check className="size-4 shrink-0" aria-hidden />}
            </button>
          </li>
        ))}
      </ul>
      {needsCode && (
        <form
          className="mt-2 flex items-center gap-2"
          data-testid="workspace-code-form"
          onSubmit={(event) => {
            event.preventDefault()
            switchTo.mutate({ id: needsCode, code })
          }}
        >
          <input
            value={code}
            onChange={(event) => setCode(event.target.value)}
            inputMode="numeric"
            autoComplete="one-time-code"
            aria-label={t('auth.mfaCode')}
            placeholder={t('auth.mfaCode')}
            data-testid="workspace-code"
            dir="ltr"
            className="field field-otp min-w-0 flex-1"
          />
          <Button type="submit" size="sm" loading={switchTo.isPending} disabled={code.trim() === ''}>{t('common.confirm')}</Button>
        </form>
      )}
    </div>
  )
}
