import { Search, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { useRecipes } from '@/api/library'
import type { Slot } from '@/api/plan'
import { Thumbnail } from '@/components/library/Thumbnail'
import { Button, inputClass } from '@/components/ui'
import { useDebounced } from '@/lib/useDebounced'

/** Full-screen list of the library to choose a meal's recipe by hand. Recipes for that meal first. */
export function RecipePicker({ slot, onPick, onClose }: { slot: Slot; onPick: (id: string) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const query = useDebounced(q, 250)
  const { data: recipes = [], isPending } = useRecipes({ q: query, tags: [], favorite: false })

  // Escape closes it; the page behind doesn't scroll while it's open.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && onClose()
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
    }
  }, [onClose])

  const suits = (types?: string[]) => !types?.length || types.includes(slot)
  const sorted = [...recipes].sort((a, b) => Number(suits(b.mealTypes)) - Number(suits(a.mealTypes)))

  // In a portal: the meal row may be inside a dimmed (translucent) day, which would show through.
  return createPortal(
    <div className="fixed inset-0 z-50 flex bg-black/30 sm:items-center sm:justify-center" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="picker-title"
        onClick={(event) => event.stopPropagation()}
        className="flex h-dvh w-full flex-col bg-background sm:h-[80dvh] sm:max-w-lg sm:rounded-lg sm:sticker"
      >
        <header className="flex items-center gap-2 border-b-2 border-outline p-3 pt-[max(0.75rem,env(safe-area-inset-top))]">
          <h2 id="picker-title" className="flex-1 text-lg font-extrabold">
            {t('plan.picker.title', { meal: t(`recipe.meal.${slot}`).toLowerCase() })}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label={t('common.cancel')}>
            <X className="size-5" aria-hidden />
          </Button>
        </header>
        <div className="relative p-3">
          <Search className="pointer-events-none absolute top-1/2 left-6 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <input
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            // oxlint-disable-next-line jsx-a11y/no-autofocus -- the reason the picker was opened
            autoFocus
            placeholder={t('plan.picker.search')}
            aria-label={t('plan.picker.search')}
            className={`${inputClass} pl-9`}
          />
        </div>
        <ul className="flex-1 overflow-y-auto px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          {!isPending && sorted.length === 0 && <li className="p-6 text-center text-sm text-muted-foreground">{t('plan.picker.empty')}</li>}
          {sorted.map((recipe) => (
            <li key={recipe.id}>
              <button
                type="button"
                onClick={() => onPick(recipe.id!)}
                className="flex w-full items-center gap-3 rounded-md p-2 text-left hover:bg-muted"
              >
                <Thumbnail src={recipe.source?.thumbnail} className="size-12 shrink-0 rounded-sm border-2 border-outline" />
                <span className="min-w-0 flex-1">
                  <span className="line-clamp-2 font-bold">{recipe.title}</span>
                  {!suits(recipe.mealTypes) && (
                    <span className="text-xs text-muted-foreground">
                      {t('plan.picker.otherMeal', { meal: t(`recipe.meal.${recipe.mealTypes?.[0] ?? 'lunch'}`).toLowerCase() })}
                    </span>
                  )}
                </span>
              </button>
            </li>
          ))}
        </ul>
      </div>
    </div>,
    document.body,
  )
}
