import { useTranslation } from 'react-i18next'
import { ApiError } from '@/lib/api'

/**
 * Turns any thrown value into a message that is safe and meaningful for a user.
 * Raw server text is only trusted for 422, where it is a validation summary.
 */
export function useApiError() {
  const { t } = useTranslation()

  return (error: unknown): string => {
    if (error instanceof ApiError) {
      if (error.status === 422) {
        const first = error.errors ? Object.values(error.errors)[0]?.[0] : null
        return first ?? t('errors.validation')
      }

      return t(`errors.${error.message}`, { defaultValue: t('errors.unknown') })
    }

    return t('errors.unknown')
  }
}
