import { useState } from 'react'

interface Props {
  value: number
  min?: number
  step?: number
  onChange: (value: number) => void
  className?: string
}

export function QuantityInput({ value, min = 1, step = 1, onChange, className = '' }: Props) {
  const [draft, setDraft] = useState(String(value))
  const [synced, setSynced] = useState(value)

  if (synced !== value) {
    setSynced(value)
    setDraft(String(value))
  }

  const commit = (next: number) => {
    const safe = Math.max(1, Math.round(next))
    setDraft(String(safe))
    if (safe !== value) onChange(safe)
  }

  return (
    <div className={`inline-flex h-9 items-stretch overflow-hidden rounded-lg ring-1 ring-slate-300 ${className}`}>
      <button
        type="button"
        aria-label="Меньше"
        className="w-8 text-slate-500 hover:bg-slate-100"
        onClick={() => commit(Math.max(min, value - step))}
      >
        −
      </button>
      <input
        inputMode="numeric"
        className="w-16 border-x border-slate-200 text-center text-sm tabular-nums outline-none focus:bg-brand-50"
        value={draft}
        onChange={(event) => setDraft(event.target.value.replace(/\D/g, ''))}
        onBlur={() => commit(Number(draft) || min)}
        onKeyDown={(event) => event.key === 'Enter' && commit(Number(draft) || min)}
      />
      <button
        type="button"
        aria-label="Больше"
        className="w-8 text-slate-500 hover:bg-slate-100"
        onClick={() => commit(value + step)}
      >
        +
      </button>
    </div>
  )
}
