import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { getToken } from './api'

declare global {
  interface Window {
    Pusher: typeof Pusher
    Echo?: Echo<'reverb'>
  }
}

window.Pusher = Pusher

export function createEcho() {
  const key = import.meta.env.VITE_REVERB_APP_KEY || 'vttlocalkey'
  const host = import.meta.env.VITE_REVERB_HOST || 'localhost'
  const port = Number(import.meta.env.VITE_REVERB_PORT || 8080)
  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'

  return new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: {
      headers: {
        Authorization: `Bearer ${getToken() ?? ''}`,
        Accept: 'application/json',
      },
    },
  })
}
