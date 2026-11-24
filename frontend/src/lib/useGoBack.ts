import { useNavigate } from 'react-router'

/**
 * Back to wherever the user came from (keeps library filters, reopens the account sheet on Hoy), or,
 * when the page was opened directly, to `fallback`.
 */
export function useGoBack(fallback = '/recipes') {
  const navigate = useNavigate()
  return () => {
    const idx = (window.history.state as { idx?: number } | null)?.idx ?? 0
    if (idx > 0) void navigate(-1)
    else void navigate(fallback, { replace: true })
  }
}
