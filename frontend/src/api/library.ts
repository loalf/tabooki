import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/api/client'
import type { components } from '@/api/schema'

type Schemas = components['schemas']

export type Source = Schemas['Source-source.read']
export type Platform = NonNullable<Source['platform']>
export type RecipeListItem = Schemas['Recipe-recipe.list']
export type Recipe = Schemas['Recipe-recipe.read']
export type RecipeInput = Schemas['Recipe-recipe.write']
export type RecipePatch = Schemas['Recipe-recipe.write.jsonMergePatch']
export type IngredientInput = Schemas['RecipeIngredient-recipe.write']
export type Ingredient = Schemas['RecipeIngredient-recipe.read']
export type RecipeTag = Schemas['RecipeTag']

export type SourceStatus = Source['status']

export type RecipeFilters = { q: string; tags: string[]; favorite: boolean; needsReview?: boolean }

const keys = {
  sources: ['sources'] as const,
  inbox: ['sources', 'inbox'] as const,
  source: (id: string) => ['sources', 'detail', id] as const,
  recipes: ['recipes'] as const,
  recipeList: (filters: RecipeFilters) => ['recipes', 'list', filters] as const,
  recipe: (id: string) => ['recipes', 'detail', id] as const,
  tags: ['recipe-tags'] as const,
}

/** A constraint violation from a 422 response. */
export type Violation = { propertyPath: string; message: string; code?: string }

export class ValidationError extends ApiError {
  readonly violations: Violation[]

  constructor(violations: Violation[]) {
    super(422, violations.map((v) => v.message).join(' '))
    this.violations = violations
  }
}

/** Codes from App\State\CreateSourceProcessor. */
export const DUPLICATE_LINK = 'b9a3c7e2-5d1f-4c8a-9e6b-2f7d0a4c1e55'
export const INVALID_LINK = 'e4f1a2b3-6c7d-4e8f-9a0b-1c2d3e4f5a6b'

function failure(response: Response, error: unknown): ApiError {
  if (response.status === 422 && error && typeof error === 'object' && 'violations' in error) {
    return new ValidationError((error as { violations: Violation[] }).violations)
  }
  return new ApiError(response.status)
}

// —— Saved links ————————————————————————————————————————————————

/**
 * Previews and extraction run in the background: poll while a link is being worked on.
 * "pending" links stop being polled after a while (e.g. links saved before extraction existed).
 */
export function isWorking(source: Source): boolean {
  if (source.status === 'extracting') return true
  const recent = source.createdAt && Date.parse(source.createdAt) > Date.now() - 10 * 60_000
  return Boolean(recent && (source.status === 'pending' || !source.metadataFetchedAt))
}

function waitingForWorker(sources: Source[] | undefined): boolean {
  return (sources ?? []).some(isWorking)
}

/** Links not yet turned into a recipe, newest first. */
export function useInbox() {
  return useQuery({
    queryKey: keys.inbox,
    queryFn: async () => {
      const { data, error, response } = await api.GET('/api/sources', { params: { query: { hasRecipe: false } } })
      if (!data) throw failure(response, error)
      return data
    },
    refetchInterval: (query) => (waitingForWorker(query.state.data) ? 2500 : false),
  })
}

export function useSource(id: string | null) {
  return useQuery({
    queryKey: keys.source(id ?? ''),
    enabled: id !== null,
    queryFn: async () => {
      const { data, error, response } = await api.GET('/api/sources/{id}', { params: { path: { id: id! } } })
      if (!data) throw failure(response, error)
      return data
    },
  })
}

export function useSaveLink() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (url: string) => {
      const { data, error, response } = await api.POST('/api/sources', { body: { url } })
      if (!data) throw failure(response, error)
      return data
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.sources }),
  })
}

/** Try extracting again, optionally from text the cook pasted (a caption, the recipe from the comments…). */
export function useExtractSource() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ id, text }: { id: string; text?: string }) => {
      const { data, error, response } = text
        ? await api.POST('/api/sources/{id}/text', { params: { path: { id } }, body: { text } })
        : await api.POST('/api/sources/{id}/extract', { params: { path: { id } } })
      if (!data) throw failure(response, error)
      return data
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.sources }),
  })
}

export function useDeleteSource() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      const { error, response } = await api.DELETE('/api/sources/{id}', { params: { path: { id } } })
      if (!response.ok) throw failure(response, error)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.sources }),
  })
}

// —— Recipes ———————————————————————————————————————————————————

export function useRecipes(filters: RecipeFilters) {
  return useQuery({
    queryKey: keys.recipeList(filters),
    queryFn: async () => {
      const { data, error, response } = await api.GET('/api/recipes', {
        params: {
          query: {
            q: filters.q.trim() || undefined,
            'tags[]': filters.tags.length ? filters.tags : undefined,
            favorite: filters.favorite || undefined,
            needsReview: filters.needsReview || undefined,
          },
        },
      })
      if (!data) throw failure(response, error)
      return data
    },
    // Keep showing the previous results while the next search loads (no flicker while typing).
    placeholderData: keepPreviousData,
  })
}

/** The recipe's link is being extracted again (POST /api/recipes/{id}/extract). */
export function isReextracting(recipe: Recipe | undefined): boolean {
  const status = recipe?.source?.status
  return status === 'pending' || status === 'extracting'
}

export function useRecipe(id: string | null) {
  return useQuery({
    queryKey: keys.recipe(id ?? ''),
    enabled: Boolean(id),
    queryFn: async () => {
      const { data, error, response } = await api.GET('/api/recipes/{id}', { params: { path: { id: id! } } })
      if (!data) throw failure(response, error)
      return data
    },
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
    refetchInterval: (query) => (isReextracting(query.state.data) ? 2500 : false),
  })
}

export function useRecipeTags() {
  return useQuery({
    queryKey: keys.tags,
    queryFn: async () => {
      const { data, error, response } = await api.GET('/api/recipe-tags')
      if (!data) throw failure(response, error)
      return data
    },
    staleTime: 60_000,
  })
}

/** How many extracted recipes nobody has checked yet. */
export function useReviewCount(): number {
  const { data = [] } = useRecipes({ q: '', tags: [], favorite: false, needsReview: true })
  return data.length
}

/** Tag names in the library, most used first. */
export function useTagNames(): string[] {
  const { data = [] } = useRecipeTags()
  return data.flatMap((tag) => (tag.tag ? [tag.tag] : []))
}

/** After any recipe change: lists, tag counts and the inbox (a link may have gained or lost its recipe). */
function useInvalidateLibrary() {
  const queryClient = useQueryClient()
  return (recipe?: Recipe) => {
    if (recipe?.id) queryClient.setQueryData(keys.recipe(recipe.id), recipe)
    void queryClient.invalidateQueries({ queryKey: ['recipes', 'list'] })
    void queryClient.invalidateQueries({ queryKey: keys.tags })
    void queryClient.invalidateQueries({ queryKey: keys.sources })
  }
}

export function useCreateRecipe() {
  const invalidate = useInvalidateLibrary()
  return useMutation({
    mutationFn: async (recipe: RecipeInput) => {
      const { data, error, response } = await api.POST('/api/recipes', { body: recipe })
      if (!data) throw failure(response, error)
      return data
    },
    onSuccess: (recipe) => invalidate(recipe),
  })
}

export function useUpdateRecipe(id: string) {
  const invalidate = useInvalidateLibrary()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (changes: Partial<RecipeInput>) => {
      const { data, error, response } = await api.PATCH('/api/recipes/{id}', {
        params: { path: { id } },
        // A merge-patch body only carries what changes, though the generated schema marks fields required.
        body: changes as RecipePatch,
        headers: { 'Content-Type': 'application/merge-patch+json' },
      })
      if (!data) throw failure(response, error)
      return data
    },
    // Optimistic for the quick toggles on the recipe page (favorite, rating).
    onMutate: async (changes) => {
      await queryClient.cancelQueries({ queryKey: keys.recipe(id) })
      const previous = queryClient.getQueryData<Recipe>(keys.recipe(id))
      if (previous && !('ingredients' in changes)) {
        queryClient.setQueryData<Recipe>(keys.recipe(id), { ...previous, ...(changes as Partial<Recipe>) })
      }
      return { previous }
    },
    onError: (_error, _changes, context) => {
      if (context?.previous) queryClient.setQueryData(keys.recipe(id), context.previous)
    },
    onSuccess: (recipe) => invalidate(recipe),
  })
}

/** Extract the recipe again from its link with the current model; poll useRecipe to follow it. */
export function useReextractRecipe(id: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const { data, error, response } = await api.POST('/api/recipes/{id}/extract', { params: { path: { id } } })
      if (!data) throw failure(response, error)
      return data
    },
    onSuccess: (recipe) => queryClient.setQueryData(keys.recipe(id), recipe),
  })
}

/** Lists show the title and tags, which re-extracting may have changed. */
export function useRefreshRecipeLists() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['recipes', 'list'] })
    void queryClient.invalidateQueries({ queryKey: keys.tags })
  }
}

export function useDeleteRecipe() {
  const invalidate = useInvalidateLibrary()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      const { error, response } = await api.DELETE('/api/recipes/{id}', { params: { path: { id } } })
      if (!response.ok) throw failure(response, error)
      return id
    },
    onSuccess: (id) => {
      queryClient.removeQueries({ queryKey: keys.recipe(id) })
      invalidate()
    },
  })
}

// —— Helpers ———————————————————————————————————————————————————

const platformNames = { youtube: 'YouTube', instagram: 'Instagram', tiktok: 'TikTok', facebook: 'Facebook' } as const

/** "YouTube", "Instagram"… or the site's host name for other links. */
export function platformLabel(platform: Platform | undefined, url?: string): string {
  return platform && platform !== 'web' ? platformNames[platform] : hostOf(url)
}

export function hostOf(url: string | undefined): string {
  if (!url) return ''
  try {
    return new URL(url).hostname.replace(/^(www|m)\./, '')
  } catch {
    return url
  }
}
