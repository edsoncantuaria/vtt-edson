"""Export book/adventure chapters from a local 5etools checkout to catalog NDJSON."""
import json,pathlib,hashlib,sys
root=pathlib.Path(sys.argv[1])/'data'
out=pathlib.Path(sys.argv[2])
def text(v):
 if isinstance(v,str): return v
 if isinstance(v,list):return '\n\n'.join(filter(None,map(text,v)))
 if isinstance(v,dict):return '\n\n'.join(filter(None,[text(v.get(k)) for k in ['name','entries','entry','items','rows']]))
 return ''
count=0
with out.open('w') as target:
 for kind,index,folder in [('books','book','book'),('adventures','adventure','adventure')]:
  for meta in json.loads((root/(index+'s.json')).read_text())[index]:
   source=meta.get('source',meta['id']);p=root/folder/(folder+'-'+meta['id'].lower()+'.json')
   if not p.exists():continue
   for i,chapter in enumerate(json.loads(p.read_text())['data']):
    name=meta['name']+' · '+str(chapter.get('name',f'Capítulo {i+1}'))
    row={'slug':hashlib.sha256(f'{kind}|{source}|{i}'.encode()).hexdigest(),'kind':kind,'name':name,'source':source,'edition':'5e-2024' if meta.get('published','')>='2024-09-17' else '5e-2014','level':None,'data':{'description':text(chapter),'raw':chapter,'sourceName':meta['name'],'chapter':i,'format':'5etools'}}
    target.write(json.dumps(row,ensure_ascii=False)+'\n');count+=1
print(count,'chapters exported')
