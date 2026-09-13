import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {pathToFileURL} from 'node:url';
const root=path.resolve(process.argv[2]), output=path.resolve(process.argv[3]);
process.chdir(root);
for(const file of ['parser.js','utils.js','utils-config.js','hist.js','render.js','render-dice.js']) await import(pathToFileURL(path.join(root,'js',file)));
(await import(pathToFileURL(path.join(root,'node/util.js')))).patchLoadJson();
const sources=JSON.parse(fs.readFileSync('data/books.json','utf8')).book;
const groups={bastions:{facility:'bastions'},vehicles:{vehicle:'vehicles',vehicleUpgrade:'vehicles'},decks:{deck:'decks',card:'decks'},recipes:{recipe:'recipes'},psionics:{psionic:'psionics'},rewards:{reward:'rewards'},deities:{deity:'deities'},languages:{language:'languages'},trapshazards:{trap:'hazards',hazard:'hazards'},objects:{object:'objects'},cultsboons:{cult:'cults',boon:'cults'}};
function text(v){if(typeof v==='string')return Renderer.stripTags(v);if(Array.isArray(v))return v.map(text).filter(Boolean).join('\n\n');if(v&&typeof v==='object')return ['name','entries','entry','items'].map(k=>text(v[k])).filter(Boolean).join('\n');return '';}
const rows=[],counts={};
for(const [file,keys] of Object.entries(groups)){
 const data=await DataUtil.loadJSON(`data/${file}.json`);
 for(const [key,kind] of Object.entries(keys)) for(const row of data[key]??[]){
  if(!row.name||!row.source)continue;
  const source=sources.find(s=>s.source===row.source);
  const edition=row.edition==='one'||(row.edition!=='classic'&&(source?.published??'')>='2024-09-17')?'5e-2024':'5e-2014';
  rows.push({slug:crypto.createHash('sha256').update(`${key}|${row.name}|${row.source}`).digest('hex'),kind,name:row.name,source:row.source,edition,level:null,data:{raw:row,description:text(row.entries??row),sourceName:source?.name??row.source,format:'5etools',optional:true}});
  counts[kind]=(counts[kind]??0)+1;
 }
}
fs.writeFileSync(output,rows.map(JSON.stringify).join('\n')+'\n');console.log(JSON.stringify(counts));
