import { useState, type FormEvent } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ApiError, apiPatch, apiPost } from '@/lib/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useApiError } from '@/hooks/useApiError'
import { Button, Card, CardBody, CardHeader, Input, PageHeader } from '@/components/ui'
import { NotificationPrefsCard } from '@/components/profile/NotificationPrefsCard'
import { SecurityCard } from '@/components/profile/SecurityCard'

export function ProfilePage() {
  const { t } = useTranslation()
  const user = useAuth((s) => s.user)
  const setUser = useAuth((s) => s.setUser)
  const toast = useUi((s) => s.toast)
  const translateError = useApiError()

  const [awayUntil, setAwayUntil] = useState(user?.away_until?.slice(0, 10) ?? '')
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const away = useMutation({
    mutationFn: (until: string | null) =>
      apiPatch(`/users/${user?.id}`, { away_until: until }),
    onSuccess: (_result, until) => {
      if (user) setUser({ ...user, away_until: until })
      toast(t(until ? 'users.awaySaved' : 'users.backSaved'), 'success')
    },
    onError: (caught) => toast(translateError(caught), 'error'),
  })

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
          <CardBody className="space-y-2.5 text-[1rem]">
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

        {/*
          "I am back on Sunday", said by the person who knows.

          Assignment's only gate was `is_active`, which is set when somebody
          leaves the company — so a rep on leave kept receiving leads that sat
          untouched until they returned. A date rather than a switch, because a
          switch is a thing somebody has to remember to turn off, and the Sunday
          they forget is a week of leads going nowhere.
        */}
        <Card>
          <CardHeader title={t('users.away')} description={t('users.awayHint')} />
          <CardBody className="space-y-3">
            <Input
              type="date"
              label={t('users.awayUntil')}
              value={awayUntil}
              min={new Date().toISOString().slice(0, 10)}
              onChange={(event) => setAwayUntil(event.target.value)}
              data-testid="away-until"
            />
            <div className="flex items-center gap-2">
              <Button loading={away.isPending} onClick={() => away.mutate(awayUntil || null)}>
                {t('common.save')}
              </Button>
              {awayUntil && (
                <Button
                  variant="ghost"
                  onClick={() => {
                    setAwayUntil('')
                    away.mutate(null)
                  }}
                >
                  {t('users.backNow')}
                </Button>
              )}
            </div>
          </CardBody>
        </Card>

        <NotificationPrefsCard />

        <SecurityCard />

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
