import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'
import type { FoodGroup } from '@/lib/facts'

type Schemas = components['schemas']

/** What the household likes (docs/PLAN.md §6.1). */
export type TasteProfile = Schemas['TasteProfile']
export type TasteRules = NonNullable<TasteProfile['rules']>
export type TasteImpact = Schemas['TasteImpactView']
export type TasteDraft = Schemas['TasteProfile.TasteDraftView']
export type TasteConflict = NonNullable<Schemas['Recipe-recipe.list']['tasteConflicts']>[number]
export type Strength = 'never' | 'avoid'
export type Diet = 'vegan' | 'vegetarian' | 'pescatarian'

/**
 * The questionnaire's answers, as sent to POST /api/taste-profile/draft and kept with the
 * profile to show them again. The server reads the tappable ones itself
 * (App\Taste\TasteRules::fromAnswers()); the model reads the rest.
 */
export type TasteAnswers = {
  /** null: eats anything. */
  diet?: Diet | null
  /** A food group, or anything else they name ("cilantro"), and how firmly. */
  avoid?: { item: FoodGroup | string; custom?: boolean; strength: Strength }[]
  /** Meals with meat or fish a week, at most; null for no limit. */
  animalProteinMax?: number | null
  eggsAndDairyCount?: boolean
  fishMin?: number | null
  meals?: 'heavyLunch' | 'same' | 'heavyDinner' | null
  /** More of: legumes, vegetables, rice… (English words). */
  more?: string[]
  spice?: 'none' | 'mild' | 'any' | null
  other?: string
  /** The follow-up questions, answered: sent with the second draft. */
  followUps?: { question: string; answer: string }[]
}

const keys = {
  profile: ['taste-profile'] as const,
}

/** null until the household has said what it likes. */
export function useTasteProfile() {
  return useQuery({
    queryKey: keys.profile,
    queryFn: async (): Promise<TasteProfile | null> => {
      const { data, response } = await api.GET('/api/taste-profile')
      if (response.status === 404) return null
      if (!data) throw new ApiError(response.status)
      return data
    },
  })
}

/** The questionnaire, turned into rules by the text model (a few seconds). Nothing is saved. */
export function useTasteDraft() {
  return useMutation({
    mutationFn: async (answers: TasteAnswers): Promise<TasteDraft> => {
      const { data, response } = await api.POST('/api/taste-profile/draft', { body: { answers } })
      if (!data) throw new ApiError(response.status)
      return data
    },
  })
}

/** What some rules would do to the library (after removing one on the review screen). */
export function useTasteImpact() {
  return useMutation({
    mutationFn: async (rules: TasteRules): Promise<TasteImpact> => {
      const { data, response } = await api.POST('/api/taste-profile/impact', { body: { rules } })
      if (!data) throw new ApiError(response.status)
      return data
    },
  })
}

/** Recipes say what they have against the likings: they change with them. */
function useRefresh() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: keys.profile })
    void queryClient.invalidateQueries({ queryKey: ['recipes'] })
  }
}

export function useSaveTaste() {
  const refresh = useRefresh()
  return useMutation({
    mutationFn: async (body: { answers: TasteAnswers; rules: TasteRules; notes: string | null; summary: { es: string; en: string } | null }) => {
      const { data, response } = await api.PUT('/api/taste-profile', { body })
      if (!data) throw new ApiError(response.status)
      return data
    },
    onSuccess: refresh,
  })
}

export function useForgetTaste() {
  const refresh = useRefresh()
  return useMutation({
    mutationFn: async () => {
      const { response } = await api.DELETE('/api/taste-profile')
      if (!response.ok) throw new ApiError(response.status)
    },
    onSuccess: refresh,
  })
}
