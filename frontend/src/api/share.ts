import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

export type ShareToken = components['schemas']['ShareToken-share_token.read']

const key = ['share-tokens'] as const

/** Personal tokens for saving links from outside the app (the iPhone Shortcut). */
export function useShareTokens() {
  return useQuery({
    queryKey: key,
    queryFn: async () => {
      const { data, response } = await api.GET('/api/share-tokens')
      if (!data) throw new ApiError(response.status)
      return data
    },
  })
}

/** Resolves to the secret, which the server only shows this once. */
export function useCreateShareToken() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (name: string) => {
      const { data, response } = await api.POST('/api/share-tokens', { body: { name } })
      if (!data?.token) throw new ApiError(response.status)
      return data.token
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  })
}

export function useDeleteShareToken() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      const { response } = await api.DELETE('/api/share-tokens/{id}', { params: { path: { id } } })
      if (!response.ok) throw new ApiError(response.status)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  })
}

/** The link in what a share sheet sent: apps put it in url, text or title, often after some words. */
export function findSharedUrl(...texts: (string | null | undefined)[]): string | null {
  for (const text of texts) {
    const match = text?.match(/https?:\/\/[^\s<>"]+/i)
    if (match) return match[0].replace(/[.,;:!?)\]}]+$/, '')
  }
  return null
}
