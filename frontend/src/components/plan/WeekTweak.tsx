import { Pencil, RotateCw, Sparkles, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { type MealPlan, useTweakWeek } from '@/api/plan'
import { Sheet } from '@/components/Sheet'
import { Button, textareaClass } from '@/components/ui'

/**
 * What's special about this week (docs/PLAN.md §6.3, the tweak box): the wish in force, or that
 * it couldn't be read, with a way to change it.
 */
export function WeekTweakBanner({ plan }: { plan: MealPlan }) {
  const { t, i18n } = useTranslation()
  const [editing, setEditing] = useState(false)
  const retry = useTweakWeek()
  if (!plan.tweak) return null
  const label = plan.tweakLabel?.[i18n.language.startsWith('en') ? 'en' : 'es']
  const unread = !plan.tweakRules

  return (
    <>
      <div className="flex items-start gap-2.5 rounded-md bg-accent px-3 py-2.5 text-sm text-accent-foreground">
        {unread ? <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden /> : <Sparkles className="mt-0.5 size-4 shrink-0" aria-hidden />}
        <span className="min-w-0 flex-1">
          <span className="font-bold">{unread ? t('plan.tweak.unread') : t('plan.tweak.thisWeek')}</span> {unread ? `«${plan.tweak}»` : (label ?? plan.tweak)}
        </span>
        {unread ? (
          <button type="button" className="inline-flex shrink-0 items-center gap-1 font-bold" disabled={retry.isPending} onClick={() => retry.mutate({ planId: plan.id!, tweak: plan.tweak! })}>
            <RotateCw className="size-4" aria-hidden />
            {t('plan.tweak.retry')}
          </button>
        ) : (
          <button type="button" className="grid size-7 shrink-0 place-items-center" aria-label={t('plan.tweak.change')} onClick={() => setEditing(true)}>
            <Pencil className="size-4" aria-hidden />
          </button>
        )}
      </div>
      {editing && <WeekTweakSheet plan={plan} onClose={() => setEditing(false)} />}
    </>
  )
}

/** Write, change or drop this week's wish; the week is planned again (locked meals stay). */
export function WeekTweakSheet({ plan, onClose }: { plan: MealPlan; onClose: () => void }) {
  const { t } = useTranslation()
  const [text, setText] = useState(plan.tweak ?? '')
  const tweak = useTweakWeek()
  const apply = (value: string | null) => tweak.mutate({ planId: plan.id!, tweak: value }, { onSuccess: onClose })

  return (
    <Sheet title={t('plan.tweak.title')} onClose={onClose}>
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{t('plan.tweak.hint')}</p>
        <textarea
          rows={3}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder={t('plan.tweak.placeholder')}
          aria-label={t('plan.tweak.title')}
          maxLength={500}
          className={textareaClass}
        />
        {tweak.isError && <p className="text-sm text-destructive">{t('plan.error')}</p>}
        <Button disabled={tweak.isPending || !text.trim()} onClick={() => apply(text)}>
          {tweak.isPending ? t('plan.tweak.applying') : t('plan.tweak.apply')}
        </Button>
        {plan.tweak && (
          <Button variant="secondary" disabled={tweak.isPending} onClick={() => apply(null)}>
            {t('plan.tweak.clear')}
          </Button>
        )}
      </div>
    </Sheet>
  )
}
