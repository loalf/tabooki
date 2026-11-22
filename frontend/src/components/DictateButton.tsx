import { LoaderCircle, Mic, Square } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'

/** Long enough for a few sentences; the server takes about three minutes. */
const MAX_SECONDS = 120

/** What the browser records in: WebM/Opus (Chrome, Android, Firefox) or MP4/AAC (Safari, iPhone). */
function recordingType(): string | undefined {
  return ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type))
}

const canRecord = () => typeof window !== 'undefined' && typeof MediaRecorder !== 'undefined' && !!navigator.mediaDevices?.getUserMedia

/**
 * Say it instead of typing it: records with the microphone, and the server writes it down
 * (Whisper, POST /api/dictation). The text comes back through onText, to add to what's typed.
 */
export function DictateButton({ onText, className }: { onText: (text: string) => void; className?: string }) {
  const { t } = useTranslation()
  const [state, setState] = useState<'idle' | 'recording' | 'sending'>('idle')
  const [seconds, setSeconds] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const recorder = useRef<MediaRecorder | null>(null)
  const timer = useRef<ReturnType<typeof setInterval> | null>(null)

  // Leaving the page while recording: let go of the microphone.
  useEffect(
    () => () => {
      if (timer.current) clearInterval(timer.current)
      recorder.current?.stream.getTracks().forEach((track) => track.stop())
    },
    [],
  )

  if (!canRecord()) return null

  const send = async (audio: Blob) => {
    setState('sending')
    const form = new FormData()
    form.append('audio', audio, audio.type.includes('mp4') ? 'dictation.m4a' : 'dictation.webm')
    try {
      const response = await fetch('/api/dictation', { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      const data = (await response.json()) as { text?: string }
      if (!response.ok) throw new Error()
      if (data.text?.trim()) onText(data.text.trim())
      else setError(t('dictate.nothing'))
    } catch {
      setError(t('dictate.error'))
    } finally {
      setState('idle')
    }
  }

  const start = async () => {
    setError(null)
    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    } catch {
      setError(t('dictate.denied'))
      return
    }
    const type = recordingType()
    const rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined)
    const chunks: Blob[] = []
    rec.ondataavailable = (event) => {
      if (event.data.size > 0) chunks.push(event.data)
    }
    rec.onstop = () => {
      stream.getTracks().forEach((track) => track.stop())
      if (timer.current) clearInterval(timer.current)
      void send(new Blob(chunks, { type: rec.mimeType || type || 'audio/webm' }))
    }
    recorder.current = rec
    rec.start()
    setSeconds(0)
    setState('recording')
    timer.current = setInterval(
      () =>
        setSeconds((s) => {
          if (s + 1 >= MAX_SECONDS && rec.state === 'recording') rec.stop()
          return s + 1
        }),
      1000,
    )
  }

  const stop = () => {
    if (recorder.current?.state === 'recording') recorder.current.stop()
  }

  return (
    <div className={cn('flex flex-col items-end gap-1', className)}>
      <button
        type="button"
        onClick={() => (state === 'recording' ? stop() : void start())}
        disabled={state === 'sending'}
        aria-pressed={state === 'recording'}
        className={cn(
          'inline-flex h-9 items-center gap-1.5 rounded-full border-2 border-outline px-3 text-sm font-bold disabled:opacity-60',
          state === 'recording' ? 'bg-destructive text-white' : 'bg-card',
        )}
      >
        {state === 'recording' ? (
          <>
            <Square className="size-3.5 fill-current" aria-hidden />
            {t('dictate.stop', { seconds })}
          </>
        ) : state === 'sending' ? (
          <>
            <LoaderCircle className="size-4 animate-spin" aria-hidden />
            {t('dictate.sending')}
          </>
        ) : (
          <>
            <Mic className="size-4" aria-hidden />
            {t('dictate.start')}
          </>
        )}
      </button>
      {error && (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}
