import { ArrowLeft, CalendarSync, Info, LoaderCircle, Sparkles, TriangleAlert } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { useMealPlan, useRegenerateMealPlan } from '@/api/plan'
import {
  type TasteAnswers,
  type TasteDraft,
  type TasteImpact,
  type TasteProfile,
  type TasteRules,
  useForgetTaste,
  useSaveTaste,
  useTasteDraft,
  useTasteImpact,
  useTasteProfile,
} from '@/api/taste'
import { EmptyState, PageHeader } from '@/components/PageHeader'
import { Questionnaire } from '@/components/taste/Questionnaire'
import { RuleList } from '@/components/taste/RuleList'
import { Suggestions } from '@/components/taste/Suggestions'
import { Button, Chip } from '@/components/ui'
import { impactWarnings } from '@/lib/taste'
import { cn } from '@/lib/utils'
import { mondayOf, today } from '@/lib/week'

type Stage =
  | { kind: 'view' }
  | { kind: 'questions' }
  | { kind: 'followUps'; answers: TasteAnswers; draft: TasteDraft }
  | { kind: 'review'; answers: TasteAnswers; draft: TasteDraft }
  | { kind: 'saved' }

/** /settings/taste: what the household likes (docs/PLAN.md §6.1). */
export function TastePage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const profile = useTasteProfile()

  return (
    <>
      <button type="button" onClick={() => void navigate('/settings')} className="mb-3 inline-flex h-9 items-center gap-1 text-[15px] font-bold">
        <ArrowLeft className="size-4" aria-hidden />
        {t('settings.title')}
      </button>
      <PageHeader title={t('taste.title')} />
      {profile.isPending ? (
        <div className="h-40 animate-pulse rounded-lg bg-muted" aria-hidden />
      ) : profile.isError ? (
        <EmptyState>{t('taste.loadError')}</EmptyState>
      ) : (
        <TasteFlow profile={profile.data} />
      )}
    </>
  )
}

function TasteFlow({ profile }: { profile: TasteProfile | null }) {
  const { t } = useTranslation()
  const [stage, setStage] = useState<Stage>(profile ? { kind: 'view' } : { kind: 'questions' })
  const [started, setStarted] = useState(!!profile)
  const draft = useTasteDraft()
  const showProfile = useCallback(() => setStage({ kind: 'view' }), [])

  const compile = (answers: TasteAnswers) =>
    draft.mutate(answers, {
      onSuccess: (result) => setStage(result.questions?.length ? { kind: 'followUps', answers, draft: result } : { kind: 'review', answers, draft: result }),
    })

  // A suggestion taken before the questionnaire makes a profile too: show it.
  if (!started && profile && stage.kind === 'questions') {
    return <ProfileView profile={profile} onEdit={() => setStarted(true)} />
  }

  if (!started) {
    return (
      <div className="flex flex-col gap-5">
        <Suggestions />
        <section className="flex flex-col gap-4 rounded-lg sticker bg-card p-5">
          <Sparkles className="size-7 text-brand" aria-hidden />
          <h2 className="text-xl font-extrabold text-balance">{t('taste.intro.title')}</h2>
          <p className="text-[15px] leading-relaxed text-muted-foreground">{t('taste.intro.text')}</p>
          <Button onClick={() => setStarted(true)}>{t('taste.intro.start')}</Button>
        </section>
      </div>
    )
  }

  if (draft.isPending) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-lg sticker bg-card px-6 py-12 text-center" role="status">
        <LoaderCircle className="size-8 animate-spin text-primary" aria-hidden />
        <p className="text-lg font-extrabold">{t('taste.thinking')}</p>
        <p className="text-sm text-muted-foreground">{t('taste.thinkingHint')}</p>
      </div>
    )
  }

  switch (stage.kind) {
    case 'view':
      return <ProfileView profile={profile!} onEdit={() => setStage({ kind: 'questions' })} />
    case 'questions':
      return (
        <>
          {draft.isError && (
            <p className="mb-3 text-sm text-destructive" role="alert">
              {t('taste.draftError')}
            </p>
          )}
          <Questionnaire
            initial={(profile?.answers as TasteAnswers | undefined) ?? {}}
            onDone={compile}
            onCancel={profile ? () => setStage({ kind: 'view' }) : undefined}
          />
        </>
      )
    case 'followUps':
      return <FollowUps draft={stage.draft} onDone={(followUps) => compile({ ...stage.answers, followUps })} onSkip={() => setStage({ kind: 'review', answers: stage.answers, draft: stage.draft })} />
    case 'review':
      return <Review answers={stage.answers} draft={stage.draft} onBack={() => setStage({ kind: 'questions' })} onSaved={() => setStage({ kind: 'saved' })} />
    case 'saved':
      return <Saved onDone={showProfile} />
  }
}

/** What the model wasn't sure about: a few multiple-choice questions, once. */
function FollowUps({ draft, onDone, onSkip }: { draft: TasteDraft; onDone: (answers: { question: string; answer: string }[]) => void; onSkip: () => void }) {
  const { t } = useTranslation()
  const questions = draft.questions ?? []
  const [answers, setAnswers] = useState<Record<number, string>>({})
  const complete = questions.every((_, i) => answers[i])

  return (
    <section className="flex flex-col gap-5">
      <header>
        <h2 className="text-[1.375rem] leading-tight font-extrabold">{t('taste.followUps.title')}</h2>
        <p className="mt-1 text-sm text-muted-foreground">{t('taste.followUps.hint')}</p>
      </header>
      {questions.map((q, i) => (
        <fieldset key={q.question} className="flex flex-col gap-2">
          <legend className="mb-2 font-bold">{q.question}</legend>
          <div className="flex flex-wrap gap-2">
            {q.options.map((option) => (
              <Chip key={option} active={answers[i] === option} onClick={() => setAnswers({ ...answers, [i]: option })} className="h-auto min-h-9 py-1.5 whitespace-normal">
                {option}
              </Chip>
            ))}
          </div>
        </fieldset>
      ))}
      <div className="flex gap-2">
        <Button variant="secondary" className="flex-1" onClick={onSkip}>
          {t('taste.followUps.skip')}
        </Button>
        <Button className="flex-[2]" disabled={!complete} onClick={() => onDone(questions.map((q, i) => ({ question: q.question, answer: answers[i] })))}>
          {t('taste.q.next')}
        </Button>
      </div>
    </section>
  )
}

/** "This is what we understood": every rule with what it leaves out; remove what's wrong, then save. */
function Review({ answers, draft, onBack, onSaved }: { answers: TasteAnswers; draft: TasteDraft; onBack: () => void; onSaved: () => void }) {
  const { t, i18n } = useTranslation()
  const [rules, setRules] = useState<TasteRules>(draft.rules as TasteRules)
  const [notes, setNotes] = useState(draft.notes ?? null)
  const [impact, setImpact] = useState<TasteImpact | undefined>(draft.impact)
  // Written by the model for the rules it made: once one is removed, it no longer says what's left.
  const [summary, setSummary] = useState(draft.summary ?? null)
  const recount = useTasteImpact()
  const save = useSaveTaste()

  const change = (next: TasteRules) => {
    setRules(next)
    setSummary(null)
    recount.mutate(next, { onSuccess: setImpact })
  }
  const warnings = impactWarnings(impact, t)
  const language = i18n.language.startsWith('en') ? 'en' : 'es'

  return (
    <section className="flex flex-col gap-5">
      <header>
        <h2 className="text-[1.375rem] leading-tight font-extrabold">{t('taste.review.title')}</h2>
        <p className="mt-1 text-sm text-muted-foreground">{t('taste.review.hint')}</p>
      </header>
      {draft.fallback && (
        <p className="flex gap-2 rounded-lg bg-accent p-3 text-sm text-accent-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
          {t('taste.review.fallback')}
        </p>
      )}
      {summary ? (
        <p className="rounded-lg sticker bg-card p-4 text-[15px] leading-relaxed">{summary[language]}</p>
      ) : (
        draft.summary && <p className="text-sm text-muted-foreground">{t('taste.review.summaryLater')}</p>
      )}
      <div className="rounded-lg sticker bg-card px-4 py-1.5">
        <RuleList
          rules={rules}
          impact={impact}
          notes={notes}
          onChange={change}
          onNotesChange={(next) => {
            setNotes(next)
            setSummary(null)
          }}
        />
      </div>
      {warnings.length > 0 && (
        <ul className="flex flex-col gap-2">
          {warnings.map((warning) => (
            <li key={warning} className="flex gap-2 text-sm font-semibold text-destructive">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
              {warning}
            </li>
          ))}
        </ul>
      )}
      {impact && impact.unread > 0 && <p className="text-xs text-muted-foreground">{t('taste.review.unread', { count: impact.unread })}</p>}
      {save.isError && (
        <p className="text-sm text-destructive" role="alert">
          {t('taste.saveError')}
        </p>
      )}
      <div className="sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom))] -mx-4 flex gap-2 border-t-2 border-outline bg-background px-4 py-3">
        <Button variant="secondary" className="flex-1" onClick={onBack}>
          {t('taste.review.change')}
        </Button>
        <Button className="flex-[2]" disabled={save.isPending} onClick={() => save.mutate({ answers, rules, notes, summary }, { onSuccess: onSaved })}>
          {save.isPending ? t('common.saving') : t('common.save')}
        </Button>
      </div>
    </section>
  )
}

/** Saved: this week's menu was planned before; offer to plan it again with the new likings. */
function Saved({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const week = mondayOf(today())
  const plan = useMealPlan(week)
  const regenerate = useRegenerateMealPlan()

  const nothingToRedo = !plan.isPending && !plan.data?.id
  useEffect(() => {
    if (nothingToRedo) onDone()
  }, [nothingToRedo, onDone])

  if (plan.isPending || nothingToRedo) return null
  return (
    <section className="flex flex-col gap-4 rounded-lg sticker bg-card p-5">
      <CalendarSync className="size-7 text-primary" aria-hidden />
      <h2 className="text-xl font-extrabold">{t('taste.saved.title')}</h2>
      <p className="text-[15px] text-muted-foreground">{t('taste.saved.text')}</p>
      <div className="flex gap-2">
        <Button variant="secondary" className="flex-1" onClick={onDone}>
          {t('taste.saved.notNow')}
        </Button>
        <Button className="flex-[2]" disabled={regenerate.isPending} onClick={() => regenerate.mutate(plan.data!.id!, { onSuccess: () => void navigate('/plan') })}>
          {t('taste.saved.redo')}
        </Button>
      </div>
    </section>
  )
}

/** The likings as saved: the summary, every rule, who changed them last. */
function ProfileView({ profile, onEdit }: { profile: TasteProfile; onEdit: () => void }) {
  const { t, i18n } = useTranslation()
  const forget = useForgetTaste()
  const save = useSaveTaste()
  // A rule changed here is saved at once; the summary is written again in the background.
  const saveLikings = (rules: TasteRules, notes: string | null) => save.mutate({ answers: (profile.answers ?? {}) as TasteAnswers, rules, notes, summary: null })
  const [confirming, setConfirming] = useState(false)
  const language = i18n.language.startsWith('en') ? 'en' : 'es'
  const warnings = impactWarnings(profile.impact, t)

  return (
    <section className="flex flex-col gap-5">
      <Suggestions />
      {profile.summary ? (
        <p className="rounded-lg sticker bg-card p-4 text-[15px] leading-relaxed">{profile.summary[language]}</p>
      ) : (
        profile.summaryPending && (
          <p className="flex items-center gap-2 rounded-lg sticker bg-card p-4 text-sm text-muted-foreground">
            <LoaderCircle className="size-4 animate-spin" aria-hidden />
            {t('taste.summaryPending')}
          </p>
        )
      )}
      <div className={cn('rounded-lg sticker bg-card px-4 py-1.5', save.isPending && 'opacity-60')}>
        <RuleList
          rules={profile.rules as TasteRules}
          impact={profile.impact}
          notes={profile.notes}
          onChange={(rules) => saveLikings(rules, profile.notes ?? null)}
          onNotesChange={(notes) => saveLikings(profile.rules as TasteRules, notes)}
        />
      </div>
      {save.isError && <p className="text-sm text-destructive">{t('taste.saveError')}</p>}
      {warnings.map((warning) => (
        <p key={warning} className="flex gap-2 text-sm font-semibold text-destructive">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          {warning}
        </p>
      ))}
      {profile.updatedBy && profile.updatedAt && (
        <p className="text-xs text-muted-foreground">
          {t('taste.updatedBy', { name: profile.updatedBy, date: new Intl.DateTimeFormat(i18n.language, { dateStyle: 'long' }).format(new Date(profile.updatedAt)) })}
        </p>
      )}
      <Button onClick={onEdit}>{t('taste.edit')}</Button>
      {confirming ? (
        <div className="flex flex-col gap-2 rounded-lg border-2 border-destructive p-3">
          <p className="text-sm font-semibold">{t('taste.forget.confirm')}</p>
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" className="flex-1" onClick={() => setConfirming(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" size="sm" className="flex-1" disabled={forget.isPending} onClick={() => forget.mutate()}>
              {t('taste.forget.yes')}
            </Button>
          </div>
        </div>
      ) : (
        <button type="button" onClick={() => setConfirming(true)} className="self-start px-1 text-sm font-bold text-destructive">
          {t('taste.forget.button')}
        </button>
      )}
    </section>
  )
}
