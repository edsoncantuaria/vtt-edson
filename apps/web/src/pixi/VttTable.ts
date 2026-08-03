import { Application, Container, Graphics, Sprite, Text, TextStyle, Texture } from 'pixi.js'
import type { SceneState, Role } from '@vtt/core'
import type { Tool } from '../store/session'

export type TableCallbacks = {
  onTokenMove: (id: string, x: number, y: number) => void
  onTokenCreate: (x: number, y: number) => void
  onWallCreate: (x1: number, y1: number, x2: number, y2: number) => void
  onDoorCreate: (x1: number, y1: number, x2: number, y2: number) => void
  onLightCreate: (x: number, y: number) => void
  onFogPaint: (x: number, y: number, w: number, h: number) => void
}

type MountOpts = {
  host: HTMLElement
  role: Role
  getTool: () => Tool
  callbacks: TableCallbacks
}

export class VttTable {
  app: Application
  world = new Container()
  bgLayer = new Container()
  gridLayer = new Graphics()
  tokenLayer = new Container()
  wallLayer = new Graphics()
  lightMask = new Graphics()
  fogLayer = new Graphics()
  draft = new Graphics()
  host: HTMLElement
  role: Role
  getTool: () => Tool
  callbacks: TableCallbacks
  state: SceneState | null = null
  backgroundUrl: string | null = null
  bgSprite: Sprite | null = null
  draggingToken: { id: string; g: Container; ox: number; oy: number } | null = null
  draftStart: { x: number; y: number } | null = null
  panning = false
  panOrigin = { x: 0, y: 0, wx: 0, wy: 0 }
  ready = false
  private destroyed = false

  constructor(opts: MountOpts) {
    this.app = new Application()
    this.host = opts.host
    this.role = opts.role
    this.getTool = opts.getTool
    this.callbacks = opts.callbacks
  }

  async init() {
    const width = Math.max(this.host.clientWidth || 800, 320)
    const height = Math.max(this.host.clientHeight || 600, 240)

    await this.app.init({
      width,
      height,
      background: '#0e0c0a',
      antialias: true,
      resolution: Math.min(window.devicePixelRatio || 1, 2),
      autoDensity: true,
      // Avoid resizeTo/ResizePlugin — destroy races break _cancelResize under React remounts.
    })

    if (this.destroyed) {
      this.teardownApp()
      return
    }

    this.host.replaceChildren()
    this.host.appendChild(this.app.canvas)
    this.world.addChild(this.bgLayer)
    this.world.addChild(this.gridLayer)
    this.world.addChild(this.tokenLayer)
    this.world.addChild(this.wallLayer)
    this.world.addChild(this.lightMask)
    this.world.addChild(this.fogLayer)
    this.world.addChild(this.draft)
    this.app.stage.addChild(this.world)
    this.bindInput()
    this.ready = true
    this.observeHostSize()
  }

  destroy() {
    this.destroyed = true
    this.ready = false
    this.resizeObserver?.disconnect()
    this.resizeObserver = null
    this.teardownApp()
  }

  private resizeObserver: ResizeObserver | null = null

  private observeHostSize() {
    this.resizeObserver = new ResizeObserver(() => {
      if (!this.ready || this.destroyed) return
      const width = Math.max(this.host.clientWidth || 800, 320)
      const height = Math.max(this.host.clientHeight || 600, 240)
      this.app.renderer.resize(width, height)
    })
    this.resizeObserver.observe(this.host)
  }

  private teardownApp() {
    try {
      this.app.ticker?.stop()
    } catch {
      /* ignore */
    }

    try {
      const canvas = this.app.canvas as HTMLCanvasElement | undefined
      canvas?.remove()
    } catch {
      /* ignore */
    }

    try {
      // Prefer renderer.destroy to avoid ResizePlugin.destroy when resizeTo was never used.
      this.app.renderer?.destroy(true)
    } catch {
      /* ignore */
    }
  }

  setRole(role: Role) {
    this.role = role
  }

  async render(state: SceneState, backgroundUrl: string | null) {
    this.state = state
    if (backgroundUrl !== this.backgroundUrl) {
      this.backgroundUrl = backgroundUrl
      this.bgLayer.removeChildren()
      this.bgSprite = null
      if (backgroundUrl) {
        const tex = await Texture.from(backgroundUrl)
        this.bgSprite = new Sprite(tex)
        this.bgLayer.addChild(this.bgSprite)
      }
    }
    this.drawGrid(state)
    this.drawWalls(state)
    this.drawTokens(state)
    this.drawFogAndLight(state)
  }

  private drawGrid(state: SceneState) {
    const g = this.gridLayer
    g.clear()
    const size = state.grid.size
    const w = this.bgSprite?.width || 2000
    const h = this.bgSprite?.height || 1400
    g.setStrokeStyle({ width: 1, color: 0xffffff, alpha: 0.12 })
    for (let x = state.grid.offsetX; x <= w; x += size) {
      g.moveTo(x, 0)
      g.lineTo(x, h)
    }
    for (let y = state.grid.offsetY; y <= h; y += size) {
      g.moveTo(0, y)
      g.lineTo(w, y)
    }
    g.stroke()
  }

  private drawWalls(state: SceneState) {
    const g = this.wallLayer
    g.clear()
    for (const wall of state.walls) {
      g.setStrokeStyle({ width: 4, color: 0xd9c3a0 })
      g.moveTo(wall.x1, wall.y1)
      g.lineTo(wall.x2, wall.y2)
      g.stroke()
    }
    for (const door of state.doors) {
      g.setStrokeStyle({ width: 5, color: door.open ? 0x3d7a5c : 0xc45c26 })
      g.moveTo(door.x1, door.y1)
      g.lineTo(door.x2, door.y2)
      g.stroke()
    }
  }

  private drawTokens(state: SceneState) {
    this.tokenLayer.removeChildren()
    const cell = state.grid.size
    for (const token of state.tokens) {
      const c = new Container()
      c.x = token.x
      c.y = token.y
      c.eventMode = 'static'
      c.cursor = 'pointer'
      ;(c as Container & { tokenId?: string }).tokenId = token.id

      const circle = new Graphics()
        .circle(0, 0, (cell * (token.size || 1)) / 2 - 4)
        .fill({ color: 0xc45c26 })
        .stroke({ width: 2, color: 0xf2e8d5 })
      c.addChild(circle)
      const label = new Text({
        text: token.name,
        style: new TextStyle({ fill: 0xf2e8d5, fontSize: 12, fontFamily: 'DM Sans' }),
      })
      label.anchor.set(0.5, -1.2)
      c.addChild(label)
      this.tokenLayer.addChild(c)
    }
  }

  private drawFogAndLight(state: SceneState) {
    const fog = this.fogLayer
    fog.clear()
    const w = this.bgSprite?.width || 2000
    const h = this.bgSprite?.height || 1400

    if (this.role === 'gm') {
      fog.rect(0, 0, w, h).fill({ color: 0x000000, alpha: 0.35 })
      for (const r of state.fog.revealed) {
        fog.rect(r.x, r.y, r.w, r.h).cut()
      }
    } else {
      fog.rect(0, 0, w, h).fill({ color: 0x000000, alpha: 1 })
      for (const r of state.fog.revealed) {
        fog.rect(r.x, r.y, r.w, r.h).cut()
      }
      // light holes: soft cutouts for players
      for (const light of state.lights) {
        fog.circle(light.x, light.y, light.radius).cut()
      }
    }

    const lights = this.lightMask
    lights.clear()
    for (const light of state.lights) {
      lights.circle(light.x, light.y, light.radius).fill({ color: 0xffe6a0, alpha: 0.12 })
      lights.circle(light.x, light.y, 6).fill({ color: 0xffcc66, alpha: 0.8 })
    }
  }

  private worldPoint(e: PointerEvent) {
    const bounds = this.app.canvas.getBoundingClientRect()
    const x = (e.clientX - bounds.left - this.world.x) / this.world.scale.x
    const y = (e.clientY - bounds.top - this.world.y) / this.world.scale.y
    return { x, y }
  }

  private snap(x: number, y: number) {
    if (!this.state?.grid.snap) return { x, y }
    const s = this.state.grid.size
    return {
      x: Math.round(x / s) * s + s / 2,
      y: Math.round(y / s) * s + s / 2,
    }
  }

  private bindInput() {
    const canvas = this.app.canvas
    canvas.addEventListener('wheel', (e) => {
      e.preventDefault()
      const factor = e.deltaY > 0 ? 0.9 : 1.1
      const next = Math.min(3, Math.max(0.3, this.world.scale.x * factor))
      this.world.scale.set(next)
    })

    canvas.addEventListener('pointerdown', (e) => {
      const tool = this.getTool()
      const p = this.worldPoint(e)
      if (tool === 'pan' || e.button === 1 || e.button === 2) {
        this.panning = true
        this.panOrigin = { x: e.clientX, y: e.clientY, wx: this.world.x, wy: this.world.y }
        return
      }

      if (tool === 'select' || tool === 'token') {
        const hit = this.tokenLayer.children.find((c) => {
          const dx = c.x - p.x
          const dy = c.y - p.y
          return Math.hypot(dx, dy) < (this.state?.grid.size || 70) / 2
        }) as (Container & { tokenId?: string }) | undefined
        if (hit?.tokenId) {
          this.draggingToken = { id: hit.tokenId, g: hit, ox: p.x - hit.x, oy: p.y - hit.y }
          return
        }
        if (tool === 'token' && this.role === 'gm') {
          const s = this.snap(p.x, p.y)
          this.callbacks.onTokenCreate(s.x, s.y)
        }
        return
      }

      if (this.role !== 'gm') return
      if (tool === 'wall' || tool === 'door' || tool === 'fog') {
        this.draftStart = p
        return
      }
      if (tool === 'light') {
        this.callbacks.onLightCreate(p.x, p.y)
      }
    })

    canvas.addEventListener('pointermove', (e) => {
      if (this.panning) {
        this.world.x = this.panOrigin.wx + (e.clientX - this.panOrigin.x)
        this.world.y = this.panOrigin.wy + (e.clientY - this.panOrigin.y)
        return
      }
      const p = this.worldPoint(e)
      if (this.draggingToken) {
        const s = this.snap(p.x - this.draggingToken.ox, p.y - this.draggingToken.oy)
        this.draggingToken.g.x = s.x
        this.draggingToken.g.y = s.y
        return
      }
      if (this.draftStart) {
        this.draft.clear()
        const tool = this.getTool()
        if (tool === 'fog') {
          const x = Math.min(this.draftStart.x, p.x)
          const y = Math.min(this.draftStart.y, p.y)
          const w = Math.abs(p.x - this.draftStart.x)
          const h = Math.abs(p.y - this.draftStart.y)
          this.draft.rect(x, y, w, h).fill({ color: 0x3d7a5c, alpha: 0.25 })
        } else {
          this.draft.setStrokeStyle({ width: 3, color: 0x7ec8a0 })
          this.draft.moveTo(this.draftStart.x, this.draftStart.y)
          this.draft.lineTo(p.x, p.y)
          this.draft.stroke()
        }
      }
    })

    const end = (e: PointerEvent) => {
      const p = this.worldPoint(e)
      if (this.panning) {
        this.panning = false
        return
      }
      if (this.draggingToken) {
        const s = this.snap(this.draggingToken.g.x, this.draggingToken.g.y)
        this.callbacks.onTokenMove(this.draggingToken.id, s.x, s.y)
        this.draggingToken = null
        return
      }
      if (this.draftStart && this.role === 'gm') {
        const tool = this.getTool()
        if (tool === 'wall') this.callbacks.onWallCreate(this.draftStart.x, this.draftStart.y, p.x, p.y)
        if (tool === 'door') this.callbacks.onDoorCreate(this.draftStart.x, this.draftStart.y, p.x, p.y)
        if (tool === 'fog') {
          const x = Math.min(this.draftStart.x, p.x)
          const y = Math.min(this.draftStart.y, p.y)
          const w = Math.abs(p.x - this.draftStart.x)
          const h = Math.abs(p.y - this.draftStart.y)
          if (w > 4 && h > 4) this.callbacks.onFogPaint(x, y, w, h)
        }
        this.draftStart = null
        this.draft.clear()
      }
    }
    canvas.addEventListener('pointerup', end)
    canvas.addEventListener('pointerleave', end)
    canvas.addEventListener('contextmenu', (e) => e.preventDefault())
  }
}
