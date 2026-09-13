/** Laravel public-storage assets use the same-origin proxy, including in WebGL. */
export function publicAssetUrl(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url, "http://vtt.local");
    return parsed.pathname.startsWith("/storage/") ? parsed.pathname + parsed.search : url;
  } catch {
    return url;
  }
}
