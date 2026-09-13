import { useState, type ReactNode } from "react";
import type { CatalogEntry } from "../lib/catalog";

export function CatalogImage({
  entry,
  thumbnail = false,
  children,
}: {
  entry: CatalogEntry;
  thumbnail?: boolean;
  children?: ReactNode;
}) {
  const variant =
    thumbnail && entry.data.tokenUrl
      ? "token"
      : entry.data.images?.[0]?.url
        ? "art"
        : entry.data.tokenUrl
          ? "token"
          : null;
  const url = variant ? `/api/catalog-media/${entry.id}/${variant}` : null;
  const [failed, setFailed] = useState<string | null>(null);
  if (!url || failed === url) return children ?? null;
  const image = (
    <img
      src={url}
      alt={thumbnail ? "" : entry.name}
      loading="lazy"
      decoding="async"
      className={thumbnail ? "catalog-thumbnail" : "catalog-art"}
      onError={() => setFailed(url)}
    />
  );
  if (thumbnail) return image;
  return (
    <figure className="catalog-figure">
      {image}
      {variant === "art" && entry.data.images?.[0]?.credit && (
        <figcaption>{String(entry.data.images[0].credit)}</figcaption>
      )}
    </figure>
  );
}
