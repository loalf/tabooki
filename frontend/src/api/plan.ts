import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

type Schemas = components['schemas']

export type MealPlan = Schemas['MealPlan-meal_plan.read_recipe.list']
export type MealPlanEntry = Schemas['MealPlanEntry-meal_plan.read_recipe.list']
export type PlannedRecipe = NonNullable<MealPlanEntry['recipe']>
export type MealPlanInput = Schemas['MealPlan.MealPlanInput']
export type Slot = 'lunch' | 'dinner'
export type SlotChoice = { date: string; slot: Slot; servings?: number }

const keys = {
  all: ['meal-plans'] as const,
  week: (week: string) => ['meal-plans', 'week', week] as const,
}

/** The plan of the week starting on `week` (a Monday, YYYY-MM-DD), or null if it isn't planned yet. */
export function useMealPlan(week: string) {
  return useQuery({
    queryKey: keys.week(week),
    queryFn: async (): Promise<MealPlan | null> => {
      const { data, response } = await api.GET('/api/meal-plans', { params: { query: { week } } })
      if (!data) throw new ApiError(response.status)
      return data[0] ?? null
    },
  })
}

/** Every action answers with the whole week: put it straight in the cache. */
function useWeekMutation<Input>(request: (input: Input) => Promise<MealPlan>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: request,
    onSuccess: (plan) => {
      if (plan.weekStart) queryClient.setQueryData(keys.week(plan.weekStart), plan)
    },
  })
}

async function unwrap(result: { data?: MealPlan; response: Response }): Promise<MealPlan> {
  if (!result.data) throw new ApiError(result.response.status)
  return result.data
}

export function useCreateMealPlan() {
  return useWeekMutation((body: MealPlanInput) => api.POST('/api/meal-plans', { body }).then(unwrap))
}

export function useRegenerateMealPlan() {
  return useWeekMutation((id: string) => api.POST('/api/meal-plans/{id}/regenerate', { params: { path: { id } } }).then(unwrap))
}

export function useAddMeal() {
  return useWeekMutation(({ planId, ...body }: SlotChoice & { planId: string }) =>
    api.POST('/api/meal-plans/{id}/entries', { params: { path: { id: planId } }, body }).then(unwrap),
  )
}

type EntryAction = { planId: string; entry: string }

export function useSwapMeal() {
  return useWeekMutation(({ planId, entry }: EntryAction) =>
    api.POST('/api/meal-plans/{id}/entries/swap', { params: { path: { id: planId } }, body: { entry } }).then(unwrap),
  )
}

export function useUpdateMeal() {
  return useWeekMutation(
    ({ planId, ...body }: EntryAction & { locked?: boolean; servings?: number; recipe?: string; rating?: number }) =>
      api.POST('/api/meal-plans/{id}/entries/update', { params: { path: { id: planId } }, body }).then(unwrap),
  )
}

export function useRemoveMeal() {
  return useWeekMutation(({ planId, entry }: EntryAction) =>
    api.POST('/api/meal-plans/{id}/entries/remove', { params: { path: { id: planId } }, body: { entry } }).then(unwrap),
  )
}

/** A wish for this week only (null clears it); the week is planned again. Takes a few seconds (the text model). */
export function useTweakWeek() {
  return useWeekMutation(({ planId, tweak }: { planId: string; tweak: string | null }) =>
    api.POST('/api/meal-plans/{id}/tweak', { params: { path: { id: planId } }, body: { tweak } }).then(unwrap),
  )
}

export function useDeleteMealPlan() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (plan: MealPlan) => {
      const { response } = await api.DELETE('/api/meal-plans/{id}', { params: { path: { id: plan.id! } } })
      if (!response.ok) throw new ApiError(response.status)
      return plan
    },
    onSuccess: (plan) => {
      if (plan.weekStart) queryClient.setQueryData(keys.week(plan.weekStart), null)
    },
  })
}
