# VTT Edson

Core VTT modular (mesa agnóstica). Backend **Laravel 12** + frontend **React/Vite/PixiJS**.

## Subir

```bash
# Docker (Postgres + API + Reverb) — use contexto Docker local
export DOCKER_CONTEXT=default
docker compose up --build -d

# Frontend
pnpm install
pnpm dev:web
```

- Web: http://localhost:5173  
- API: http://localhost:8000  
- Reverb WS: ws://localhost:8080  

### Contas seed

- `gm@vtt.local` / `password`
- `player@vtt.local` / `password`

## Fluxo manual

1. Login como GM → criar mesa → copiar código  
2. Outro browser: login player → entrar com código  
3. GM: upload mapa, desenhar paredes/portas/luz/fog, criar tokens  
4. Player: mover o próprio token, `/roll 2d6+3` no chat  

## Estrutura

```
apps/api      Laravel (Sanctum Bearer + Reverb)
apps/web      React + Pixi
packages/core tipos + dice helper
packages/contracts JSON Schema SceneState
```
