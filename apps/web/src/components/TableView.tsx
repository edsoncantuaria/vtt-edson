import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import type { SceneState } from '@vtt/core'
import { isValidDiceFormula } from '@vtt/core'
import { api } from '../lib/api'
import { createEcho } from '../lib/echo'
import { VttTable } from '../pixi/VttTable'
import { useSession, type Tool } from '../store/session'
import './TableView.css'

const TOOLS: { id: Tool; label: string; gmOnly?: boolean }[] = [
  { id: 'select', label: 'Selecionar' },
  { id: 'pan', label: 'Pan' },
  { id: 'token', label: 'Token', gmOnly: true },
  { id: 'wall', label: 'Parede', gmOnly: true },
  { id: 'door', label: 'Porta', gmOnly: true },
  { id: 'light', label: 'Luz', gmOnly: true },
  { id: 'fog', label: 'Fog', gmOnly: true },
]

export function TableView() {
  const {
    sceneId,
    sceneName,
    roomCode,
    role,
    state,
    backgroundUrl,
    tool,
    user,
    setTool,
    patchState,
    setError,
    error,
  } = useSession()
  const hostRef = useRef<HTMLDivElement>(null)
  const tableRef = useRef<VttTable | null>(null)
  const toolRef = useRef(tool)
  const [chatText, setChatText] = useState('')
  const [uploading, setUploading] = useState(false)

  toolRef.current = tool

  const tools = useMemo(
    () => TOOLS.filter((t) => !t.gmOnly || role === 'gm'),
    [role],
  )

  useEffect(() => {
    if (!hostRef.current || !sceneId || !role) return

    let cancelled = false
    let echo: ReturnType<typeof createEcho> | null = null
    const host = hostRef.current
    const table = new VttTable({
      host,
      role,
      getTool: () => toolRef.current,
      callbacks: {
        onTokenMove: (id, x, y) => {
          void api(`/scenes/${sceneId}/tokens`, {
            method: 'POST',
            body: JSON.stringify({ id, x, y }),
          }).catch((e) => setError(e.message))
        },
        onTokenCreate: (x, y) => {
          void api(`/scenes/${sceneId}/tokens`, {
            method: 'POST',
            body: JSON.stringify({ x, y, name: 'Token', ownerUserId: user?.id }),
          }).catch((e) => setError(e.message))
        },
        onWallCreate: (x1, y1, x2, y2) => {
          void api(`/scenes/${sceneId}/walls`, {
            method: 'POST',
            body: JSON.stringify({ x1, y1, x2, y2 }),
          }).catch((e) => setError(e.message))
        },
        onDoorCreate: (x1, y1, x2, y2) => {
          void api(`/scenes/${sceneId}/doors`, {
            method: 'POST',
            body: JSON.stringify({ x1, y1, x2, y2, open: false }),
          }).catch((e) => setError(e.message))
        },
        onLightCreate: (x, y) => {
          void api(`/scenes/${sceneId}/lights`, {
            method: 'POST',
            body: JSON.stringify({ x, y, radius: 180 }),
          }).catch((e) => setError(e.message))
        },
        onFogPaint: (x, y, w, h) => {
          void api(`/scenes/${sceneId}/fog`, {
            method: 'POST',
            body: JSON.stringify({ x, y, w, h, mode: 'reveal' }),
          }).catch((e) => setError(e.message))
        },
      },
    })

    void (async () => {
      try {
        await table.init()
        if (cancelled || !table.ready) return

        tableRef.current = table
        const snap = useSession.getState()
        await table.render(snap.state, snap.backgroundUrl)

        echo = createEcho()
        echo
          .private(`scene.${sceneId}`)
          .listen('.SceneUpdated', (payload: { state: SceneState; backgroundUrl?: string | null }) => {
            patchState(payload.state, payload.backgroundUrl)
          })
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Falha ao iniciar a mesa')
        }
      }
    })()

    return () => {
      cancelled = true
      echo?.leave(`scene.${sceneId}`)
      table.destroy()
      if (tableRef.current === table) tableRef.current = null
      host.replaceChildren()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sceneId, role])

  useEffect(() => {
    const table = tableRef.current
    if (!table?.ready) return
    table.setRole(role ?? 'player')
    void table.render(state, backgroundUrl)
  }, [state, backgroundUrl, role])

  async function onUpload(file: File | null) {
    if (!file || !sceneId) return
    setUploading(true)
    setError(null)
    try {
      const fd = new FormData()
      fd.append('background', file)
      const res = await api<{ state: SceneState; backgroundUrl: string }>(
        `/scenes/${sceneId}/background`,
        { method: 'POST', formData: fd },
      )
      patchState(res.state, res.backgroundUrl)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Falha no upload')
    } finally {
      setUploading(false)
    }
  }

  async function sendChat(e: FormEvent) {
    e.preventDefault()
    if (!sceneId || !chatText.trim()) return
    const text = chatText.trim()
    if (text.toLowerCase().startsWith('/roll ') && !isValidDiceFormula(text.slice(6))) {
      setError('Fórmula inválida. Use NdM±K, ex: 2d6+3')
      return
    }
    setChatText('')
    try {
      await api(`/scenes/${sceneId}/chat`, {
        method: 'POST',
        body: JSON.stringify({ text }),
      })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha no chat')
    }
  }

  return (
    <div className="table">
      <aside className="table__sidebar">
        <div className="table__meta">
          <h1>{sceneName}</h1>
          <p>
            Código <strong>{roomCode}</strong> · {role === 'gm' ? 'Mestre' : 'Jogador'} · {user?.name}
          </p>
        </div>

        <div className="table__tools">
          {tools.map((t) => (
            <button key={t.id} className={tool === t.id ? 'active' : ''} onClick={() => setTool(t.id)}>
              {t.label}
            </button>
          ))}
        </div>

        {role === 'gm' && (
          <label className="table__upload">
            {uploading ? 'Enviando mapa…' : 'Upload do mapa'}
            <input
              type="file"
              accept="image/*"
              hidden
              onChange={(e) => void onUpload(e.target.files?.[0] ?? null)}
            />
          </label>
        )}

        {error && <p className="table__error">{error}</p>}

        <div className="table__chat">
          <div className="table__chat-log">
            {state.chat.map((m) => (
              <div key={m.id} className={`msg msg--${m.type}`}>
                <strong>{m.userName}</strong>
                {m.type === 'roll' ? (
                  <span>
                    {' '}
                    rolou <code>{m.formula}</code> → <em>{m.total}</em> ({m.detail})
                  </span>
                ) : (
                  <span> {m.text}</span>
                )}
              </div>
            ))}
          </div>
          <form onSubmit={sendChat} className="table__chat-form">
            <input
              value={chatText}
              onChange={(e) => setChatText(e.target.value)}
              placeholder="Mensagem ou /roll 2d6+3"
            />
            <button className="primary" type="submit">
              Enviar
            </button>
          </form>
        </div>
      </aside>
      <div className="table__stage">
        <div className="table__canvas" ref={hostRef} />
      </div>
    </div>
  )
}
