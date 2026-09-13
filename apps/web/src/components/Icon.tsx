const paths = {
  dice: "M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 0L7 9l5 10 5-10-5-7ZM3 7l4 2h10l4-2M3 17l9 2 9-2M12 19v3",
  home: "m3 10 9-7 9 7v11h-6v-7H9v7H3V10Z",
  plus: "M12 5v14M5 12h14",
  arrow: "M4 12h16m-6-6 6 6-6 6",
  back: "M20 12H4m6-6-6 6 6 6",
  chevron: "m9 5 7 7-7 7",
  close: "m6 6 12 12M6 18 18 6",
  search: "M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z",
  users:
    "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M13 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z",
  shield: "m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z",
  book: "M12 5v16M12 5C8 2 4 3 2 4v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-2-1-6-2-10 1Z",
  chat: "M21 11a9 9 0 0 1-9 9 10 10 0 0 1-4-1l-6 2 2-6a10 10 0 0 1-1-4 9 9 0 0 1 18 0ZM8 11h8",
  swords: "m3 3 5 1 13 13-4 4L4 8 3 3Zm18 0-5 1-3 3M8 13l-5 4 4 4 5-5M14 18l7-7M3 11l7 7",
  map: "m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16",
  cursor: "m4 3 16 9-7 2-3 7L4 3Z",
  hand: "M8 13V5a2 2 0 0 1 4 0v7-8a2 2 0 0 1 4 0v8-6a2 2 0 0 1 4 0v9c0 4-3 7-7 7-3 0-5-2-7-5l-3-4a2 2 0 0 1 3-3l2 3Z",
  token: "M20 12a8 8 0 1 1-16 0 8 8 0 0 1 16 0ZM12 8v8m-4-4h8",
  wall: "M3 4h18v16H3V4Zm0 8h18M9 4v8m6 0v8",
  door: "M4 21h16M6 21V3h12v18M14 12h.01",
  light: "M9 18h6m-6 3h6M8 14a6 6 0 1 1 8 0l-1 2H9l-1-2Z",
  fog: "M3 15h18M5 19h14M3 11h18M6 7h12",
  upload: "M12 16V3m-5 5 5-5 5 5M3 15v6h18v-6",
  grid: "M3 3h18v18H3V3Zm6 0v18m6-18v18M3 9h18M3 15h18",
  fit: "M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M8 8h8v8H8V8Z",
  minus: "M5 12h14",
  help: "M9 9a3 3 0 1 1 5 2c-2 1-2 2-2 3m0 3h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z",
  copy: "M9 9h12v12H9V9ZM5 15H3V3h12v2",
  check: "m5 12 4 4L20 5",
  logout: "M9 3H3v18h6m5-14 5 5-5 5m-7-5h12",
  heart: "M20 4c-3-2-6 0-8 2-2-2-5-4-8-2-5 4 0 10 8 17 8-7 13-13 8-17Z",
  settings: "M4 7h16M4 17h16M8 4v6m8 4v6",
  download: "M12 3v13m-5-5 5 5 5-5M3 17v4h18v-4",
  trash: "M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7",
  spark: "m12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3 3-7Z",
  journal: "M5 3h11a3 3 0 0 1 3 3v15H8a3 3 0 0 0-3 3V3Zm3 18h11M9 8h6m-6 4h6",
  music: "M9 18V5l11-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm11-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z",
} as const;
export type IconName = keyof typeof paths;
export function Icon({ name, size = 20 }: { name: IconName; size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.65"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d={paths[name]} />
    </svg>
  );
}
export function Brand() {
  return (
    <span className="brand">
      <span className="brand__mark">
        <Icon name="dice" size={27} />
      </span>
      <span>
        VTT <b>Edson</b>
        <small>SUA PRÓXIMA AVENTURA</small>
      </span>
    </span>
  );
}
