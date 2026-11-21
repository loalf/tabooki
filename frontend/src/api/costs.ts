import { useQuery } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

export type Costs = components['schemas']['Costs']
export type CostCall = components['schemas']['CostCall']

/** What the household's AI calls have cost (GET /api/costs). */
export function useCosts() {
  return useQuery({
    queryKey: ['costs'],
    queryFn: async () => {
      const { data, response } = await api.GET('/api/costs')
      if (!data) throw new ApiError(response.status)
      return data
    },
  })
}

/** "$0.0213", "$1.20": small amounts keep enough decimals to mean something. */
export function formatUsd(value: number, locale: string): string {
  const digits = value === 0 ? 0 : value < 0.01 ? 4 : value < 1 ? 3 : 2
  return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD', minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value)
}
