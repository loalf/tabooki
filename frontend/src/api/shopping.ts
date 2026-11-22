import { type QueryClient, useIsMutating, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

type Schemas = components['schemas']

export type ShoppingList = Schemas['ShoppingList']
export type ShoppingItem = NonNullable<ShoppingList['items']>[number]
export type Amount = NonNullable<ShoppingItem['amounts']>[number]
export type Aisle = NonNullable<ShoppingItem['aisle']>
export const AISLES: Aisle[] = ['produce', 'meat_fish', 'dairy_eggs', 'bakery', 'pantry', 'spices', 'frozen', 'drinks', 'other']

/** Which list: a week (its Monday), optionally only from a day on. */
export type ListScope = { week: string; from?: string }

export const shoppingKey = ({ week, from }: ListScope) => ['shopping', week, from ?? 'all'] as const

/**
 * A week's list. While it's open it refreshes every few seconds, so whoever else is shopping
 * sees what's been ticked off; not while changes are waiting to be sent, so they don't flicker back.
 * Summaries elsewhere (Hoy) pass `live: false`.
 */
export function useShoppingList(scope: ListScope, { live = true }: { live?: boolean } = {}) {
  const queryClient = useQueryClient()
  return useQuery({
    queryKey: shoppingKey(scope),
    queryFn: async () => {
      const { data, response } = await api.GET('/api/shopping-lists/{week}', {
        params: { path: { week: scope.week }, query: { from: scope.from } },
      })
      if (!data) throw new ApiError(response.status)
      return data
    },
    refetchInterval: (query) =>
      !live ? false : queryClient.isMutating({ mutationKey: ['shopping'] }) > 0 ? false : query.state.data?.classifying ? 2000 : 5000,
  })
}

/** Changes made offline wait here (and survive closing the app, see main.tsx). */
export function usePendingShoppingChanges() {
  return useIsMutating({ mutationKey: ['shopping'] })
}

type CheckVars = ListScope & { key: string; checked: boolean }
type AddVars = ListScope & { text: string; tempKey: string }
type RemoveVars = ListScope & { key: string }

async function unwrap(result: { data?: ShoppingList; response: Response }): Promise<ShoppingList> {
  if (!result.data) throw new ApiError(result.response.status)
  return result.data
}

/**
 * The list's changes, registered by key so mutations paused offline can be resumed after a
 * reload (their functions can't be saved with them). Each shows at once (optimistic) and the
 * server's answer then replaces the cached list.
 */
export function registerShoppingMutations(queryClient: QueryClient) {
  const optimistic = async (scope: ListScope, change: (list: ShoppingList) => ShoppingList) => {
    await queryClient.cancelQueries({ queryKey: shoppingKey(scope) })
    queryClient.setQueryData<ShoppingList>(shoppingKey(scope), (list) => (list ? change(list) : list))
  }
  const settle = (scope: ListScope, list?: ShoppingList) => {
    if (list) queryClient.setQueryData(shoppingKey(scope), list)
    // Another scope of the same week (all / from today) is now out of date.
    void queryClient.invalidateQueries({ queryKey: ['shopping', scope.week], predicate: (q) => q.queryKey[2] !== (scope.from ?? 'all') })
  }

  queryClient.setMutationDefaults(['shopping', 'check'], {
    mutationFn: ({ week, from, key, checked }: CheckVars) =>
      api.POST('/api/shopping-lists/{week}/check', { params: { path: { week }, query: { from } }, body: { key, checked } }).then(unwrap),
    onMutate: (vars: CheckVars) =>
      optimistic(vars, (list) => ({
        ...list,
        items: list.items?.map((item) => (item.key === vars.key ? { ...item, checked: vars.checked } : item)),
      })),
    onSuccess: (list: ShoppingList, vars: CheckVars) => settle(vars, list),
  })
  queryClient.setMutationDefaults(['shopping', 'add'], {
    mutationFn: ({ week, from, text }: AddVars) =>
      api.POST('/api/shopping-lists/{week}/items', { params: { path: { week }, query: { from } }, body: { text } }).then(unwrap),
    onMutate: (vars: AddVars) =>
      optimistic(vars, (list) => ({
        ...list,
        items: [...(list.items ?? []), { key: vars.tempKey, name: vars.text, manual: true, checked: false, staple: false, aisle: 'other', amounts: [], recipes: [] }],
      })),
    onSuccess: (list: ShoppingList, vars: AddVars) => settle(vars, list),
  })
  queryClient.setMutationDefaults(['shopping', 'remove'], {
    mutationFn: ({ week, from, key }: RemoveVars) =>
      api.POST('/api/shopping-lists/{week}/items/remove', { params: { path: { week }, query: { from } }, body: { key, checked: false } }).then(unwrap),
    onMutate: (vars: RemoveVars) => optimistic(vars, (list) => ({ ...list, items: list.items?.filter((item) => item.key !== vars.key) })),
    onSuccess: (list: ShoppingList, vars: RemoveVars) => settle(vars, list),
  })
}

export function useCheckItem() {
  return useMutation<ShoppingList, Error, CheckVars>({ mutationKey: ['shopping', 'check'] })
}

export function useAddItem() {
  return useMutation<ShoppingList, Error, AddVars>({ mutationKey: ['shopping', 'add'] })
}

export function useRemoveItem() {
  return useMutation<ShoppingList, Error, RemoveVars>({ mutationKey: ['shopping', 'remove'] })
}

/** Fix where an ingredient goes, or mark it as always at home; kept for every future list. */
export function useUpdateIngredient() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, ...body }: { id: string; aisle?: Aisle; staple?: boolean }) => {
      // The generated merge-patch type marks every field required; a patch sends only what changes.
      const patch = body as Schemas['Ingredient-ingredient.write.jsonMergePatch']
      const { response } = await api.PATCH('/api/ingredients/{id}', {
        params: { path: { id } },
        body: patch,
        headers: { 'Content-Type': 'application/merge-patch+json' },
      })
      if (!response.ok) throw new ApiError(response.status)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['shopping'] }),
  })
}
