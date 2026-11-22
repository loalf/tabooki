import { Check } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useRecipe } from '@/api/library'
import type { MealPlanEntry } from '@/api/plan'
import { type SwapReason, useSwapFeedback } from '@/api/taste'
import { Button, Chip } from '@/components/ui'
import { sortGroups } from '@/lib/facts'
import { stopAskingWhySwap } from '@/lib/swapWhy'

/**
 * After a meal was swapped: why? One tap, or skip (docs/PLAN.md §6.3). "We don't like it" asks
 * what: one of its food groups, an ingredient, or the whole dish.
 */
export function SwapWhy({ recipe, entry, onDone }: { recipe: { id: string; title: string }; entry: MealPlanEntry; onDone: () => void }) {
  const { t } = useTranslation()
  const send = useSwapFeedback()
  const [dislike, setDislike] = useState(false)
  const [thanks, setThanks] = useState<string | null>(null)
  const meal = t(`recipe.meal.${entry.slot ?? 'dinner'}`).toLowerCase()

  const answer = (reason: SwapReason, what?: { kind: 'group' | 'ingredient' | 'dish'; value?: string }) =>
    send.mutate(
      { recipe: recipe.id, reason, whatKind: what?.kind, whatValue: what?.value, slot: entry.slot ?? 'dinner', date: entry.date! },
      { onSuccess: () => setThanks(what?.kind === 'dish' ? t('plan.why.swap.thanksDish') : t('plan.why.swap.thanks')) },
    )
  const stopAsking = () => {
    stopAskingWhySwap()
    onDone()
  }

  if (thanks) {
    return (
      <div className="flex flex-col items-center gap-3 py-4 text-center">
        <Check className="size-8 text-primary" aria-hidden />
        <p className="font-bold">{thanks}</p>
        <Button className="self-stretch" onClick={onDone}>
          {t('plan.why.swap.done')}
        </Button>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <header>
        <h2 className="text-lg font-extrabold">{dislike ? t('plan.why.swap.whatTitle') : t('plan.why.swap.title')}</h2>
        <p className="text-sm text-muted-foreground">{dislike ? recipe.title : t('plan.why.swap.hint', { title: recipe.title })}</p>
      </header>
      {dislike ? (
        <DislikeWhat recipeId={recipe.id} disabled={send.isPending} onPick={(what) => answer('dislike', what)} />
      ) : (
        <div className="flex flex-wrap gap-2">
          <Chip disabled={send.isPending} onClick={() => answer('notToday')}>{t('plan.why.swap.notToday')}</Chip>
          <Chip disabled={send.isPending} onClick={() => setDislike(true)}>{t('plan.why.swap.dislike')}</Chip>
          <Chip disabled={send.isPending} onClick={() => answer('tooHeavy')}>{t('plan.why.swap.tooHeavy', { meal })}</Chip>
          <Chip disabled={send.isPending} onClick={() => answer('tooLight')}>{t('plan.why.swap.tooLight', { meal })}</Chip>
          <Chip disabled={send.isPending} onClick={() => answer('tooLong')}>{t('plan.why.swap.tooLong')}</Chip>
        </div>
      )}
      {send.isError && <p className="text-sm text-destructive">{t('plan.error')}</p>}
      <div className="flex items-center justify-between">
        <button type="button" onClick={stopAsking} className="h-9 text-sm font-semibold text-muted-foreground">
          {t('plan.why.swap.never')}
        </button>
        <Button variant="secondary" size="sm" onClick={onDone}>
          {t('plan.why.swap.skip')}
        </Button>
      </div>
    </div>
  )
}

/** Its food groups, its ingredients, or the whole dish. */
function DislikeWhat({ recipeId, disabled, onPick }: { recipeId: string; disabled: boolean; onPick: (what: { kind: 'group' | 'ingredient' | 'dish'; value?: string }) => void }) {
  const { t } = useTranslation()
  const { data: recipe } = useRecipe(recipeId)
  const groups = sortGroups(recipe?.foodGroups ?? [])
  const seen = new Set<string>()
  const ingredients = (recipe?.ingredients ?? []).filter((i) => {
    if (i.optional || !i.canonicalKey || seen.has(i.canonicalKey)) return false
    seen.add(i.canonicalKey)
    return true
  })

  return (
    <div className="flex flex-col gap-3">
      <Chip disabled={disabled} onClick={() => onPick({ kind: 'dish' })} className="self-start">
        {t('plan.why.swap.wholeDish')}
      </Chip>
      {groups.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {groups.map((group) => (
            <Chip key={group} disabled={disabled} onClick={() => onPick({ kind: 'group', value: group })}>
              {t(`recipe.facts.group.${group}`)}
            </Chip>
          ))}
        </div>
      )}
      {ingredients.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {ingredients.slice(0, 12).map((i) => (
            <Chip key={i.canonicalKey} disabled={disabled} onClick={() => onPick({ kind: 'ingredient', value: i.canonicalKey! })}>
              {i.name ?? i.rawText}
            </Chip>
          ))}
        </div>
      )}
    </div>
  )
}
