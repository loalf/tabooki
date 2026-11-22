import { Heart, ListChecks, PenLine, Plus, Search, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { type RecipeFilters, useRecipes, useReviewCount, useTagNames } from '@/api/library'
import { Inbox } from '@/components/library/Inbox'
import { RecipeCard } from '@/components/library/RecipeCard'
import { SaveLinkForm } from '@/components/library/SaveLinkForm'
import { Wordmark } from '@/components/brand'
import { EmptyState, pageTitleClass } from '@/components/PageHeader'
import { Button, Chip, inputClass, linkButtonClass } from '@/components/ui'
import { cn } from '@/lib/utils'
import { useDebounced } from '@/lib/useDebounced'

export function LibraryPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()

  // Filters live in the URL so going back from a recipe keeps them.
  const tags = params.getAll('tag')
  const favorite = params.get('fav') === '1'
  const needsReview = params.get('review') === '1'
  const [query, setQuery] = useState(params.get('q') ?? '')
  const debouncedQuery = useDebounced(query)

  useEffect(() => {
    setParams(
      (current) => {
        const next = new URLSearchParams(current)
        if (debouncedQuery.trim()) next.set('q', debouncedQuery)
        else next.delete('q')
        return next
      },
      { replace: true },
    )
  }, [debouncedQuery, setParams])

  // A new object each render is fine: query keys are compared by value.
  const filters: RecipeFilters = { q: debouncedQuery, tags, favorite, needsReview }
  const recipes = useRecipes(filters)
  const allTags = useTagNames()
  const reviewCount = useReviewCount()
  const filtering = Boolean(filters.q.trim() || tags.length || favorite || needsReview)

  const toggleTag = (tag: string) =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      const selected = next.getAll('tag')
      next.delete('tag')
      for (const t of selected.includes(tag) ? selected.filter((s) => s !== tag) : [...selected, tag]) next.append('tag', t)
      return next
    })

  const toggleFlag = (key: 'fav' | 'review') =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      if (next.get(key) === '1') next.delete(key)
      else next.set(key, '1')
      return next
    })

  const clearFilters = () => {
    setQuery('')
    setParams(new URLSearchParams(), { replace: true })
  }

  // Selected tags first (even if no recipe has them any more), then the rest by use.
  const tagChips = [...tags, ...allTags.filter((tag) => !tags.includes(tag))]

  return (
    <>
      <div className="mb-2 flex items-center justify-between gap-2">
        <Wordmark className="text-[1.875rem]" />
        <Link to="/recipes/new" className={linkButtonClass}>
          <Plus className="size-4" aria-hidden />
          {t('library.newRecipe')}
        </Link>
      </div>
      <h1 className={cn('mb-4', pageTitleClass)}>{t('library.title')}</h1>

      <SaveLinkForm />
      <Inbox />

      <section aria-label={t('library.recipes')} className="flex flex-col gap-3">
        <label className="relative">
          <span className="sr-only">{t('library.search')}</span>
          <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <input
            type="search"
            enterKeyHint="search"
            placeholder={t('library.search')}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            className={cn(inputClass, 'pr-10 pl-10 [&::-webkit-search-cancel-button]:hidden')}
          />
          {query && (
            <button
              type="button"
              onClick={() => setQuery('')}
              className="absolute top-1/2 right-1 grid size-9 -translate-y-1/2 place-items-center text-muted-foreground"
              aria-label={t('library.clearSearch')}
            >
              <X className="size-5" />
            </button>
          )}
        </label>

        <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
          {(reviewCount > 0 || needsReview) && (
            <Chip active={needsReview} onClick={() => toggleFlag('review')}>
              <ListChecks className="size-4" aria-hidden />
              {t('library.toReview', { count: reviewCount })}
            </Chip>
          )}
          <Chip active={favorite} onClick={() => toggleFlag('fav')}>
            <Heart className={cn('size-4', favorite && 'fill-current')} aria-hidden />
            {t('library.favorites')}
          </Chip>
          {tagChips.map((tag) => (
            <Chip key={tag} active={tags.includes(tag)} onClick={() => toggleTag(tag)}>
              {tag}
            </Chip>
          ))}
        </div>

        {recipes.isPending ? (
          <RecipeGridSkeleton />
        ) : recipes.isError ? (
          <EmptyState>{t('library.loadError')}</EmptyState>
        ) : recipes.data.length === 0 ? (
          filtering ? (
            <EmptyState
              title={t('library.noMatches')}
              action={
                <Button variant="secondary" size="sm" onClick={clearFilters}>
                  {t('library.clearFilters')}
                </Button>
              }
            >
              {t('library.noMatchesHint')}
            </EmptyState>
          ) : (
            <EmptyState
              title={t('library.emptyTitle')}
              action={
                <Link to="/recipes/new" className={linkButtonClass}>
                  <PenLine className="size-4" aria-hidden />
                  {t('library.writeOne')}
                </Link>
              }
            >
              {t('library.empty')}
            </EmptyState>
          )
        ) : (
          <ul className={cn('grid grid-cols-2 gap-3 sm:grid-cols-3', recipes.isPlaceholderData && 'opacity-60')}>
            {recipes.data.map((recipe) => (
              <li key={recipe.id} className="flex">
                <RecipeCard recipe={recipe} />
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  )
}

function RecipeGridSkeleton() {
  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-hidden>
      {Array.from({ length: 4 }, (_, i) => (
        <li key={i} className="overflow-hidden rounded-lg sticker bg-card">
          <div className="aspect-[4/3] animate-pulse border-b-2 border-outline bg-muted" />
          <div className="m-2.5 h-4 w-3/4 animate-pulse rounded bg-muted" />
        </li>
      ))}
    </ul>
  )
}
