import { cn } from '@/lib/utils'

/** One fried egg. Its colours are the mark's own, the same in light and dark mode. */
export function Egg({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 20 20" className={cn('shrink-0 overflow-visible', className)} aria-hidden>
      <path
        d="M10 1.2c3.4 0 4.6 2.2 6.6 3.1 2.2 1 2.6 3.6 1.6 5.8-.9 2-.2 4.4-2.3 6.3-1.9 1.7-4.3 1.5-6.4 1.9-2.4.4-4.6-.6-5.8-2.6C2.6 13.9.9 12.4 1.3 9.6 1.7 6.9 3.4 6 4.6 4.1 5.9 2.1 7.5 1.2 10 1.2z"
        fill="#fffdf6"
        stroke="#3a2213"
        strokeWidth="1.3"
      />
      <circle cx="9.6" cy="10" r="4" fill="#f3a81c" stroke="#3a2213" strokeWidth="1.1" />
    </svg>
  )
}

/** The two eggs (comida and cena): the "oo" of the wordmark, also used as the mascot. */
export function Eggs({ className, eggClassName, wobble = false }: { className?: string; eggClassName?: string; wobble?: boolean }) {
  return (
    <span className={cn('inline-flex items-center gap-[0.08em]', wobble && 'egg-wobble', className)} aria-hidden>
      <Egg className={eggClassName} />
      <Egg className={eggClassName} />
    </span>
  )
}

/** "tabooki" with the two o's drawn as fried eggs. Size it with a text-* class. */
export function Wordmark({ className }: { className?: string }) {
  return (
    <span role="img" aria-label="Tabooki" className={cn('inline-flex items-center font-display leading-none font-normal text-brand', className)}>
      <span aria-hidden>tab</span>
      <Eggs className="mx-[0.02em] translate-y-[0.06em]" eggClassName="size-[0.6em]" />
      <span aria-hidden>ki</span>
    </span>
  )
}
