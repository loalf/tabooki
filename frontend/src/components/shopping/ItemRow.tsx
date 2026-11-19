import { Check, MoreHorizontal, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  AISLES,
  type Aisle,
  type ListScope,
  type ShoppingItem,
  useCheckItem,
  useRemoveItem,
  useUpdateIngredient,
} from '@/api/shopping'
import { formatAmount } from '@/components/shopping/format'
import { Button } from '@/components/ui'
import { cn } from '@/lib/utils'

/** One line: a big tap target to tick it off, and a way to fix where it goes. */
export function ItemRow({ item, scope }: { item: ShoppingItem; scope: ListScope }) {
  const { t, i18n } = useTranslation()
  const check = useCheckItem()
  const remove = useRemoveItem()
  const update = useUpdateIngredient()
  const [open, setOpen] = useState(false)
  const amounts = (item.amounts ?? []).map((a) => formatAmount(a, t, i18n.language)).join(' + ')
  const pending = item.key.startsWith('pending:')

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
          <span
            className={cn(
              'grid size-6 shrink-0 place-items-center rounded-md border-2',
              item.checked ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-card',
            )}
            aria-hidden
          >
            {item.checked && <Check className="size-4" strokeWidth={3} />}
          </span>
          <span className={cn('min-w-0 flex-1', item.checked && 'text-muted-foreground line-through')}>
            <span className="font-medium">{item.name.charAt(0).toLocaleUpperCase(i18n.language) + item.name.slice(1)}</span>
            {amounts && <span className="ml-2 text-sm text-muted-foreground tabular-nums">{amounts}</span>}
            {!!item.recipes?.length && <span className="block truncate text-xs text-muted-foreground no-underline">{item.recipes.join(' · ')}</span>}
          </span>
        </button>
        {item.manual ? (
          <Button variant="ghost" size="icon" disabled={pending || remove.isPending} aria-label={t('shopping.removeItem', { name: item.name })} onClick={() => remove.mutate({ ...scope, key: item.key })}>
            <X className="size-4 text-muted-foreground" aria-hidden />
          </Button>
        ) : (
          item.ingredientId && (
            <Button variant="ghost" size="icon" aria-expanded={open} aria-label={t('shopping.fix', { name: item.name })} onClick={() => setOpen(!open)}>
              <MoreHorizontal className="size-4 text-muted-foreground" aria-hidden />
            </Button>
          )
        )}
      </div>

      {open && item.ingredientId && (
        <div className="mb-2 flex flex-wrap items-center gap-2 rounded-md bg-muted p-2 text-sm">
          <label className="flex items-center gap-2">
            {t('shopping.aisleLabel')}
            <select
              value={item.aisle}
              disabled={update.isPending}
              onChange={(e) => update.mutate({ id: item.ingredientId!, aisle: e.target.value as Aisle })}
              className="h-9 rounded-md border border-input bg-card px-2"
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
        </div>
      )}
    </li>
  )
}
