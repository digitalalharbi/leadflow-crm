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

        /*
         * A reason the server gave as a key — `sheets.not_picked` — is looked
         * up. Several controllers return their reasons that way so the wording
         * lives in one place, and until this they reached the screen as the
         * bare key, or as "validation failed" when the key was only in the
         * message. Anything that is not a key is shown as it always was.
         */
        if (first && /^[a-z0-9_]+(\.[a-z0-9_]+)+$/.test(first)) {
          return t(first, { defaultValue: t('errors.validation') })
        }

        return first ?? t('errors.validation')
      }

      return t(`errors.${error.message}`, { defaultValue: t('errors.unknown') })
    }

    return t('errors.unknown')
  }
}
