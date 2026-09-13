// Resolve every supported 5etools data family with the upstream DataUtil.
// This keeps copy/inheritance semantics in the upstream project instead of
// duplicating them in the VTT importer.
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const checkout = path.resolve(process.argv[2]);
const destination = path.resolve(process.argv[3]);
process.chdir(checkout);
for (const file of ['parser.js', 'utils.js', 'utils-config.js', 'hist.js', 'render.js', 'render-dice.js']) {
  await import(pathToFileURL(path.join(checkout, 'js', file)));
}
const util = await import(pathToFileURL(path.join(checkout, 'node/util.js')));
util.patchLoadJson();

const files = new Set([
  'races.json', 'backgrounds.json', 'feats.json', 'optionalfeatures.json',
  'items.json', 'items-base.json', 'variantrules.json', 'conditionsdiseases.json',
  'actions.json', 'senses.json', 'skills.json', 'books.json',
  'bastions.json', 'vehicles.json', 'decks.json', 'recipes.json', 'psionics.json',
  'rewards.json', 'deities.json', 'languages.json', 'trapshazards.json',
  'objects.json', 'cultsboons.json', 'encounters.json', 'loot.json', 'magicvariants.json',
  'bestiary/legendarygroups.json',
]);
for (const folder of ['class', 'bestiary', 'spells']) {
  for (const name of fs.readdirSync(`data/${folder}`)) {
    if (!name.endsWith('.json') || /^(fluff-|foundry-)/.test(name)) continue;
    if (['index.json', 'sources.json', 'foundry.json', 'legendarygroups.json'].includes(name)) continue;
    files.add(`${folder}/${name}`);
  }
}

let count = 0;
for (const file of [...files].sort()) {
  const source = path.join(checkout, 'data', file);
  if (!fs.existsSync(source)) continue;
  const data = await DataUtil.loadJSON(`data/${file}`);
  const output = path.join(destination, 'data', file);
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, JSON.stringify(data));
  count++;
}
console.log(`${count} source files resolved with upstream DataUtil.`);
