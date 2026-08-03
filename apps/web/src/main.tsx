import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App'

// Sem StrictMode: Pixi Application + resize plugin quebram no double-mount de efeitos.
createRoot(document.getElementById('root')!).render(<App />)
