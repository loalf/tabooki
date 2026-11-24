import { ListX, MoreHorizontal, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import {
  AISLES,
  type Aisle,
  type ListScope,
  type ShoppingItem,
  useAddItem,
  useCheckItem,
  useRemoveItem,
  useUpdateIngredient,
} from '@/api/shopping'
import { formatAmount } from '@/components/shopping/format'
import { Button, CheckMark } from '@/components/ui'
import { toast } from '@/lib/toast'
import { cn } from '@/lib/utils'

/**
 * One line, for scanning in the shop: what and how much, a big tap target to tick it off. Which
 * recipes it's for, what can stand in for it and where it goes are behind "…".
 */
export function ItemRow({ item, scope }: { item: ShoppingItem; scope: ListScope }) {
  const { t, i18n } = useTranslation()
  const check = useCheckItem()
  const remove = useRemoveItem()
  const update = useUpdateIngredient()
  const [open, setOpen] = useState(false)
  const amounts = (item.amounts ?? []).map((a) => formatAmount(a, t, i18n.language)).join(' + ')
  const pending = item.key.startsWith('pending:')
  const recipes = item.recipes ?? []
  const substitutes = item.substitutes ?? []
  const hasMore = Boolean(item.ingredientId || recipes.length || substitutes.length)
  const add = useAddItem()
  // A typed item is gone in one tap, so it can be put back: its name is what was typed.
  const removeTyped = () => {
    remove.mutate({ ...scope, key: item.key })
    toast({
      tone: 'success',
      icon: ListX,
      title: t('shopping.removed'),
      body: item.name,
      action: { label: t('common.undo'), undo: () => add.mutate({ ...scope, text: item.name, tempKey: `pending:undo-${Date.now()}` }) },
    })
  }

  return (
    <li className="border-b last:border-b-0">
      <div className="flex items-center gap-1">
        <button
          type="button"
          role="checkbox"
          aria-checked={item.checked}
          disabled={pending}
          onClick={() => check.mutate({ ...scope, key: item.key, checked: !item.checked })}
          className="flex min-h-12 min-w-0 flex-1 items-center gap-3 py-2 pl-1 text-left"
        >
          <CheckMark checked={Boolean(item.checked)} />
          <span className={cn('min-w-0 flex-1', item.checked && 'text-muted-foreground line-through opacity-60')}>
            <span className="font-semibold">{item.name.charAt(0).toLocaleUpperCase(i18n.language) + item.name.slice(1)}</span>
            {amounts && <span className="ml-2 text-sm text-muted-foreground tabular-nums">{amounts}</span>}
          </span>
        </button>
        {item.manual ? (
          <Button variant="ghost" size="icon" disabled={pending || remove.isPending} aria-label={t('shopping.removeItem', { name: item.name })} onClick={removeTyped}>
            <X className="size-4 text-muted-foreground" aria-hidden />
          </Button>
        ) : (
          hasMore && (
            <Button variant="ghost" size="icon" aria-expanded={open} aria-label={t('shopping.fix', { name: item.name })} onClick={() => setOpen(!open)}>
              <MoreHorizontal className="size-4 text-muted-foreground" aria-hidden />
            </Button>
          )
        )}
      </div>

      {open && hasMore && (
        <div className="mb-2 flex flex-wrap items-center gap-2 rounded-sm bg-muted p-2 text-sm">
          {(recipes.length > 0 || substitutes.length > 0) && (
            <div className="flex basis-full flex-col gap-0.5 px-0.5 pb-1">
              {recipes.length > 0 && (
                <p>
                  <span className="font-semibold">{t('shopping.forRecipes')}</span> {recipes.join(' · ')}
                </p>
              )}
              {substitutes.length > 0 && <p>{t('shopping.substitutes', { what: substitutes.join(', ') })}</p>}
            </div>
          )}
          {item.ingredientId && <IngredientControls item={item} update={update} />}
        </div>
      )}
    </li>
  )
}

/** Where the ingredient goes in the shop, whether it's always at home, and its page. */
function IngredientControls({ item, update }: { item: ShoppingItem; update: ReturnType<typeof useUpdateIngredient> }) {
  const { t } = useTranslation()
  return (
    <>
      <label className="flex items-center gap-2">
        {t('shopping.aisleLabel')}
        <select
          value={item.aisle}
          disabled={update.isPending}
          onChange={(e) => update.mutate({ id: item.ingredientId!, aisle: e.target.value as Aisle })}
          className="h-9 rounded-sm border-2 border-input bg-card px-2"
        >
          {AISLES.map((aisle) => (
            <option key={aisle} value={aisle}>
              {t(`shopping.aisle.${aisle}`)}
            </option>
          ))}
        </select>
      </label>
      <Button variant="secondary" size="sm" disabled={update.isPending} onClick={() => update.mutate({ id: item.ingredientId!, staple: !item.staple })}>
        {item.staple ? t('shopping.notStaple') : t('shopping.makeStaple')}
      </Button>
      <Link to={`/settings/ingredients/${item.ingredientId}`} className="inline-flex h-9 items-center px-2 font-semibold text-primary underline-offset-2 hover:underline">
        {t('shopping.aboutIngredient')}
      </Link>
    </>
  )
}
