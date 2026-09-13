export type PluginPermission =
  | 'actors:read'
  | 'actors:write'
  | 'scene:read'
  | 'scene:write'
  | 'chat:write'
  | 'combat:read'
  | 'combat:write'
  | 'assets:read'

export type VttHookName =
  | 'scene:entered'
  | 'scene:updated'
  | 'actor:opened'
  | 'actor:updated'
  | 'action:before'
  | 'action:after'
  | 'combat:turn'
  | 'token:moved'
  | 'macro:executed'

export type VttHookPayloads = {
  'scene:entered': { sceneId: number }
  'scene:updated': { sceneId: number; reason?: string }
  'actor:opened': { actorId: number }
  'actor:updated': { actorId: number }
  'action:before': { sceneId: number; actorId: number; actionId: string }
  'action:after': { sceneId: number; actorId: number; actionId: string; messageId?: string }
  'combat:turn': { sceneId: number; combatId: number; round: number; turn: number }
  'token:moved': { sceneId: number; tokenId: string; x: number; y: number }
  'macro:executed': { sceneId: number; macroId: number }
}

export type VttPluginManifest = {
  id: string
  name: string
  version: string
  apiVersion: 1
  description?: string
  permissions?: PluginPermission[]
  hooks?: VttHookName[]
}

export type VttPluginContext = {
  emit<K extends VttHookName>(hook: K, payload: VttHookPayloads[K]): Promise<void>
  on<K extends VttHookName>(hook: K, callback: (payload: VttHookPayloads[K]) => void | Promise<void>): () => void
}

export type VttPlugin = {
  manifest: VttPluginManifest
  activate(context: VttPluginContext): void | (() => void) | Promise<void | (() => void)>
}

export function defineVttPlugin(plugin: VttPlugin): VttPlugin {
  return plugin
}

type HookCallback<K extends VttHookName> = (payload: VttHookPayloads[K]) => void | Promise<void>

/**
 * In-process hook bus for trusted, build-time plugins. Campaign module manifests
 * are data-only and never execute remote JavaScript; this keeps the extension
 * boundary explicit and avoids turning a campaign import into code execution.
 */
export class VttHookBus implements VttPluginContext {
  private listeners = new Map<VttHookName, Set<(payload: unknown) => void | Promise<void>>>()

  on<K extends VttHookName>(hook: K, callback: HookCallback<K>): () => void {
    const listeners = this.listeners.get(hook) ?? new Set()
    listeners.add(callback as (payload: unknown) => void | Promise<void>)
    this.listeners.set(hook, listeners)
    return () => listeners.delete(callback as (payload: unknown) => void | Promise<void>)
  }

  async emit<K extends VttHookName>(hook: K, payload: VttHookPayloads[K]): Promise<void> {
    const callbacks = [...(this.listeners.get(hook) ?? [])]
    for (const callback of callbacks) await callback(payload)
  }
}

export class PluginRegistry {
  private cleanups = new Map<string, () => void>()
  readonly hooks: VttHookBus

  constructor(hooks = new VttHookBus()) {
    this.hooks = hooks
  }

  async register(plugin: VttPlugin, granted: PluginPermission[] = []): Promise<void> {
    if (plugin.manifest.apiVersion !== 1) throw new Error(`Plugin API incompatível: ${plugin.manifest.apiVersion}`)
    const required = plugin.manifest.permissions ?? []
    const denied = required.filter((permission) => !granted.includes(permission))
    if (denied.length) throw new Error(`Permissões não concedidas: ${denied.join(', ')}`)
    await this.unregister(plugin.manifest.id)
    const cleanup = await plugin.activate(this.hooks)
    if (cleanup) this.cleanups.set(plugin.manifest.id, cleanup)
  }

  async unregister(id: string): Promise<void> {
    this.cleanups.get(id)?.()
    this.cleanups.delete(id)
  }
}

export interface GameSystem {
  id: string
  name: string
  version: string
}

export const nullSystem: GameSystem = {
  id: 'none',
  name: 'Core (sem sistema)',
  version: '0.0.0',
}
