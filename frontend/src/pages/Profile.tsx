import { useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ApiError, apiPost } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Button, Card, CardBody, CardHeader, Input, PageHeader } from '@/components/ui'

export function ProfilePage() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const mutation = useMutation({
    mutationFn: () => apiPost('/auth/password', form),
    onSuccess: () => {
      setForm({ current_password: '', password: '', password_confirmation: '' })
      toast(t('auth.passwordChanged'), 'success')
    },
    onError: (caught) => {
      if (caught instanceof ApiError && caught.status === 422 && caught.errors) {
        const next: Record<string, string> = {}
        for (const [field, messages] of Object.entries(caught.errors)) {
          if (messages[0]) next[field] = messages[0]
        }
        setFieldErrors(next)
      } else {
        toast(translateError(caught), 'error')
      }
    },
  })

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setFieldErrors({})
    mutation.mutate()
  }

  return (
    <>
      <PageHeader title={t('nav.profile')} />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title={t('users.name')} />
          <CardBody className="space-y-2.5 text-[0.875rem]">
            <div className="flex justify-between gap-3">
              <span className="text-[var(--color-ink-muted)]">{t('users.name')}</span>
              <span className="font-medium">{user?.name}</span>
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-[var(--color-ink-muted)]">{t('auth.email')}</span>
              <span className="font-medium" dir="ltr">{user?.email}</span>
            </div>
            <div className="flex justify-between gap-3">
              <span className="text-[var(--color-ink-muted)]">{t('users.role')}</span>
              <span className="font-medium">{t(`roles.${user?.role ?? 'sales'}`)}</span>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title={t('auth.changePassword')} />
          <CardBody>
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
              <Input
                required
                dir="ltr"
                type="password"
                autoComplete="current-password"
                label={t('auth.currentPassword')}
                value={form.current_password}
                error={fieldErrors.current_password}
                onChange={(e) => setForm((prev) => ({ ...prev, current_password: e.target.value }))}
              />
              <Input
                required
                dir="ltr"
                type="password"
                autoComplete="new-password"
                label={t('auth.newPassword')}
                value={form.password}
                error={fieldErrors.password}
                onChange={(e) => setForm((prev) => ({ ...prev, password: e.target.value }))}
              />
              <Input
                required
                dir="ltr"
                type="password"
                autoComplete="new-password"
                label={t('auth.confirmPassword')}
                value={form.password_confirmation}
                onChange={(e) => setForm((prev) => ({ ...prev, password_confirmation: e.target.value }))}
              />
              <div className="flex justify-end">
                <Button type="submit" loading={mutation.isPending}>{t('common.save')}</Button>
              </div>
            </form>
          </CardBody>
        </Card>
      </div>
    </>
  )
}
