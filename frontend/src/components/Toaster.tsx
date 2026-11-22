import { AlertCircle, CheckCircle2, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { dismissToast, type Toast, useToasts } from '@/lib/toast'
import { cn } from '@/lib/utils'

/** Toasts drop in at the top, clear of the tab bar, bottom sheets and the recipe's action bar. */
export function Toaster() {
  const toasts = useToasts()
  return (
    <div
      aria-live="polite"
      className="pointer-events-none fixed inset-x-0 top-0 z-[60] mx-auto flex max-w-2xl flex-col gap-2 px-4 pt-[max(0.75rem,env(safe-area-inset-top))]"
    >
      {toasts.map((item) => (
        <ToastCard key={item.id} toast={item} />
      ))}
    </div>
  )
}

function ToastCard({ toast }: { toast: Toast }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const Icon = toast.tone === 'success' ? CheckCircle2 : AlertCircle

  return (
    <div role={toast.tone === 'error' ? 'alert' : 'status'} className="toast pointer-events-auto flex items-center gap-3 rounded-lg sticker bg-card py-2.5 pr-1.5 pl-3">
      <Icon className={cn('size-6 shrink-0', toast.tone === 'success' ? 'text-primary' : 'text-destructive')} aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="text-sm font-extrabold">{toast.title}</p>
        {toast.body && <p className="truncate text-sm text-muted-foreground">{toast.body}</p>}
      </div>
      {toast.action && (
        <button
          type="button"
          onClick={() => {
            dismissToast(toast.id)
            void navigate(toast.action!.to)
          }}
          className="h-9 shrink-0 rounded-sm sticker-sm bg-card px-3 text-sm font-semibold"
        >
          {toast.action.label}
        </button>
      )}
      <button
        type="button"
        onClick={() => dismissToast(toast.id)}
        className="grid size-9 shrink-0 place-items-center rounded-sm text-muted-foreground hover:bg-muted"
        aria-label={t('common.dismiss')}
      >
        <X className="size-4" aria-hidden />
      </button>
    </div>
  )
}
