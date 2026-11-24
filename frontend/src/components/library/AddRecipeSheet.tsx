import { ChevronRight, PenLine, Share2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { useInbox } from '@/api/library'
import { InboxList } from '@/components/library/Inbox'
import { SaveLinkForm } from '@/components/library/SaveLinkForm'
import { Sheet, SheetRowContent, sheetRowClass } from '@/components/Sheet'

/**
 * One place to add a recipe and to see how the last ones are doing: opened from + or from the
 * inbox banner. The share-sheet tip lives here, where people are trying to add one.
 */
export function AddRecipeSheet({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const { data: sources = [] } = useInbox()
  const chevron = <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />

  return (
    <Sheet title={t('library.add.title')} onClose={onClose}>
      <div className="flex flex-col gap-4">
        <SaveLinkForm />
        <div className="-mt-2 flex flex-col divide-y">
          <Link to="/recipes/new" className={sheetRowClass}>
            <SheetRowContent icon={<PenLine />} title={t('library.add.write')} hint={t('library.add.writeHint')} trailing={chevron} />
          </Link>
          <Link to="/settings#share" className={sheetRowClass}>
            <SheetRowContent icon={<Share2 />} title={t('library.add.share')} hint={t('library.add.shareHint')} trailing={chevron} />
          </Link>
        </div>
        <InboxList sources={sources} />
      </div>
    </Sheet>
  )
}
