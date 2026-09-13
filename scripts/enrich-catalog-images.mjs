// Attach upstream media references without downloading the image collection.
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
const [checkoutArg, inputArg, outputArg] = process.argv.slice(2);
const checkout = path.resolve(checkoutArg);
const input = path.resolve(inputArg);
const output = path.resolve(outputArg);
for (const file of ['parser.js', 'utils.js', 'utils-config.js', 'hist.js', 'render.js']) {
  await import(pathToFileURL(path.join(checkout, 'js', file)));
}
Renderer.get().setBaseMediaUrl('img', 'https://5e.tools/img/');
function safeUrl(value) {
  try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password ? url.href : null; } catch { return null; }
}
const fluff = new Map();
for (const [folder, key] of [['spells', 'spellFluff'], ['bestiary', 'monsterFluff']]) {
  for (const file of fs.readdirSync(path.join(checkout, 'data', folder)).filter(name => name.startsWith('fluff-') && name.endsWith('.json'))) {
    for (const entry of JSON.parse(fs.readFileSync(path.join(checkout, 'data', folder, file), 'utf8'))[key] ?? []) {
      fluff.set(`${folder}|${entry.source}|${entry.name}`, entry);
    }
  }
}
function images(entry, folder, seen = new Set()) {
  if (!entry) return [];
  const key = `${folder}|${entry.source}|${entry.name}`;
  if (seen.has(key)) return [];
  seen.add(key);
  const own = (entry.images ?? []).flatMap(image => {
    const href = image.href;
    const url = href?.type === 'internal' ? safeUrl('https://5e.tools/img/' + href.path.split('/').map(encodeURIComponent).join('/')) : safeUrl(href?.url);
    return url ? [{url, credit: image.credit ?? null}] : [];
  });
  if (own.length || !entry._copy) return own;
  return images(fluff.get(`${folder}|${entry._copy.source}|${entry._copy.name}`), folder, seen);
}
let tokens = 0, illustrations = 0;
const rows = fs.readFileSync(input, 'utf8').split('\n').filter(Boolean).map(line => {
  const row = JSON.parse(line);
  const raw = row.data.raw ?? {};
  if (row.kind === 'monsters' && Renderer.monster.hasToken(raw)) {
    const tokenUrl = safeUrl(Renderer.monster.getTokenUrl(raw, {isUrlEncode: true}));
    if (tokenUrl) { row.data.tokenUrl = tokenUrl; tokens++; }
  }
  if (['spells', 'monsters'].includes(row.kind)) {
    const folder = row.kind === 'spells' ? 'spells' : 'bestiary';
    row.data.images = images(fluff.get(`${folder}|${raw.source}|${raw.name}`), folder);
    if (row.data.images.length) illustrations++;
  }
  return JSON.stringify(row);
});
fs.writeFileSync(output, rows.join('\n') + '\n');
console.log(JSON.stringify({entries: rows.length, tokens, illustrations}));
