import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { ApiError } from '../api/client'
import { useAuth } from '../auth/context'
import { Button } from '../components/ui'

const demoAccounts = [
  { email: 'buyer@energomontazh.demo', label: 'Закупщик, ЭнергоМонтаж (крупный опт)' },
  { email: 'director@energomontazh.demo', label: 'Руководитель, ЭнергоМонтаж' },
  { email: 'buyer@domstroy.demo', label: 'Закупщик, ДомСтрой (розница −7%)' },
]

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState(demoAccounts[0].email)
  const [password, setPassword] = useState('demo12345')
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState(false)

  if (user?.company) return <Navigate to="/" replace />

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setPending(true)
    setError(null)

    try {
      const signedIn = await login(email, password)
      if (!signedIn.company) {
        setError('Это сотрудник оптовика: войдите в панель менеджера')
        return
      }
      navigate('/')
    } catch (exception) {
      setError(exception instanceof ApiError ? (exception.errors.email?.[0] ?? exception.message) : 'Сервер недоступен')
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="grid min-h-screen place-items-center bg-gradient-to-br from-brand-50 via-white to-slate-100 px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <div className="mx-auto mb-4 grid size-12 place-items-center rounded-xl bg-brand-600 text-2xl text-white">⚡</div>
          <h1 className="text-2xl font-semibold text-slate-900">ЭлектроОпт B2B</h1>
          <p className="mt-1 text-sm text-slate-500">Кабинет оптового покупателя</p>
        </div>

        <form onSubmit={submit} className="space-y-4 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Email</span>
            <input
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300 outline-none focus:ring-2 focus:ring-brand-500"
              required
            />
          </label>
          <label className="block">
            <span className="text-sm font-medium text-slate-700">Пароль</span>
            <input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300 outline-none focus:ring-2 focus:ring-brand-500"
              required
            />
          </label>
          {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
          <Button type="submit" disabled={pending} className="w-full py-2.5">
            {pending ? 'Входим…' : 'Войти'}
          </Button>
        </form>

        <div className="mt-6 rounded-2xl border border-dashed border-slate-300 p-4">
          <p className="mb-2 text-xs font-medium tracking-wide text-slate-500 uppercase">Демо-доступ, пароль demo12345</p>
          <div className="space-y-1">
            {demoAccounts.map((account) => (
              <button
                key={account.email}
                type="button"
                onClick={() => setEmail(account.email)}
                className="block w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-white"
              >
                <span className="font-medium text-slate-700">{account.label}</span>
                <span className="block text-xs text-slate-500">{account.email}</span>
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
