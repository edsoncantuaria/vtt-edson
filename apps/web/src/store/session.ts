import { create } from 'zustand'
import type { Role, SceneState } from '@vtt/core'
import { emptySceneState } from '@vtt/core'

export type Tool = 'pan' | 'select' | 'token' | 'wall' | 'door' | 'light' | 'fog'

type User = { id: number; name: string; email: string }

type Session = {
  user: User | null
  token: string | null
  roomCode: string | null
  sceneId: number | null
  sceneName: string | null
  role: Role | null
  state: SceneState
  backgroundUrl: string | null
  tool: Tool
  error: string | null
  setAuth: (user: User, token: string) => void
  clearAuth: () => void
  setScene: (payload: {
    id: number
    name: string
    role: Role
    state: SceneState
    backgroundUrl?: string | null
    roomCode?: string
  }) => void
  patchState: (state: SceneState, backgroundUrl?: string | null) => void
  setTool: (tool: Tool) => void
  setError: (error: string | null) => void
}

export const useSession = create<Session>((set) => ({
  user: null,
  token: null,
  roomCode: null,
  sceneId: null,
  sceneName: null,
  role: null,
  state: emptySceneState(),
  backgroundUrl: null,
  tool: 'select',
  error: null,
  setAuth: (user, token) => set({ user, token }),
  clearAuth: () =>
    set({
      user: null,
      token: null,
      roomCode: null,
      sceneId: null,
      sceneName: null,
      role: null,
      state: emptySceneState(),
      backgroundUrl: null,
    }),
  setScene: ({ id, name, role, state, backgroundUrl, roomCode }) =>
    set({
      sceneId: id,
      sceneName: name,
      role,
      state,
      backgroundUrl: backgroundUrl ?? state.backgroundUrl ?? null,
      roomCode: roomCode ?? null,
    }),
  patchState: (state, backgroundUrl) =>
    set((s) => ({
      state,
      backgroundUrl: backgroundUrl ?? state.backgroundUrl ?? s.backgroundUrl,
    })),
  setTool: (tool) => set({ tool }),
  setError: (error) => set({ error }),
}))
