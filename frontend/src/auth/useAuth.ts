import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

export type Me = components['schemas']['Me-me.read']
type MeUpdate = Pick<Me, 'displayName' | 'locale'>

export const meQueryKey = ['me'] as const

/** The signed-in user, or `null` when there is no session. */
export function useMe() {
  return useQuery({
    queryKey: meQueryKey,
    queryFn: async (): Promise<Me | null> => {
      const { data, response } = await api.GET('/api/me')
      if (response.status === 401) return null
      if (!data) throw new ApiError(response.status)
      return data
    },
    staleTime: 5 * 60_000,
    retry: false,
  })
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (credentials: { email: string; password: string }) => {
      const response = await fetch('/api/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(credentials),
      })
      if (!response.ok) throw new ApiError(response.status)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: meQueryKey }),
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      await fetch('/api/logout', { method: 'POST', credentials: 'same-origin' })
    },
    onSettled: () => {
      queryClient.clear()
      queryClient.setQueryData(meQueryKey, null)
    },
  })
}

export function useUpdateMe() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (changes: Partial<MeUpdate>) => {
      const current = queryClient.getQueryData<Me | null>(meQueryKey)
      if (!current) throw new ApiError(401)
      const { data, response } = await api.PATCH('/api/me', {
        body: { displayName: current.displayName, locale: current.locale, ...changes },
        headers: { 'Content-Type': 'application/merge-patch+json' },
      })
      if (!data) throw new ApiError(response.status)
      return data
    },
    onSuccess: (me) => queryClient.setQueryData(meQueryKey, me),
  })
}
