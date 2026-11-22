import { ChevronDown, CloudOff, LoaderCircle } from 'lucide-react'
import { type FormEvent, useState, useSyncExternalStore } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { AISLES, type ListScope, type ShoppingItem, useAddItem, usePendingShoppingChanges, useShoppingList } from '@/api/shopping'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { ItemRow } from '@/components/shopping/ItemRow'
import { Button, Chip, inputClass, linkButtonClass } from '@/components/ui'
import { WeekNav } from '@/components/WeekNav'
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

/** The week's shopping list, from its menu: by aisle, staples apart, ticked off live and offline. */
export function ShoppingPage() {
  const { t } = useTranslation()
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
  const toBuy = items.filter((i) => !i.staple && !i.manual)
  const manual = items.filter((i) => i.manual)
  const staples = items.filter((i) => i.staple && !i.manual)
  const left = [...toBuy, ...manual].filter((i) => !i.checked).length
  const byAisle = AISLES.map((aisle) => [aisle, toBuy.filter((i) => i.aisle === aisle)] as const).filter(([, list]) => list.length)

  return (
    <>
      <PageHeader title={t('shopping.title')} />
      <WeekNav {...nav} />

      {(!online || pending > 0) && (
        <p className="mb-4 flex items-center gap-2 rounded-md border-2 border-dashed border-muted-foreground/60 bg-muted px-3 py-2 text-sm font-semibold" role="status">
          {online ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <CloudOff className="size-4" aria-hidden />}
          {online ? t('shopping.sending') : t('shopping.offline')}
        </p>
      )}

      {isPending ? null : isError && !list ? (
        <EmptyState>{t('shopping.loadError')}</EmptyState>
      ) : (
        <div className="flex flex-col gap-5">
          {list && !list.planned ? (
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
            <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-muted-foreground">
              <span className="flex-1">{t('shopping.summary', { count: left, meals: list?.meals ?? 0 })}</span>
              {canNarrow && (
                <Chip active={fromToday} onClick={() => setFromToday(!fromToday)}>
                  {t('shopping.fromToday')}
                </Chip>
              )}
            </div>
          )}
          {list?.classifying && (
            <p className="-mt-2 flex items-center gap-2 text-xs text-muted-foreground">
              <LoaderCircle className="size-3.5 animate-spin" aria-hidden />
              {t('shopping.classifying')}
            </p>
          )}

          {byAisle.map(([aisle, aisleItems]) => (
            <Section key={aisle} title={t(`shopping.aisle.${aisle}`)} items={aisleItems} scope={scope} />
          ))}

          <section aria-labelledby="manual-title">
            <h2 id="manual-title" className="mb-2 text-lg font-extrabold">
              {t('shopping.extra')}
            </h2>
            {manual.length > 0 && (
              <ul className="mb-2 rounded-lg sticker bg-card px-3">
                {manual.map((item) => (
                  <ItemRow key={item.key} item={item} scope={scope} />
                ))}
              </ul>
            )}
            <AddItem scope={scope} />
          </section>

          {staples.length > 0 && (
            <details className="group rounded-lg sticker bg-card px-3.5 py-2.5">
              <summary className="flex cursor-pointer list-none items-center gap-3 py-1 [&::-webkit-details-marker]:hidden">
                <span className="flex-1">
                  <span className="block font-extrabold">{t('shopping.staples', { count: staples.length })}</span>
                  <span className="block text-sm text-muted-foreground">{t('shopping.staplesHint')}</span>
                </span>
                <ChevronDown className="size-5 transition-transform group-open:rotate-180" aria-hidden />
              </summary>
              <ul className="mt-1">
                {staples.map((item) => (
                  <ItemRow key={item.key} item={item} scope={scope} />
                ))}
              </ul>
            </details>
          )}
        </div>
      )}
    </>
  )
}

function Section({ title, items, scope }: { title: string; items: ShoppingItem[]; scope: ListScope }) {
  const left = items.filter((i) => !i.checked).length
  return (
    <section>
      <h2 className="mb-2 flex items-baseline justify-between text-lg font-extrabold">
        {title}
        <span className="text-sm font-bold text-muted-foreground tabular-nums">{left || '✓'}</span>
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

function AddItem({ scope }: { scope: ListScope }) {
  const { t } = useTranslation()
  const add = useAddItem()
  const [text, setText] = useState('')

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (!text.trim()) return
    add.mutate({ ...scope, text: text.trim(), tempKey: `pending:${++pendingCounter}` })
    setText('')
  }

  return (
    <form onSubmit={submit} className="flex gap-2">
      <input
        value={text}
        onChange={(e) => setText(e.target.value)}
        maxLength={200}
        placeholder={t('shopping.addPlaceholder')}
        aria-label={t('shopping.addPlaceholder')}
        className={inputClass}
      />
      <Button type="submit" disabled={!text.trim()}>
        {t('shopping.add')}
      </Button>
    </form>
  )
}
