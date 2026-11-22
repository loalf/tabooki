import { useNavigate } from 'react-router'

/** Back to wherever the user came from (keeps library filters), or to the library. */
export function useGoBack() {
  const navigate = useNavigate()
  return () => {
    const idx = (window.history.state as { idx?: number } | null)?.idx ?? 0
    if (idx > 0) void navigate(-1)
    else void navigate('/recipes', { replace: true })
  }
}
