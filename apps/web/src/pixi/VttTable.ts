import { Application, Assets, Container, Graphics, Sprite, Text, TextStyle } from "pixi.js";
import type { Actor, AreaTemplate, AreaTemplateKind, SceneState, Role } from "@vtt/core";
import type { Tool } from "../store/session";
import type { CanvasObjectKind } from "../lib/canvasObjects";
import { publicAssetUrl } from "../lib/assets";
import {
  pointInAreaTemplate,
  segmentIntersectsSegment,
  visibilityPolygon,
} from "../lib/visibility";

export type TableCallbacks = {
  onTokenMove: (id: string, x: number, y: number) => void;
  onTokenCreate: (x: number, y: number) => void;
  onWallCreate: (x1: number, y1: number, x2: number, y2: number) => void;
  onDoorCreate: (x1: number, y1: number, x2: number, y2: number) => void;
  onLightCreate: (x: number, y: number) => void;
  onFogPaint: (x: number, y: number, w: number, h: number) => void;
  onDrawingCreate: (points: Array<{ x: number; y: number }>) => void;
  onLabelCreate: (x: number, y: number) => void;
  onPing: (x: number, y: number) => void;
  onRegionCreate: (x: number, y: number, w: number, h: number) => void;
  onCanvasSelect: (kind: CanvasObjectKind | null, id: string | null) => void;
  onCanvasMove: (kind: CanvasObjectKind, id: string, dx: number, dy: number) => void;
  onZoom: (zoom: number) => void;
  onActorOpen: (actorId: number) => void;
  onTokenSelection: (tokenIds: string[]) => void;
  onAreaTemplate: (template: AreaTemplate) => void;
};

type MountOpts = {
  host: HTMLElement;
  role: Role;
  userId: number;
  getTool: () => Tool;
  getActors: () => Actor[];
  canEditScene: boolean;
  callbacks: TableCallbacks;
};

export class VttTable {
  app: Application;
  world = new Container();
  bgLayer = new Container();
  tileLayer = new Container();
  gridLayer = new Graphics();
  canvasLayer = new Container();
  tokenLayer = new Container();
  wallLayer = new Graphics();
  lightMask = new Graphics();
  fogLayer = new Graphics();
  draft = new Graphics();
  host: HTMLElement;
  role: Role;
  userId: number;
  getTool: () => Tool;
  getActors: () => Actor[];
  canEditScene: boolean;
  callbacks: TableCallbacks;
  state: SceneState | null = null;
  backgroundUrl: string | null = null;
  bgSprite: Sprite | null = null;
  draggingToken: { id: string; g: Container; ox: number; oy: number } | null = null;
  draggingCanvas: { kind: CanvasObjectKind; id: string; startX: number; startY: number } | null =
    null;
  draftStart: { x: number; y: number } | null = null;
  draftPath: Array<{ x: number; y: number }> = [];
  panning = false;
  panOrigin = { x: 0, y: 0, wx: 0, wy: 0 };
  ready = false;
  private destroyed = false;
  private renderVersion = 0;
  private selectedTokenIds = new Set<string>();
  private activeTouches = new Map<number, { x: number; y: number }>();
  private pinchDistance = 0;
  private pinchMidpoint: { x: number; y: number } | null = null;

  constructor(opts: MountOpts) {
    this.app = new Application();
    this.host = opts.host;
    this.role = opts.role;
    this.userId = opts.userId;
    this.getTool = opts.getTool;
    this.getActors = opts.getActors;
    this.canEditScene = opts.canEditScene;
    this.callbacks = opts.callbacks;
  }

  async init() {
    const width = Math.max(this.host.clientWidth || 800, 320);
    const height = Math.max(this.host.clientHeight || 600, 240);

    await this.app.init({
      width,
      height,
      background: "#101719",
      antialias: true,
      resolution: Math.min(window.devicePixelRatio || 1, 2),
      autoDensity: true,
      // Avoid resizeTo/ResizePlugin — destroy races break _cancelResize under React remounts.
    });

    if (this.destroyed) {
      this.teardownApp();
      return;
    }

    this.host.replaceChildren();
    this.host.appendChild(this.app.canvas);
    this.world.addChild(this.bgLayer);
    this.world.addChild(this.tileLayer);
    this.world.addChild(this.gridLayer);
    this.world.addChild(this.canvasLayer);
    this.world.addChild(this.tokenLayer);
    this.world.addChild(this.wallLayer);
    this.world.addChild(this.lightMask);
    this.world.addChild(this.fogLayer);
    this.world.addChild(this.draft);
    this.app.stage.addChild(this.world);
    this.bindInput();
    this.ready = true;
    this.observeHostSize();
  }

  destroy() {
    this.destroyed = true;
    this.ready = false;
    this.resizeObserver?.disconnect();
    this.resizeObserver = null;
    this.teardownApp();
  }

  private resizeObserver: ResizeObserver | null = null;

  private observeHostSize() {
    this.resizeObserver = new ResizeObserver(() => {
      if (!this.ready || this.destroyed) return;
      const width = Math.max(this.host.clientWidth || 800, 320);
      const height = Math.max(this.host.clientHeight || 600, 240);
      this.app.renderer.resize(width, height);
    });
    this.resizeObserver.observe(this.host);
  }

  private teardownApp() {
    try {
      this.app.ticker?.stop();
    } catch {
      /* ignore */
    }

    try {
      const canvas = this.app.canvas as HTMLCanvasElement | undefined;
      canvas?.remove();
    } catch {
      /* ignore */
    }

    try {
      // Prefer renderer.destroy to avoid ResizePlugin.destroy when resizeTo was never used.
      this.app.renderer?.destroy(true);
    } catch {
      /* ignore */
    }
  }

  setRole(role: Role) {
    this.role = role;
  }

  zoom(factor: number, x = this.app.screen.width / 2, y = this.app.screen.height / 2) {
    const old = this.world.scale.x;
    const next = Math.min(3, Math.max(0.15, old * factor));
    this.world.position.set(
      x - ((x - this.world.x) * next) / old,
      y - ((y - this.world.y) * next) / old,
    );
    this.world.scale.set(next);
    this.callbacks.onZoom(next);
  }

  fit() {
    const w = this.bgSprite?.width || 2000;
    const h = this.bgSprite?.height || 1400;
    const scale = Math.min(
      1.5,
      (this.app.screen.width - 100) / w,
      (this.app.screen.height - 100) / h,
    );
    this.world.scale.set(Math.max(0.15, scale));
    this.world.position.set(
      (this.app.screen.width - w * scale) / 2,
      (this.app.screen.height - h * scale) / 2,
    );
    this.callbacks.onZoom(this.world.scale.x);
  }

  centerPoint() {
    return {
      x: (this.app.screen.width / 2 - this.world.x) / this.world.scale.x,
      y: (this.app.screen.height / 2 - this.world.y) / this.world.scale.y,
    };
  }

  async render(state: SceneState, backgroundUrl: string | null) {
    this.state = state;
    const version = ++this.renderVersion;
    if (backgroundUrl !== this.backgroundUrl) {
      let tex;
      try {
        tex = backgroundUrl ? await Assets.load(backgroundUrl) : null;
      } catch {
        if (this.destroyed || version !== this.renderVersion) return;
        this.drawGrid(state);
        this.drawWalls(state);
        if (!this.draggingToken) this.drawTokens(state);
        this.drawFogAndLight(state);
        throw new Error(
          "Não foi possível carregar o mapa. Verifique a conexão ou envie a imagem novamente.",
        );
      }
      if (this.destroyed || version !== this.renderVersion) return;
      for (const child of this.bgLayer.removeChildren()) child.destroy();
      this.backgroundUrl = backgroundUrl;
      this.bgSprite = tex ? new Sprite(tex) : null;
      if (backgroundUrl) {
        this.bgLayer.addChild(this.bgSprite!);
      }
      this.fit();
    }
    await this.drawCanvas(state, version);
    this.drawGrid(state);
    this.drawWalls(state);
    if (!this.draggingToken) this.drawTokens(state);
    this.drawFogAndLight(state);
  }

  private async drawCanvas(state: SceneState, version: number) {
    for (const child of this.tileLayer.removeChildren()) child.destroy({ children: true });
    for (const child of this.canvasLayer.removeChildren()) child.destroy({ children: true });

    for (const region of state.regions) {
      const color =
        region.behavior === "danger"
          ? 0xd94a5f
          : region.behavior === "difficult-terrain"
            ? 0xd9a441
            : 0x6fc3ff;
      this.canvasLayer.addChild(
        new Graphics()
          .rect(region.x, region.y, region.w, region.h)
          .fill({ color, alpha: 0.08 })
          .stroke({ width: 2, color, alpha: 0.55 }),
      );
    }

    for (const drawing of state.drawings) {
      if (drawing.points.length < 2) continue;
      const graphic = new Graphics();
      graphic.setStrokeStyle({
        width: drawing.width,
        color: Number.parseInt(drawing.stroke.slice(1), 16),
        alpha: 0.9,
      });
      graphic.moveTo(drawing.points[0].x, drawing.points[0].y);
      for (const point of drawing.points.slice(1)) graphic.lineTo(point.x, point.y);
      graphic.stroke();
      this.canvasLayer.addChild(graphic);
    }

    for (const label of state.labels) {
      const text = new Text({
        text: label.text,
        style: new TextStyle({
          fill: 0xf1ece1,
          fontSize: label.fontSize,
          fontFamily: "Archivo",
          fontWeight: "600",
          dropShadow: { color: 0x0d0a07, blur: 3, distance: 1, angle: Math.PI / 4 },
        }),
      });
      text.x = label.x;
      text.y = label.y;
      this.canvasLayer.addChild(text);
    }

    const now = Date.now();
    for (const ping of state.pings) {
      if (now - Date.parse(ping.createdAt) > 15000) continue;
      this.canvasLayer.addChild(
        new Graphics()
          .circle(ping.x, ping.y, 18)
          .stroke({ width: 4, color: 0x6fc3ff, alpha: 0.95 }),
      );
      const label = new Text({
        text: ping.label ?? ping.userName,
        style: { fill: 0xf1ece1, fontSize: 12, fontFamily: "Archivo", fontWeight: "600" },
      });
      label.x = ping.x + 24;
      label.y = ping.y - 8;
      this.canvasLayer.addChild(label);
    }

    await Promise.all(
      state.tiles.map(async (tile) => {
        try {
          const texture = await Assets.load(tile.url);
          if (this.destroyed || version !== this.renderVersion) return;
          const sprite = new Sprite(texture);
          sprite.x = tile.x;
          sprite.y = tile.y;
          sprite.width = tile.w;
          sprite.height = tile.h;
          sprite.alpha = tile.opacity;
          sprite.rotation = (tile.rotation * Math.PI) / 180;
          this.tileLayer.addChild(sprite);
        } catch {
          // A missing prop should not prevent the rest of the tactical map from rendering.
        }
      }),
    );
  }

  private drawGrid(state: SceneState) {
    const g = this.gridLayer;
    g.clear();
    const size = state.grid.size;
    const w = this.bgSprite?.width || 2000;
    const h = this.bgSprite?.height || 1400;

    if (!this.bgSprite) {
      g.rect(0, 0, w, h).fill({ color: 0x263638 });
    }

    g.setStrokeStyle({ width: 1, color: 0xa0b8b8, alpha: 0.2 });
    for (let x = state.grid.offsetX; x <= w; x += size) {
      g.moveTo(x, 0);
      g.lineTo(x, h);
    }
    for (let y = state.grid.offsetY; y <= h; y += size) {
      g.moveTo(0, y);
      g.lineTo(w, y);
    }
    g.stroke();
  }

  private drawWalls(state: SceneState) {
    const g = this.wallLayer;
    g.clear();
    for (const wall of state.walls) {
      g.setStrokeStyle({ width: 5, color: 0xf1ece1, alpha: 0.95 });
      g.moveTo(wall.x1, wall.y1);
      g.lineTo(wall.x2, wall.y2);
      g.stroke();
    }
    for (const door of state.doors) {
      const color = door.open
        ? 0x4fac7a
        : door.state === "locked"
          ? 0xd94a5f
          : door.state === "secret"
            ? 0x7f8c8d
            : 0xd9a441;
      g.setStrokeStyle({ width: 6, color });
      g.moveTo(door.x1, door.y1);
      g.lineTo(door.x2, door.y2);
      g.stroke();
    }
  }

  private drawTokens(state: SceneState) {
    this.draggingToken = null;
    for (const child of this.tokenLayer.removeChildren()) child.destroy({ children: true });
    const cell = state.grid.size;
    const actors = this.getActors();
    for (const token of state.tokens) {
      const c = new Container();
      c.x = token.x;
      c.y = token.y;
      c.alpha = token.detected ? 0.78 : 1;
      c.eventMode = "static";
      c.cursor = this.canEditScene || token.ownerUserId === this.userId ? "grab" : "default";
      (c as Container & { tokenId?: string }).tokenId = token.id;

      const actor = token.actorId ? actors.find((a) => a.id === token.actorId) : undefined;
      const radius = Math.max(2, (cell * (token.size || 1)) / 2 - 4);
      const ringColor = token.appearance
        ? Number.parseInt(token.appearance.border.slice(1), 16)
        : actor?.type === "monster"
          ? 0xd94a5f
          : actor?.type === "character"
            ? 0xa0b0ea
            : 0xd9a441;
      const circle = new Graphics()
        .circle(0, 0, radius)
        .fill({
          color: token.appearance
            ? Number.parseInt(token.appearance.background.slice(1), 16)
            : 0x2b241a,
        })
        .stroke({ width: 3, color: ringColor });
      c.addChild(circle);
      if (this.selectedTokenIds.has(token.id)) {
        c.addChild(
          new Graphics()
            .circle(0, 0, radius + 6)
            .stroke({ width: 3, color: 0x6fc3ff, alpha: 0.95 }),
        );
      }
      const initial = new Text({
        text: token.name.slice(0, 2).toUpperCase(),
        style: {
          fill: ringColor,
          fontFamily: "Archivo",
          fontSize: Math.max(14, radius * 0.65),
          fontWeight: "600",
        },
      });
      initial.anchor.set(0.5);
      c.addChild(initial);
      const imageUrl = publicAssetUrl(
        actor?.imgUrl || actor?.system.tokenImageUrl || token.imageUrl,
      );
      if (imageUrl) {
        void Assets.load(imageUrl)
          .then((texture) => {
            if (c.destroyed) return;
            const portrait = new Sprite(texture);
            portrait.anchor.set(0.5);
            const scale =
              (Math.max(1, radius * 2 - 4) / Math.min(texture.width, texture.height)) *
              (token.appearance?.zoom ?? 1);
            portrait.scale.set(scale);
            portrait.x = (token.appearance?.x ?? 0) * radius;
            portrait.y = (token.appearance?.y ?? 0) * radius;
            const mask = new Graphics().circle(0, 0, Math.max(1, radius - 2)).fill(0xffffff);
            c.addChild(mask, portrait);
            portrait.mask = mask;
            initial.visible = false;
          })
          .catch(() => {
            /* Initials remain visible when a portrait is unavailable. */
          });
      }

      if (actor) {
        const pct =
          actor.system.hp.max > 0
            ? Math.max(0, Math.min(1, actor.system.hp.value / actor.system.hp.max))
            : 0;
        const barW = radius * 2;
        const barY = -radius - 10;
        const bar = new Graphics();
        bar.rect(-radius, barY, barW, 4).fill({ color: 0x0d0a07, alpha: 0.85 });
        bar.rect(-radius, barY, barW * pct, 4).fill({
          color: pct > 0.5 ? 0x4fac7a : pct > 0.2 ? 0xd9a441 : 0xd94a5f,
        });
        c.addChild(bar);
      }

      const label = new Text({
        text: token.name,
        style: new TextStyle({
          fill: 0xf1ece1,
          fontSize: 13,
          fontFamily: "Archivo",
          fontWeight: "600",
          dropShadow: {
            color: 0x0d0a07,
            blur: 2,
            distance: 1,
            angle: Math.PI / 4,
          },
        }),
      });
      label.anchor.set(0.5, -1.15);
      c.addChild(label);
      this.tokenLayer.addChild(c);
    }
  }

  private drawFogAndLight(state: SceneState) {
    const fog = this.fogLayer;
    fog.clear();
    const w = this.bgSprite?.width || 2000;
    const h = this.bgSprite?.height || 1400;

    const blockers = [...state.walls, ...state.doors.filter((door) => !door.open)];
    const actors = this.getActors();
    const controlledTokens = state.tokens.filter((token) => token.ownerUserId === this.userId);
    const normalRadius = (state.vision.normalVisionFeet / 5) * state.grid.size;
    const observerSources = controlledTokens.map((token) => ({
      x: token.x,
      y: token.y,
      radius: state.vision.darkness
        ? ((actors.find((actor) => actor.id === token.actorId)?.system.senses.darkvision ?? 0) /
            5) *
          state.grid.size
        : normalRadius,
    }));
    const lightSources = state.lights.filter((light) =>
      controlledTokens.some((token) => {
        if (Math.hypot(light.x - token.x, light.y - token.y) > normalRadius) return false;
        const sight = { x1: token.x, y1: token.y, x2: light.x, y2: light.y };
        return !blockers.some((blocker) => segmentIntersectsSegment(sight, blocker));
      }),
    );
    const dynamicSources = [
      ...observerSources.filter((source) => source.radius > 0),
      ...lightSources,
    ];
    const cutVisibility = (source: { x: number; y: number; radius: number }) => {
      if (!state.vision.dynamic) {
        fog.circle(source.x, source.y, source.radius).cut();
        return;
      }
      const points = visibilityPolygon(source, source.radius, blockers);
      if (points.length > 2) fog.poly(points.flatMap((point) => [point.x, point.y])).cut();
    };

    if (this.canEditScene) {
      fog.rect(0, 0, w, h).fill({ color: 0x0d0a07, alpha: 0.5 });
      for (const r of state.fog.revealed) {
        fog.rect(r.x, r.y, r.w, r.h).cut();
      }
      if (state.vision.dynamic) for (const source of dynamicSources) cutVisibility(source);
    } else {
      fog.rect(0, 0, w, h).fill({ color: 0x0d0a07, alpha: 1 });
      for (const r of state.fog.revealed) {
        fog.rect(r.x, r.y, r.w, r.h).cut();
      }
      for (const source of dynamicSources) cutVisibility(source);
    }

    const lights = this.lightMask;
    lights.clear();
    for (const light of state.lights) {
      lights.circle(light.x, light.y, light.radius).fill({ color: 0xffe6a0, alpha: 0.14 });
      lights.circle(light.x, light.y, 7).fill({ color: 0xf0bd5f, alpha: 0.9 });
    }
  }

  private worldPoint(e: { clientX: number; clientY: number }) {
    const bounds = this.app.canvas.getBoundingClientRect();
    const x = (e.clientX - bounds.left - this.world.x) / this.world.scale.x;
    const y = (e.clientY - bounds.top - this.world.y) / this.world.scale.y;
    return { x, y };
  }

  private selectTokens(ids: string[], redraw = true) {
    this.selectedTokenIds = new Set(ids);
    this.callbacks.onTokenSelection(ids);
    if (redraw && this.state && !this.draggingToken) this.drawTokens(this.state);
  }

  private templateSelection(
    kind: AreaTemplateKind,
    origin: { x: number; y: number },
    end: { x: number; y: number },
  ) {
    if (!this.state) return [];
    return this.state.tokens
      .filter((token) => pointInAreaTemplate(token, kind, origin, end, this.state!.grid.size))
      .map((token) => token.id);
  }

  private drawTemplate(
    tool: Tool,
    origin: { x: number; y: number },
    end: { x: number; y: number },
  ) {
    const distance = Math.hypot(end.x - origin.x, end.y - origin.y);
    const gridSize = this.state?.grid.size ?? 70;
    const distanceFeet = (distance / gridSize) * 5;
    for (const child of this.draft.removeChildren()) child.destroy();
    this.draft.clear();
    this.draft.setStrokeStyle({ width: 3, color: 0x6fc3ff, alpha: 0.95 });
    if (tool === "ruler" || tool === "line") {
      if (tool === "line") {
        const angle = Math.atan2(end.y - origin.y, end.x - origin.x);
        const nx = (Math.cos(angle + Math.PI / 2) * gridSize) / 2;
        const ny = (Math.sin(angle + Math.PI / 2) * gridSize) / 2;
        this.draft
          .poly([
            origin.x + nx,
            origin.y + ny,
            end.x + nx,
            end.y + ny,
            end.x - nx,
            end.y - ny,
            origin.x - nx,
            origin.y - ny,
          ])
          .fill({ color: 0x6fc3ff, alpha: 0.18 });
      }
      this.draft.moveTo(origin.x, origin.y).lineTo(end.x, end.y).stroke();
    } else if (tool === "circle" || tool === "radius") {
      this.draft
        .circle(origin.x, origin.y, distance)
        .fill({ color: 0x6fc3ff, alpha: 0.16 })
        .stroke();
      if (tool === "radius") this.draft.moveTo(origin.x, origin.y).lineTo(end.x, end.y).stroke();
    } else if (tool === "cone") {
      const angle = Math.atan2(end.y - origin.y, end.x - origin.x);
      const half = Math.atan(0.5);
      const left = {
        x: origin.x + Math.cos(angle - half) * distance,
        y: origin.y + Math.sin(angle - half) * distance,
      };
      const right = {
        x: origin.x + Math.cos(angle + half) * distance,
        y: origin.y + Math.sin(angle + half) * distance,
      };
      this.draft
        .poly([origin.x, origin.y, left.x, left.y, right.x, right.y])
        .fill({ color: 0x6fc3ff, alpha: 0.18 })
        .stroke();
    }
    const label = new Text({
      text: `${Math.round(distanceFeet * 10) / 10} ft`,
      style: { fill: 0xf1ece1, fontFamily: "Archivo", fontSize: 13, fontWeight: "600" },
    });
    label.x = end.x + 8;
    label.y = end.y + 8;
    this.draft.addChild(label);
  }

  private snap(x: number, y: number) {
    if (!this.state?.grid.snap) return { x, y };
    const s = this.state.grid.size;
    return {
      x: Math.floor((x - this.state.grid.offsetX) / s) * s + s / 2 + this.state.grid.offsetX,
      y: Math.floor((y - this.state.grid.offsetY) / s) * s + s / 2 + this.state.grid.offsetY,
    };
  }

  private canvasHit(x: number, y: number): { kind: CanvasObjectKind; id: string } | null {
    if (!this.state || !this.canEditScene) return null;
    for (const label of [...this.state.labels].reverse()) {
      const w = Math.max(label.fontSize * 2, label.text.length * label.fontSize * 0.55);
      if (
        x >= label.x - 6 &&
        x <= label.x + w + 6 &&
        y >= label.y - 6 &&
        y <= label.y + label.fontSize * 1.5
      )
        return { kind: "labels", id: label.id };
    }
    for (const tile of [...this.state.tiles].reverse()) {
      if (x >= tile.x && x <= tile.x + tile.w && y >= tile.y && y <= tile.y + tile.h)
        return { kind: "tiles", id: tile.id };
    }
    for (const region of [...this.state.regions].reverse()) {
      if (x >= region.x && x <= region.x + region.w && y >= region.y && y <= region.y + region.h)
        return { kind: "regions", id: region.id };
    }
    for (const drawing of [...this.state.drawings].reverse()) {
      for (let i = 1; i < drawing.points.length; i++) {
        if (
          this.pointSegmentDistance(x, y, drawing.points[i - 1], drawing.points[i]) <=
          Math.max(8, drawing.width + 4)
        )
          return { kind: "drawings", id: drawing.id };
      }
    }
    return null;
  }

  private pointSegmentDistance(
    x: number,
    y: number,
    a: { x: number; y: number },
    b: { x: number; y: number },
  ) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (dx === 0 && dy === 0) return Math.hypot(x - a.x, y - a.y);
    const t = Math.max(0, Math.min(1, ((x - a.x) * dx + (y - a.y) * dy) / (dx * dx + dy * dy)));
    return Math.hypot(x - (a.x + t * dx), y - (a.y + t * dy));
  }

  private bindInput() {
    const canvas = this.app.canvas;
    canvas.style.touchAction = "none";
    canvas.addEventListener("wheel", (e) => {
      e.preventDefault();
      const factor = e.deltaY > 0 ? 0.9 : 1.1;
      const bounds = canvas.getBoundingClientRect();
      this.zoom(factor, e.clientX - bounds.left, e.clientY - bounds.top);
    });

    canvas.addEventListener("dblclick", (e) => {
      if (this.getTool() !== "select") return;
      const p = this.worldPoint(e);
      const token = this.state?.tokens.find(
        (t) => Math.hypot(t.x - p.x, t.y - p.y) < (this.state!.grid.size * t.size) / 2,
      );
      if (token?.actorId) this.callbacks.onActorOpen(token.actorId);
    });

    canvas.addEventListener("pointerdown", (e) => {
      canvas.setPointerCapture(e.pointerId);
      if (e.pointerType === "touch") {
        this.activeTouches.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (this.activeTouches.size >= 2) {
          const [a, b] = [...this.activeTouches.values()];
          this.pinchDistance = Math.hypot(b.x - a.x, b.y - a.y);
          this.pinchMidpoint = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
          this.panning = false;
          this.draggingToken = null;
          this.draftStart = null;
          for (const child of this.draft.removeChildren()) child.destroy();
          this.draft.clear();
          if (this.state) this.drawTokens(this.state);
          return;
        }
      }
      const tool = this.getTool();
      const p = this.worldPoint(e);
      if (tool === "ping") {
        this.callbacks.onPing(p.x, p.y);
        return;
      }
      if (tool === "label" && this.canEditScene) {
        this.callbacks.onLabelCreate(p.x, p.y);
        return;
      }
      if (tool === "pan" || e.button === 1 || e.button === 2) {
        this.panning = true;
        this.panOrigin = {
          x: e.clientX,
          y: e.clientY,
          wx: this.world.x,
          wy: this.world.y,
        };
        return;
      }

      if (tool === "select" || tool === "token") {
        const hit = this.tokenLayer.children.find((c) => {
          const dx = c.x - p.x;
          const dy = c.y - p.y;
          const token = this.state?.tokens.find(
            (item) => item.id === (c as Container & { tokenId?: string }).tokenId,
          );
          return Math.hypot(dx, dy) < ((this.state?.grid.size || 70) * (token?.size ?? 1)) / 2;
        }) as (Container & { tokenId?: string }) | undefined;
        if (hit?.tokenId) {
          const token = this.state?.tokens.find((t) => t.id === hit.tokenId);
          if (tool === "select") {
            const additive = e.shiftKey || e.ctrlKey || e.metaKey;
            const next = additive
              ? this.selectedTokenIds.has(hit.tokenId)
                ? [...this.selectedTokenIds].filter((id) => id !== hit.tokenId)
                : [...this.selectedTokenIds, hit.tokenId]
              : [hit.tokenId];
            this.selectTokens(next, false);
          }
          if (!this.canEditScene && token?.ownerUserId !== this.userId) {
            if (tool === "select" && this.state) this.drawTokens(this.state);
            return;
          }
          this.draggingToken = {
            id: hit.tokenId,
            g: hit,
            ox: p.x - hit.x,
            oy: p.y - hit.y,
          };
          return;
        }
        if (tool === "select" && this.canEditScene) {
          const canvasHit = this.canvasHit(p.x, p.y);
          if (canvasHit) {
            this.selectTokens([], false);
            this.draggingCanvas = { ...canvasHit, startX: p.x, startY: p.y };
            this.callbacks.onCanvasSelect(canvasHit.kind, canvasHit.id);
            return;
          }
          this.callbacks.onCanvasSelect(null, null);
        }
        if (tool === "select") {
          if (e.pointerType === "touch") {
            this.panning = true;
            this.panOrigin = {
              x: e.clientX,
              y: e.clientY,
              wx: this.world.x,
              wy: this.world.y,
            };
          } else if (!e.shiftKey && !e.ctrlKey && !e.metaKey) {
            this.selectTokens([]);
          }
        }
        if (tool === "token" && this.canEditScene) {
          const s = this.snap(p.x, p.y);
          this.callbacks.onTokenCreate(s.x, s.y);
        }
        return;
      }

      if (["ruler", "circle", "cone", "line", "radius"].includes(tool)) {
        this.draftStart = p;
        return;
      }

      if (!this.canEditScene) return;
      if (tool === "draw") {
        this.draftStart = p;
        this.draftPath = [p];
        return;
      }
      if (tool === "wall" || tool === "door" || tool === "fog" || tool === "region") {
        this.draftStart = p;
        return;
      }
      if (tool === "light") {
        this.callbacks.onLightCreate(p.x, p.y);
      }
    });

    canvas.addEventListener("pointermove", (e) => {
      if (e.pointerType === "touch" && this.activeTouches.has(e.pointerId)) {
        this.activeTouches.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (this.activeTouches.size >= 2) {
          const [a, b] = [...this.activeTouches.values()];
          const distance = Math.hypot(b.x - a.x, b.y - a.y);
          const midpoint = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
          if (this.pinchMidpoint) {
            this.world.x += midpoint.x - this.pinchMidpoint.x;
            this.world.y += midpoint.y - this.pinchMidpoint.y;
          }
          if (this.pinchDistance > 0 && distance > 0) {
            const bounds = canvas.getBoundingClientRect();
            this.zoom(
              distance / this.pinchDistance,
              midpoint.x - bounds.left,
              midpoint.y - bounds.top,
            );
          }
          this.pinchDistance = distance;
          this.pinchMidpoint = midpoint;
          return;
        }
      }
      if (this.panning) {
        this.world.x = this.panOrigin.wx + (e.clientX - this.panOrigin.x);
        this.world.y = this.panOrigin.wy + (e.clientY - this.panOrigin.y);
        return;
      }
      const p = this.worldPoint(e);
      if (this.draggingToken) {
        const s = this.snap(p.x - this.draggingToken.ox, p.y - this.draggingToken.oy);
        this.draggingToken.g.x = s.x;
        this.draggingToken.g.y = s.y;
        return;
      }
      if (this.draggingCanvas) {
        for (const child of this.draft.removeChildren()) child.destroy();
        this.draft.clear();
        this.draft.setStrokeStyle({ width: 2, color: 0x6fc3ff, alpha: 0.9 });
        this.draft.moveTo(this.draggingCanvas.startX, this.draggingCanvas.startY);
        this.draft.lineTo(p.x, p.y);
        this.draft.stroke();
        return;
      }
      if (this.draftStart) {
        const tool = this.getTool();
        if (tool === "draw") {
          this.draftPath.push(p);
          for (const child of this.draft.removeChildren()) child.destroy();
          this.draft.clear();
          this.draft.setStrokeStyle({ width: 3, color: 0x6fc3ff, alpha: 0.9 });
          this.draft.moveTo(this.draftPath[0].x, this.draftPath[0].y);
          for (const point of this.draftPath.slice(1)) this.draft.lineTo(point.x, point.y);
          this.draft.stroke();
          return;
        }
        if (["ruler", "circle", "cone", "line", "radius"].includes(tool)) {
          this.drawTemplate(tool, this.draftStart, p);
          return;
        }
        for (const child of this.draft.removeChildren()) child.destroy();
        this.draft.clear();
        if (tool === "fog" || tool === "region") {
          const x = Math.min(this.draftStart.x, p.x);
          const y = Math.min(this.draftStart.y, p.y);
          const w = Math.abs(p.x - this.draftStart.x);
          const h = Math.abs(p.y - this.draftStart.y);
          this.draft
            .rect(x, y, w, h)
            .fill({ color: tool === "region" ? 0x6fc3ff : 0x52b788, alpha: 0.22 });
        } else {
          this.draft.setStrokeStyle({ width: 3, color: 0xd9a441 });
          this.draft.moveTo(this.draftStart.x, this.draftStart.y);
          this.draft.lineTo(p.x, p.y);
          this.draft.stroke();
        }
      }
    });

    const end = (e: PointerEvent) => {
      if (canvas.hasPointerCapture(e.pointerId)) canvas.releasePointerCapture(e.pointerId);
      const wasPinching = this.pinchDistance > 0 && this.activeTouches.size >= 2;
      if (e.pointerType === "touch") {
        this.activeTouches.delete(e.pointerId);
        if (this.activeTouches.size < 2) {
          this.pinchDistance = 0;
          this.pinchMidpoint = null;
        }
        if (wasPinching) return;
      }
      const p = this.worldPoint(e);
      if (this.panning) {
        this.panning = false;
        return;
      }
      if (this.draggingToken) {
        const s = this.snap(this.draggingToken.g.x, this.draggingToken.g.y);
        this.callbacks.onTokenMove(this.draggingToken.id, s.x, s.y);
        this.draggingToken = null;
        return;
      }
      if (this.draggingCanvas) {
        const active = this.draggingCanvas;
        const dx = p.x - active.startX;
        const dy = p.y - active.startY;
        this.draggingCanvas = null;
        for (const child of this.draft.removeChildren()) child.destroy();
        this.draft.clear();
        if (Math.abs(dx) > 1 || Math.abs(dy) > 1)
          this.callbacks.onCanvasMove(active.kind, active.id, dx, dy);
        return;
      }
      if (this.draftStart && this.canEditScene && this.getTool() === "draw") {
        if (this.draftPath.length > 1) this.callbacks.onDrawingCreate(this.draftPath);
        this.draftPath = [];
        this.draftStart = null;
        for (const child of this.draft.removeChildren()) child.destroy();
        this.draft.clear();
        return;
      }
      if (
        this.draftStart &&
        this.canEditScene &&
        ["wall", "door", "fog", "region"].includes(this.getTool())
      ) {
        const tool = this.getTool();
        if (tool === "wall")
          this.callbacks.onWallCreate(this.draftStart.x, this.draftStart.y, p.x, p.y);
        if (tool === "door")
          this.callbacks.onDoorCreate(this.draftStart.x, this.draftStart.y, p.x, p.y);
        if (tool === "fog") {
          const x = Math.min(this.draftStart.x, p.x);
          const y = Math.min(this.draftStart.y, p.y);
          const w = Math.abs(p.x - this.draftStart.x);
          const h = Math.abs(p.y - this.draftStart.y);
          if (w > 4 && h > 4) this.callbacks.onFogPaint(x, y, w, h);
        }
        if (tool === "region") {
          const x = Math.min(this.draftStart.x, p.x);
          const y = Math.min(this.draftStart.y, p.y);
          const w = Math.abs(p.x - this.draftStart.x);
          const h = Math.abs(p.y - this.draftStart.y);
          if (w > 4 && h > 4) this.callbacks.onRegionCreate(x, y, w, h);
        }
        this.draftStart = null;
        for (const child of this.draft.removeChildren()) child.destroy();
        this.draft.clear();
        return;
      }
      if (this.draftStart) {
        const tool = this.getTool();
        if (["circle", "cone", "line", "radius"].includes(tool)) {
          const kind = tool as AreaTemplateKind;
          const tokenIds = this.templateSelection(kind, this.draftStart, p);
          const actors = new Set(
            (this.state?.tokens ?? [])
              .filter((token) => tokenIds.includes(token.id) && token.actorId != null)
              .map((token) => token.actorId as number),
          );
          const distanceFeet =
            (Math.hypot(p.x - this.draftStart.x, p.y - this.draftStart.y) /
              (this.state?.grid.size ?? 70)) *
            5;
          this.selectTokens(tokenIds);
          this.callbacks.onAreaTemplate({
            kind,
            origin: this.draftStart,
            end: p,
            distanceFeet,
            tokenIds,
            actorIds: [...actors],
          });
        }
        this.draftStart = null;
        for (const child of this.draft.removeChildren()) child.destroy();
        this.draft.clear();
      }
    };
    canvas.addEventListener("pointerup", end);
    canvas.addEventListener("pointercancel", (e) => {
      this.activeTouches.delete(e.pointerId);
      this.pinchDistance = 0;
      this.pinchMidpoint = null;
      this.panning = false;
      this.draggingToken = null;
      this.draftStart = null;
      this.draftPath = [];
      for (const child of this.draft.removeChildren()) child.destroy();
      this.draft.clear();
      if (this.state) this.drawTokens(this.state);
    });
    canvas.addEventListener("contextmenu", (e) => e.preventDefault());
  }
}
