# Core VTT MVP — Design Spec

**Date:** 2026-08-02  
**Status:** Approved (brainstorming) + Laravel backend

## Summary

Monorepo “GitLab para RPG”: engine de mesa agnóstica primeiro; D&D 5.2 como plugin futuro.

**MVP:** auth híbrida (login + código de sala), mesa tática Pixi (grid, tokens, walls, doors, lights, FOW manual), chat e `/roll NdM±K`, sync realtime.

## Stack

- `apps/web` — React, Vite, PixiJS, Zustand, Laravel Echo
- `apps/api` — Laravel 12, Eloquent, Sanctum (Bearer), Reverb
- `packages/contracts` — JSON Schema SceneState
- `packages/core` — tipos TS + dice helper cliente
- PostgreSQL via Docker Compose

## Domain

User → Campaign → Room (código) → Scene(s)  
Scene.state JSON: tokens, walls, doors, lights, fog, grid  
Roles: `gm` | `player`

## Permissions

| Action | GM | Player |
|--------|----|--------|
| Upload / walls / lights / fog | yes | no |
| Move any token | yes | own only |
| Chat / roll | yes | yes |

## Out of scope

D&D rules, Open5e, Keycloak, MinIO, token LOS, 3D dice, Tiled import.
