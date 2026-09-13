// Use the upstream resolver for inherited stat blocks; no resolver is duplicated here.
import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
const checkout=path.resolve(process.argv[2]);
const destination=path.resolve(process.argv[3]);
process.chdir(checkout);
for(const file of ['parser.js','utils.js','utils-config.js','hist.js','render.js','render-dice.js']) await import(pathToFileURL(path.join(checkout,'js',file)));
const util=await import(pathToFileURL(path.join(checkout,'node/util.js')));
util.patchLoadJson();
const files=['races.json','backgrounds.json','feats.json','optionalfeatures.json','items.json','items-base.json','variantrules.json','conditionsdiseases.json','actions.json','senses.json','skills.json','books.json'];
for(const folder of ['class','bestiary','spells']) for(const name of fs.readdirSync(`data/${folder}`)) if(name.endsWith('.json')&&!/^(fluff-|foundry-)/.test(name)&&!['index.json','sources.json','legendarygroups.json','foundry.json'].includes(name)) files.push(`${folder}/${name}`);
let count=0;
for(const file of files){
 const data=await DataUtil.loadJSON(`data/${file}`);
 const output=path.join(destination,'data',file);fs.mkdirSync(path.dirname(output),{recursive:true});fs.writeFileSync(output,JSON.stringify(data));count++;
}
console.log(`${count} source files resolved with upstream DataUtil.`);
