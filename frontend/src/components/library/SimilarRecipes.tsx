import { Heart } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useSimilarRecipes } from '@/api/library'
import { Thumbnail } from '@/components/library/Thumbnail'

/** "More like this": a row of the library's recipes sharing the most with this one, and what they share. Nothing when none does. */
export function SimilarRecipes({ id }: { id: string }) {
  const { t, i18n } = useTranslation()
  const similar = useSimilarRecipes(id)
  const lang = i18n.language.startsWith('en') ? 'en' : 'es'
  if (!similar.data?.length) return null
  const list = new Intl.ListFormat(i18n.language, { type: 'conjunction' })

  return (
    <section aria-labelledby="similar-title" className="mt-8 flex flex-col gap-2.5">
      <h2 id="similar-title" className="text-lg font-extrabold">
        {t('recipe.similar.title')}
      </h2>
      <ul className="no-scrollbar -mx-4 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pt-0.5 pb-2">
        {similar.data.map((recipe) => (
          <li key={recipe.id} className="flex w-[14.5rem] shrink-0 snap-start">
            <Link to={`/recipes/${recipe.id}`} className="flex w-full flex-col overflow-hidden rounded-lg sticker bg-card">
              <Thumbnail src={recipe.thumbnail} className="aspect-[9/4] w-full border-b-2 border-outline" />
              <span className="px-2.5 pt-2 pb-2.5">
                <span className="flex items-center gap-1 text-sm font-bold">
                  <span className="truncate">{recipe.title}</span>
                  {recipe.favorite && <Heart className="size-3.5 shrink-0 fill-current text-brand" aria-label={t('library.favorites')} />}
                </span>
                {recipe.shared?.length ? (
                  <span className="block truncate text-xs text-muted-foreground">
                    {t('recipe.similar.shared', { what: list.format(recipe.shared.slice(0, 3).map((name) => name[lang])) })}
                  </span>
                ) : null}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}
