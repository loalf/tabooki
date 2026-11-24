import { ChevronDown, CloudOff, House, LoaderCircle, Plus, Share, ShoppingBasket } from 'lucide-react'
import { type FormEvent, useState, useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { AISLES, type ListScope, type ShoppingItem, useAddItem, usePendingShoppingChanges, useShoppingList } from '@/api/shopping'
import { EmptyState, pageTitleClass } from '@/components/PageHeader'
import { formatAmount } from '@/components/shopping/format'
import { ItemRow } from '@/components/shopping/ItemRow'
import { Chip, inputClass, linkButtonClass } from '@/components/ui'
import { WeekNav } from '@/components/WeekNav'
import { useAboveTabBar } from '@/lib/layout'
import { cn } from '@/lib/utils'
import { useWeekParam } from '@/lib/useWeekParam'
import { today } from '@/lib/week'

function useOnline() {
  return useSyncExternalStore(
    (onChange) => {
      window.addEventListener('online', onChange)
      window.addEventListener('offline', onChange)
      return () => {
        window.removeEventListener('online', onChange)
        window.removeEventListener('offline', onChange)
      }
    },
    () => navigator.onLine,
  )
}

/**
 * The week's shopping list, from its menu, laid out for the shop: what to check at home first,
 * then what's left by aisle (ticked things drop into the cart), and a quick add at thumb height.
 * Ticked off live with whoever else is shopping, and offline.
 */
export function ShoppingPage() {
  const { t, i18n } = useTranslation()
  const nav = useWeekParam()
  const now = today()
  // "From today" only makes sense in the current week, after Monday.
  const canNarrow = nav.week === nav.thisWeek && now !== nav.week
  const [fromToday, setFromToday] = useState(false)
  const scope: ListScope = { week: nav.week, from: canNarrow && fromToday ? now : undefined }
  const { data: list, isPending, isError } = useShoppingList(scope)
  const online = useOnline()
  const pending = usePendingShoppingChanges()

  const items = list?.items ?? []
  const staples = items.filter((i) => i.staple && !i.manual)
  const toBuy = items.filter((i) => !i.staple || i.manual)
  const open = toBuy.filter((i) => !i.checked)
  const inCart = toBuy.filter((i) => i.checked)
  const byAisle = AISLES.map((aisle) => [aisle, open.filter((i) => !i.manual && i.aisle === aisle)] as const).filter(([, list]) => list.length)
  const manual = open.filter((i) => i.manual)
  const planned = Boolean(list?.planned)

  const share = async () => {
    const text = open
      .map((i) => {
        const amounts = (i.amounts ?? []).map((a) => formatAmount(a, t, i18n.language)).join(' + ')
        return `• ${i.name}${amounts ? ` (${amounts})` : ''}`
      })
      .join('\n')
    try {
      if (navigator.share) await navigator.share({ title: t('shopping.title'), text })
      else await navigator.clipboard.writeText(text)
    } catch {
      // Cancelled.
    }
  }

  return (
    <>
      <div className="mb-4 flex items-center justify-between gap-2">
        <h1 className={pageTitleClass}>{t('shopping.title')}</h1>
        {open.length > 0 && (
          <button type="button" onClick={() => void share()} aria-label={t('shopping.share')} className="grid size-11 place-items-center rounded-full sticker-sm bg-card">
            <Share className="size-[18px]" aria-hidden />
          </button>
        )}
      </div>
      <WeekNav {...nav} />

      {(!online || pending > 0) && (
        <p className="mb-4 flex items-center gap-2 rounded-md border-2 border-dashed border-muted-foreground/60 bg-muted px-3 py-2 text-sm font-semibold" role="status">
          {online ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <CloudOff className="size-4" aria-hidden />}
          {online ? t('shopping.sending') : t('shopping.offline')}
        </p>
      )}

      {isPending ? (
        <ListSkeleton />
      ) : isError && !list ? (
        <EmptyState>{t('shopping.loadError')}</EmptyState>
      ) : !planned && !items.length ? (
        <EmptyState
          title={t('shopping.noPlanTitle')}
          action={
            <Link to={`/plan${nav.week === nav.thisWeek ? '' : `?week=${nav.week}`}`} className={linkButtonClass}>
              {t('shopping.toPlan')}
            </Link>
          }
        >
          {t('shopping.noPlan')}
        </EmptyState>
      ) : (
        <div className="flex flex-col gap-5">
          <div className="flex flex-col gap-2">
            <div className="flex items-end justify-between gap-3">
              <div>
                <p className="text-[17px] font-extrabold">{open.length ? t('shopping.left', { count: open.length }) : t('shopping.allDone')}</p>
                {planned && <p className="text-xs text-muted-foreground">{t('shopping.forMeals', { count: list?.meals ?? 0 })}</p>}
              </div>
              {toBuy.length > 0 && (
                <span className="text-sm font-bold text-primary tabular-nums">
                  {inCart.length} / {toBuy.length}
                </span>
              )}
            </div>
            {toBuy.length > 0 && (
              <div className="h-2.5 overflow-hidden rounded-full border-2 border-outline bg-muted" role="progressbar" aria-valuemin={0} aria-valuemax={toBuy.length} aria-valuenow={inCart.length}>
                <div className="h-full bg-primary transition-[width]" style={{ width: `${(inCart.length / toBuy.length) * 100}%` }} />
              </div>
            )}
            {canNarrow && (
              <Chip active={fromToday} onClick={() => setFromToday(!fromToday)} className="mt-1 self-start">
                {t('shopping.fromToday')}
              </Chip>
            )}
          </div>
          {list?.classifying && (
            <p className="-mt-2 flex items-center gap-2 text-xs text-muted-foreground">
              <LoaderCircle className="size-3.5 animate-spin" aria-hidden />
              {t('shopping.classifying')}
            </p>
          )}

          {staples.length > 0 && (
            <details className="group rounded-lg border-2 border-outline bg-accent px-3.5 py-2.5 text-accent-foreground">
              <summary className="flex cursor-pointer list-none items-center gap-3 py-1 [&::-webkit-details-marker]:hidden">
                <House className="size-5 shrink-0 text-brand" aria-hidden />
                <span className="flex-1">
                  <span className="block font-extrabold">{t('shopping.staples', { count: staples.length })}</span>
                  <span className="block text-sm opacity-80">{t('shopping.staplesHint')}</span>
                </span>
                <ChevronDown className="size-5 transition-transform group-open:rotate-180" aria-hidden />
              </summary>
              <ul className="mt-2 mb-1 rounded-md bg-card px-3 text-foreground">
                {staples.map((item) => (
                  <ItemRow key={item.key} item={item} scope={scope} />
                ))}
              </ul>
            </details>
          )}

          {byAisle.map(([aisle, aisleItems]) => (
            <Section key={aisle} title={t(`shopping.aisle.${aisle}`)} items={aisleItems} scope={scope} />
          ))}
          {manual.length > 0 && <Section title={t('shopping.extra')} items={manual} scope={scope} />}

          {inCart.length > 0 && (
            <details className="group rounded-md bg-muted px-3.5 py-2.5">
              <summary className="flex cursor-pointer list-none items-center gap-3 py-1 [&::-webkit-details-marker]:hidden">
                <ShoppingBasket className="size-5 shrink-0 text-primary" aria-hidden />
                <span className="min-w-0 flex-1">
                  <span className="block font-extrabold">{t('shopping.inCart', { count: inCart.length })}</span>
                  <span className="block truncate text-xs text-muted-foreground line-through group-open:hidden">{inCart.map((i) => i.name).join(', ')}</span>
                </span>
                <ChevronDown className="size-5 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
              </summary>
              <ul className="mt-2 mb-1 rounded-md bg-card px-3">
                {inCart.map((item) => (
                  <ItemRow key={item.key} item={item} scope={scope} />
                ))}
              </ul>
            </details>
          )}
          {/* Room for the quick add over the tab bar. */}
          <div className="h-14" aria-hidden />
        </div>
      )}

      <QuickAdd scope={scope} />
    </>
  )
}

/** Where the list will be, while it loads: what's left and its bar, then an aisle's heading and rows. */
function ListSkeleton() {
  return (
    <div className="flex flex-col gap-5" aria-hidden>
      <div className="flex flex-col gap-2">
        <div className="h-6 w-2/5 animate-pulse rounded-md bg-muted" />
        <div className="h-2.5 animate-pulse rounded-full bg-muted" />
      </div>
      <div className="flex flex-col gap-2">
        <div className="h-5 w-1/3 animate-pulse rounded-md bg-muted" />
        <div className="flex flex-col gap-1.5">
          {Array.from({ length: 6 }, (_, i) => (
            <div key={i} className="h-11 animate-pulse rounded-md bg-muted" />
          ))}
        </div>
      </div>
    </div>
  )
}

function Section({ title, items, scope }: { title: string; items: ShoppingItem[]; scope: ListScope }) {
  return (
    <section>
      <h2 className="mb-2 flex items-baseline gap-2 text-[15px] font-extrabold">
        {title}
        <span className="text-sm font-bold text-muted-foreground tabular-nums">{items.length}</span>
      </h2>
      <ul className="rounded-lg sticker bg-card px-3">
        {items.map((item) => (
          <ItemRow key={item.key} item={item} scope={scope} />
        ))}
      </ul>
    </section>
  )
}

let pendingCounter = 0

/** Add anything, from thumb height, like a chat box; it goes under "Además". */
function QuickAdd({ scope }: { scope: ListScope }) {
  const { t } = useTranslation()
  const add = useAddItem()
  const [text, setText] = useState('')
  const slot = useAboveTabBar()

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!text.trim()) return
    add.mutate({ ...scope, text: text.trim(), tempKey: `pending:${++pendingCounter}` })
    setText('')
  }

  if (!slot) return null
  return createPortal(
    <form onSubmit={submit} className="border-t-2 border-outline bg-background">
      <div className="mx-auto flex max-w-2xl gap-2 px-4 py-2.5">
      <input
        value={text}
        onChange={(e) => setText(e.target.value)}
        maxLength={200}
        enterKeyHint="done"
        placeholder={t('shopping.addPlaceholder')}
        aria-label={t('shopping.addPlaceholder')}
        className={cn(inputClass, 'h-11 rounded-full')}
      />
      <button type="submit" disabled={!text.trim()} aria-label={t('shopping.add')} className="grid size-11 shrink-0 place-items-center rounded-full sticker-sm bg-primary text-primary-foreground disabled:opacity-50">
        <Plus className="size-5" aria-hidden />
      </button>
      </div>
    </form>,
    slot,
  )
}
