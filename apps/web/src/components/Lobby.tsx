import { useState, type FormEvent } from 'react'
import { api, setToken } from '../lib/api'
import { useSession } from '../store/session'
import './Lobby.css'

type AuthRes = { user: { id: number; name: string; email: string }; token: string }
type RoomRes = {
  room: { code: string }
  scene: {
    id: number
    name: string
    role: 'gm' | 'player'
    state: import('@vtt/core').SceneState
    backgroundUrl: string | null
  }
}

export function Lobby() {
  const { setAuth, setScene, setError, error } = useSession()
  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('gm@vtt.local')
  const [password, setPassword] = useState('password')
  const [campaignName, setCampaignName] = useState('Aventura')
  const [joinCode, setJoinCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [authed, setAuthed] = useState(false)

  async function auth(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const path = mode === 'login' ? '/login' : '/register'
      const body =
        mode === 'login'
          ? { email, password }
          : { name: name || email.split('@')[0], email, password }
      const res = await api<AuthRes>(path, { method: 'POST', body: JSON.stringify(body) })
      setToken(res.token)
      setAuth(res.user, res.token)
      setAuthed(true)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha na autenticação')
    } finally {
      setBusy(false)
    }
  }

  async function createRoom(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api<RoomRes>('/rooms', {
        method: 'POST',
        body: JSON.stringify({ name: campaignName }),
      })
      setScene({
        id: res.scene.id,
        name: res.scene.name,
        role: res.scene.role,
        state: res.scene.state,
        backgroundUrl: res.scene.backgroundUrl,
        roomCode: res.room.code,
      })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao criar sala')
    } finally {
      setBusy(false)
    }
  }

  async function joinRoom(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api<RoomRes>('/rooms/join', {
        method: 'POST',
        body: JSON.stringify({ code: joinCode }),
      })
      setScene({
        id: res.scene.id,
        name: res.scene.name,
        role: res.scene.role,
        state: res.scene.state,
        backgroundUrl: res.scene.backgroundUrl,
        roomCode: res.room.code,
      })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Código inválido')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="lobby">
      <header className="lobby__brand">
        <p className="lobby__eyebrow">Mesa virtual</p>
        <h1>VTT Edson</h1>
        <p className="lobby__tag">Core tático — mapa, tokens, fog e dados. Sistema de regras depois.</p>
      </header>

      {error && <p className="lobby__error">{error}</p>}

      {!authed ? (
        <form className="lobby__card" onSubmit={auth}>
          <div className="lobby__tabs">
            <button type="button" className={mode === 'login' ? 'active' : ''} onClick={() => setMode('login')}>
              Entrar
            </button>
            <button type="button" className={mode === 'register' ? 'active' : ''} onClick={() => setMode('register')}>
              Registrar
            </button>
          </div>
          {mode === 'register' && (
            <label>
              Nome
              <input value={name} onChange={(e) => setName(e.target.value)} required />
            </label>
          )}
          <label>
            E-mail
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </label>
          <label>
            Senha
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </label>
          <button className="primary" disabled={busy}>
            {busy ? '...' : mode === 'login' ? 'Entrar' : 'Criar conta'}
          </button>
          <p className="lobby__hint">Dev: gm@vtt.local / player@vtt.local — senha password</p>
        </form>
      ) : (
        <div className="lobby__grid">
          <form className="lobby__card" onSubmit={createRoom}>
            <h2>Criar mesa (GM)</h2>
            <label>
              Nome da campanha
              <input value={campaignName} onChange={(e) => setCampaignName(e.target.value)} required />
            </label>
            <button className="primary" disabled={busy}>
              Gerar código
            </button>
          </form>
          <form className="lobby__card" onSubmit={joinRoom}>
            <h2>Entrar com código</h2>
            <label>
              Código da sala
              <input value={joinCode} onChange={(e) => setJoinCode(e.target.value.toUpperCase())} required maxLength={8} />
            </label>
            <button className="primary" disabled={busy}>
              Entrar na mesa
            </button>
          </form>
        </div>
      )}
    </div>
  )
}
