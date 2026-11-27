import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import { meQueryKey } from '@/auth/useAuth'

/** A 422 from the password endpoints: which field is wrong (currentPassword, newPassword, token…). */
export class PasswordError extends ApiError {
  readonly fields: string[]

  constructor(status: number, fields: string[] = []) {
    super(status)
    this.fields = fields
  }
}

function failure(response: Response, error: unknown): PasswordError {
  const violations = error && typeof error === 'object' && 'violations' in error ? (error as { violations: { propertyPath: string }[] }).violations : []
  return new PasswordError(response.status, violations.map((v) => v.propertyPath))
}

/**
 * Signed in: change it, or add one (currentPassword empty) after signing up with Google. Other
 * devices are signed out; this one stays signed in.
 */
export function useChangePassword() {
  const queryClient = useQueryClient()
  return useMutation<void, PasswordError, { currentPassword: string; newPassword: string }>({
    mutationFn: async (body: { currentPassword: string; newPassword: string }) => {
      const { error, response } = await api.POST('/api/me/password', { body })
      if (!response.ok) throw failure(response, error)
    },
    // A first password changes Me.hasPassword.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: meQueryKey }),
  })
}

/** Signed out: email a link. The answer is the same whether or not the address has an account. */
export function useForgotPassword() {
  return useMutation<void, PasswordError, string>({
    mutationFn: async (email: string) => {
      const { error, response } = await api.POST('/api/password/forgot', { body: { email } })
      if (!response.ok) throw failure(response, error)
    },
  })
}

/** From the emailed link. */
export function useResetPassword() {
  return useMutation<void, PasswordError, { token: string; password: string }>({
    mutationFn: async (body: { token: string; password: string }) => {
      const { error, response } = await api.POST('/api/password/reset', { body })
      if (!response.ok) throw failure(response, error)
    },
  })
}

export const MIN_PASSWORD_LENGTH = 8
