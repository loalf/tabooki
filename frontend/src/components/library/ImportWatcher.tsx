import { useQueryClient } from '@tanstack/react-query'
import { type TFunction } from 'i18next'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { hostOf, isWorking, type RecipeListItem, type Source, useInbox } from '@/api/library'
import { toast } from '@/lib/toast'

/**
 * Follows the links being extracted from anywhere in the app (it keeps the inbox polled while
 * one is) and says when one is done: a toast to open the new recipe, or to go and help when it
 * failed or needs the recipe text. Only what finishes while the app is open is announced.
 */
export function ImportWatcher() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { data: sources } = useInbox()
  const previous = useRef<Map<string, Source> | null>(null)

  useEffect(() => {
    if (!sources) return
    const before = previous.current
    const now = new Map(sources.map((s) => [s.id!, s]))
    previous.current = now
    if (!before) return

    const left: Source[] = []
    for (const [id, old] of before) {
      const current = now.get(id)
      if (!current) {
        left.push(old)
      } else if (wasExtracting(old) && (current.status === 'failed' || current.status === 'needs_input')) {
        announceStuck(current, t)
      }
    }
    if (!left.length) return

    // A link leaving the inbox usually means it just became a recipe: show it in the lists.
    void queryClient.invalidateQueries({ queryKey: ['recipes'] })
    void queryClient.invalidateQueries({ queryKey: ['recipe-tags'] })
    // Deleted links and recipes written by hand leave too: only extractions that made a recipe count.
    const extracted = left.filter(wasExtracting)
    if (extracted.length) void announceRecipes(extracted, t)
  }, [sources, queryClient, t])

  return null
}

/** Was being worked on by the extraction worker (not a link sitting there since before). */
function wasExtracting(source: Source): boolean {
  return (source.status === 'pending' || source.status === 'extracting') && isWorking(source)
}

function sourceName(source: Source): string {
  return source.title ?? hostOf(source.url)
}

function announceStuck(source: Source, t: TFunction) {
  toast({
    tone: 'error',
    title: source.status === 'failed' ? t('library.imported.failed') : t('library.imported.needsInput'),
    body: sourceName(source),
    action: { label: t('library.imported.see'), to: '/recipes?inbox=1' },
  })
}

async function announceRecipes(sources: Source[], t: TFunction) {
  const { data: recipes } = await api.GET('/api/recipes')
  if (!recipes) return
  for (const source of sources) {
    const made = recipes.filter((r: RecipeListItem) => r.source?.id === source.id)
    if (!made.length) continue
    if (made.length === 1) {
      const [recipe] = made
      toast({
        tone: 'success',
        title: recipe.needsReview ? t('library.imported.readyToReview') : t('library.imported.ready'),
        body: recipe.title,
        action: { label: t('library.imported.open'), to: `/recipes/${recipe.id}` },
      })
    } else {
      toast({
        tone: 'success',
        title: t('library.imported.readyMany', { count: made.length }),
        body: sourceName(source),
        action: { label: t('library.imported.see'), to: '/recipes' },
      })
    }
  }
}
