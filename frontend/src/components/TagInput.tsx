import { X } from 'lucide-react'
import { type KeyboardEvent, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

/** Tags as removable chips; Enter or comma adds what's typed. Suggests tags already in the library. */
export function TagInput({
  value,
  onChange,
  suggestions,
}: {
  value: string[]
  onChange: (tags: string[]) => void
  suggestions: string[]
}) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState('')
  const listId = useId()

  const add = (raw: string) => {
    const tag = raw.trim().replace(/\s+/g, ' ').toLowerCase()
    if (tag && !value.includes(tag)) onChange([...value, tag])
    setDraft('')
  }

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault()
      add(draft)
    } else if (event.key === 'Backspace' && !draft && value.length) {
      onChange(value.slice(0, -1))
    }
  }

  return (
    <div
      className={cn(
        'flex min-h-11 flex-wrap items-center gap-1.5 rounded-md border border-input bg-card px-2 py-1.5',
        'focus-within:ring-2 focus-within:ring-ring',
      )}
    >
      {value.map((tag) => (
        <span key={tag} className="inline-flex h-8 items-center gap-1 rounded-full bg-accent pr-1 pl-3 text-sm text-accent-foreground">
          {tag}
          <button
            type="button"
            onClick={() => onChange(value.filter((t) => t !== tag))}
            className="grid size-6 place-items-center rounded-full"
            aria-label={t('recipe.form.removeTag', { tag })}
          >
            <X className="size-3.5" />
          </button>
        </span>
      ))}
      <input
        list={listId}
        value={draft}
        onChange={(e) => {
          // Mobile keyboards don't always send a "," keydown: split on input too.
          if (e.target.value.endsWith(',')) add(e.target.value.slice(0, -1))
          else setDraft(e.target.value)
        }}
        onKeyDown={onKeyDown}
        onBlur={() => draft && add(draft)}
        enterKeyHint="enter"
        placeholder={value.length ? '' : t('recipe.form.tagsPlaceholder')}
        className="h-8 min-w-24 flex-1 bg-transparent px-1 text-base outline-none placeholder:text-muted-foreground"
      />
      <datalist id={listId}>
        {suggestions
          .filter((s) => !value.includes(s))
          .map((s) => (
            <option key={s} value={s} />
          ))}
      </datalist>
    </div>
  )
}
