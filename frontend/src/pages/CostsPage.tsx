import { AlertTriangle, ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { type CostCall, type Costs, formatUsd, useCosts } from '@/api/costs'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { cn } from '@/lib/utils'

/** What extraction and ingredient sorting have cost: this month, the last six, by step and model. */
export function CostsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data, isPending, isError } = useCosts()

  return (
    <>
      <button type="button" onClick={() => void navigate('/settings')} className="mb-3 inline-flex h-9 items-center gap-1 text-sm font-medium text-muted-foreground">
        <ArrowLeft className="size-4" aria-hidden />
        {t('settings.title')}
      </button>
      <PageHeader title={t('costs.title')} />
      {isPending ? (
        <div className="h-40 animate-pulse rounded-lg bg-muted" aria-hidden />
      ) : isError || !data ? (
        <EmptyState>{t('costs.loadError')}</EmptyState>
      ) : (
        <CostsView costs={data} />
      )}
    </>
  )
}

function CostsView({ costs }: { costs: Costs }) {
  const { t, i18n } = useTranslation()
  const usd = (value: number) => formatUsd(value, i18n.language)
  const allTime = costs.allTime!

  if (!allTime.calls) return <EmptyState>{t('costs.none')}</EmptyState>

  return (
    <div className="flex flex-col gap-5">
      <section className="grid grid-cols-2 gap-3" aria-label={t('costs.summary')}>
        <div className="col-span-2 rounded-lg border bg-card p-4">
          <p className="text-sm text-muted-foreground">{t('costs.thisMonth')}</p>
          <p className="text-3xl font-semibold tabular-nums">{usd(costs.thisMonth!.usd!)}</p>
          <p className="text-xs text-muted-foreground">{t('costs.calls', { count: costs.thisMonth!.calls })}</p>
        </div>
        <Tile label={t('costs.lastMonth')} value={usd(costs.lastMonth!.usd!)} />
        <Tile label={t('costs.allTime')} value={usd(allTime.usd!)} />
        <Tile
          label={t('costs.perLink')}
          value={costs.perLink != null ? usd(costs.perLink) : '–'}
          hint={t('costs.links', { count: costs.linksExtracted })}
          className="col-span-2"
        />
      </section>

      {!!costs.unpricedCalls && (
        <p className="flex gap-2 rounded-md bg-muted px-3 py-2 text-sm">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          {t('costs.unpriced', { count: costs.unpricedCalls })}
        </p>
      )}

      <MonthChart months={costs.months ?? []} />

      <Breakdown title={t('costs.byStep')} groups={(costs.byStep ?? []).map((g) => ({ ...g, label: t(`costs.step.${g.name}`, { defaultValue: g.name }) }))} />
      <Breakdown title={t('costs.byModel')} groups={(costs.byModel ?? []).map((g) => ({ ...g, label: g.name ?? '' }))} mono />

      <section aria-labelledby="costs-recent">
        <h2 id="costs-recent" className="mb-1 font-semibold">
          {t('costs.recent')}
        </h2>
        <ul className="divide-y rounded-lg border bg-card">
          {(costs.recent ?? []).map((call, i) => (
            <RecentCall key={i} call={call} />
          ))}
        </ul>
      </section>

      <p className="text-xs text-muted-foreground">{t('costs.footnote')}</p>
    </div>
  )
}

function Tile({ label, value, hint, className }: { label: string; value: string; hint?: string; className?: string }) {
  return (
    <div className={cn('rounded-lg border bg-card p-3', className)}>
      <p className="text-sm text-muted-foreground">{label}</p>
      <p className="text-xl font-semibold tabular-nums">{value}</p>
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

/**
 * Six months as bars (one series, so no legend: the title names it). Tapping a bar shows its
 * value; the current month is labeled. A hidden table carries the same numbers for screen readers.
 */
function MonthChart({ months }: { months: NonNullable<Costs['months']> }) {
  const { t, i18n } = useTranslation()
  const [active, setActive] = useState<number | null>(null)
  const max = Math.max(...months.map((m) => m.usd ?? 0), 0)
  const monthName = (month: string) =>
    new Intl.DateTimeFormat(i18n.language, { month: 'short', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`))
  const current = months.length - 1
  const shown = active ?? current

  return (
    <section aria-labelledby="costs-months">
      <h2 id="costs-months" className="mb-1 font-semibold">
        {t('costs.months')}
      </h2>
      <div className="rounded-lg border bg-card p-3">
        <p className="mb-2 h-5 text-sm tabular-nums" aria-hidden>
          <span className="font-medium capitalize">{monthName(months[shown]?.month ?? '')}</span>{' '}
          <span className="text-muted-foreground">
            {formatUsd(months[shown]?.usd ?? 0, i18n.language)} · {t('costs.calls', { count: months[shown]?.calls ?? 0 })}
          </span>
        </p>
        <div className="flex h-32 items-end gap-0.5 border-b border-border" aria-hidden>
          {months.map((m, i) => (
            <button
              key={m.month}
              type="button"
              tabIndex={-1}
              onPointerEnter={() => setActive(i)}
              onPointerLeave={() => setActive(null)}
              onClick={() => setActive(i)}
              className="flex h-full flex-1 items-end justify-center px-1.5"
            >
              <span
                className={cn('w-full rounded-t-[4px] bg-primary transition-opacity', shown !== i && 'opacity-60')}
                style={{ height: max > 0 ? `${Math.max(((m.usd ?? 0) / max) * 100, (m.usd ?? 0) > 0 ? 2 : 0)}%` : 0 }}
              />
            </button>
          ))}
        </div>
        <div className="mt-1 flex gap-0.5 text-center text-xs text-muted-foreground capitalize" aria-hidden>
          {months.map((m) => (
            <span key={m.month} className="flex-1">
              {monthName(m.month ?? '')}
            </span>
          ))}
        </div>
        <table className="sr-only">
          <caption>{t('costs.months')}</caption>
          <tbody>
            {months.map((m) => (
              <tr key={m.month}>
                <th scope="row">{monthName(m.month ?? '')}</th>
                <td>{formatUsd(m.usd ?? 0, i18n.language)}</td>
                <td>{t('costs.calls', { count: m.calls ?? 0 })}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

type Group = NonNullable<Costs['byStep']>[number] & { label: string }

function Breakdown({ title, groups, mono = false }: { title: string; groups: Group[]; mono?: boolean }) {
  const { t, i18n } = useTranslation()
  if (!groups.length) return null
  return (
    <section>
      <h2 className="mb-1 font-semibold">{title}</h2>
      <ul className="divide-y rounded-lg border bg-card">
        {groups.map((g) => (
          <li key={g.name} className="flex items-baseline gap-3 px-3 py-2.5">
            <div className="min-w-0 flex-1">
              <p className={cn('truncate', mono ? 'font-mono text-sm' : 'font-medium')}>{g.label}</p>
              <p className="text-xs text-muted-foreground">
                {t('costs.calls', { count: g.calls })}
                {!!g.failed && ` · ${t('costs.failed', { count: g.failed })}`}
                {' · '}
                {t('costs.tokens', {
                  input: new Intl.NumberFormat(i18n.language, { notation: 'compact' }).format(g.inputTokens ?? 0),
                  output: new Intl.NumberFormat(i18n.language, { notation: 'compact' }).format(g.outputTokens ?? 0),
                })}
              </p>
            </div>
            <span className="font-medium tabular-nums">{formatUsd(g.usd ?? 0, i18n.language)}</span>
          </li>
        ))}
      </ul>
    </section>
  )
}

function RecentCall({ call }: { call: CostCall }) {
  const { t, i18n } = useTranslation()
  const when = new Intl.DateTimeFormat(i18n.language, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(call.at!))
  const what = call.sourceTitle ?? call.sourceUrl ?? (call.step === 'classify' ? t('costs.classifyWhat') : t('costs.deletedLink'))
  return (
    <li className="flex items-baseline gap-3 px-3 py-2.5">
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">
          {call.recipeId ? (
            <Link to={`/recipes/${call.recipeId}`} className="hover:underline">
              {what}
            </Link>
          ) : (
            what
          )}
        </p>
        <p className="truncate text-xs text-muted-foreground">
          {when} · {t(`costs.step.${call.step}`, { defaultValue: call.step })}
          {!call.ok && <span className="text-destructive"> · {call.error ? t('costs.failedWith', { error: call.error }) : t('costs.failed', { count: 1 })}</span>}
        </p>
      </div>
      <span className="text-sm tabular-nums">{call.usd != null ? formatUsd(call.usd, i18n.language) : '–'}</span>
    </li>
  )
}
