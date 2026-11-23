import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

type Schemas = components['schemas']

export type HouseholdIngredient = Schemas['Ingredient-ingredient.read']
export type IngredientDetail = Schemas['Ingredient-ingredient.read_ingredient.item']
export type FoodDetail = NonNullable<IngredientDetail['food']>
export type FoodChoice = Schemas['Food-food.read']
export type FoodCorrection = Schemas['Food.FoodCorrectionInput-food.correct']

const keys = {
  all: ['ingredients'] as const,
  one: (id: string) => ['ingredients', id] as const,
  foods: (q: string) => ['foods', q] as const,
}

/** The household's ingredients: every one its recipes use, with the food each is and how many recipes use it. */
export function useIngredients() {
  return useQuery({
    queryKey: keys.all,
    queryFn: async () => {
      const { data, response } = await api.GET('/api/ingredients')
      if (!data) throw new ApiError(response.status)
      return data
    },
  })
}

export function useIngredient(id: string) {
  return useQuery({
    queryKey: keys.one(id),
    queryFn: async () => {
      const { data, response } = await api.GET('/api/ingredients/{id}', { params: { path: { id } } })
      if (!data) throw new ApiError(response.status)
      return data
    },
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
    // Its food is being described (new, or a fix undone): it's back in seconds.
    refetchInterval: (query) => (query.state.data?.food && !query.state.data.food.described ? 3000 : false),
  })
}

/** Shared foods by their name or key, those recipes use most first: what an ingredient can be pointed at. */
export function useFoodSearch(q: string) {
  const query = q.trim()
  return useQuery({
    queryKey: keys.foods(query),
    enabled: query.length >= 2,
    queryFn: async () => {
      const { data, response } = await api.GET('/api/foods', { params: { query: { q: query } } })
      if (!data) throw new ApiError(response.status)
      return data
    },
    placeholderData: (previous) => previous,
  })
}

/** Point an ingredient at another food: the household's recipes with it are weighed and added up again. */
export function useRepointIngredient(id: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (foodId: string) => {
      // The generated merge-patch type marks every field required; a patch sends only what changes.
      const body = { food: `/api/foods/${foodId}` } as unknown as Schemas['Ingredient-ingredient.write.jsonMergePatch']
      const { response } = await api.PATCH('/api/ingredients/{id}', {
        params: { path: { id } },
        body,
        headers: { 'Content-Type': 'application/merge-patch+json' },
      })
      if (!response.ok) throw new ApiError(response.status)
    },
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.all }),
        queryClient.invalidateQueries({ queryKey: ['recipes'] }),
        queryClient.invalidateQueries({ queryKey: ['shopping'] }),
      ]),
  })
}

function useFoodChanged() {
  const queryClient = useQueryClient()
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.all }),
      queryClient.invalidateQueries({ queryKey: ['foods'] }),
      queryClient.invalidateQueries({ queryKey: ['recipes'] }),
      queryClient.invalidateQueries({ queryKey: ['shopping'] }),
    ])
}

/** Fix a food's figures by hand (shared by everyone who cooks with it): its recipes are worked out again. */
export function useCorrectFood(foodId: string) {
  const changed = useFoodChanged()
  return useMutation({
    mutationFn: async (body: FoodCorrection) => {
      const { error, response } = await api.POST('/api/foods/{id}/correction', { params: { path: { id: foodId } }, body })
      if (!response.ok) {
        const violations = (error as { violations?: { propertyPath: string; message: string }[] } | undefined)?.violations
        throw Object.assign(new ApiError(response.status), { violations: violations ?? [] })
      }
    },
    onSuccess: changed,
  })
}

/** Undo the fixes: the model describes the food again in the background. */
export function useEstimateFood(foodId: string) {
  const changed = useFoodChanged()
  return useMutation({
    mutationFn: async () => {
      const { response } = await api.POST('/api/foods/{id}/estimate', { params: { path: { id: foodId } } })
      if (!response.ok) throw new ApiError(response.status)
    },
    onSuccess: changed,
  })
}

/** In the reader's language, else the other, else its key. */
export function ingredientName(ingredient: { nameEs?: string | null; nameEn?: string | null; canonicalKey?: string }, language: string): string {
  const en = language.startsWith('en')
  const name = en ? (ingredient.nameEn ?? ingredient.nameEs) : (ingredient.nameEs ?? ingredient.nameEn)
  return name ?? (ingredient.canonicalKey ?? '').replaceAll('_', ' ')
}

export function foodName(food: { nameEs?: string | null; nameEn?: string | null; key?: string }, language: string): string {
  return ingredientName({ nameEs: food.nameEs, nameEn: food.nameEn, canonicalKey: food.key }, language)
}
