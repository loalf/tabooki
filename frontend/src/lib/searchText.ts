/** Folds text for accent- and case-insensitive matching, like App\Library\SearchText: "Limón" → "limon". */
export const SearchText = {
  normalize(text: string): string {
    return text.normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase().replace(/\s+/g, ' ').trim()
  },
  /** The distinct words of a query, folded. */
  words(query: string): string[] {
    return [...new Set(SearchText.normalize(query).split(' ').filter(Boolean))]
  },
}
