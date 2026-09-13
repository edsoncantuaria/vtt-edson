"""Normalize a local 5etools checkout, retaining provenance and edition.
Usage: python3 scripts/build-compendium.py CHECKOUT OUTPUT.ndjson
Entries requiring unsupported copy modifiers are reported, never fabricated.
"""
import copy
import hashlib
import json
import pathlib
import re
import sys
from collections import Counter

root, output = pathlib.Path(sys.argv[1]) / 'data', pathlib.Path(sys.argv[2])

def read(path):
    return json.loads(path.read_text())

books = {r['source']: r for r in read(root / 'books.json')['book']}
raw = []
files = [root / n for n in ['races.json', 'backgrounds.json', 'feats.json', 'optionalfeatures.json', 'items.json', 'items-base.json', 'variantrules.json', 'conditionsdiseases.json', 'actions.json', 'senses.json', 'skills.json']]
for directory, index in [('spells','spell'), ('bestiary','monster'), ('class','class')]:
    files += [p for p in sorted((root / directory).glob('*.json')) if not p.name.startswith(('fluff-', 'foundry-')) and p.name not in ('index.json','sources.json','legendarygroups.json','foundry.json')]
kinds = {'spell':'spells','monster':'monsters','item':'items','baseitem':'items','class':'classes','subclass':'subclasses','race':'races','background':'backgrounds','feat':'feats','optionalfeature':'features','classFeature':'features','subclassFeature':'features','variantrule':'rules','condition':'rules','disease':'rules','action':'rules','sense':'rules','skill':'rules'}
for path in files:
    if not path.exists(): continue
    data = read(path)
    for key, kind in kinds.items():
        for row in data.get(key, []):
            if row.get('name') and row.get('source'):
                raw.append((kind, key, row))
lookup = {(key, row['name'].lower(), row['source'].lower()): row for _, key, row in raw}

def resolve(key, row, seen=()):
    parent = row.get('_copy')
    if not parent:
        return copy.deepcopy(row)
    identity = (key, parent['name'].lower(), parent['source'].lower())
    if identity in seen or identity not in lookup or parent.get('_mod'):
        return None
    base = resolve(key, lookup[identity], (*seen, identity))
    if base is None:
        return None
    # Source-specific licensing flags are not inherited by copies.
    for flag in ['srd','srd52','basicRules','basicRules2024','reprintedAs']:
        base.pop(flag, None)
    base.update(copy.deepcopy(row)); base.pop('_copy', None)
    return base

def text(value):
    if isinstance(value, str):
        def tag(m):
            tag, body = m.group(1), m.group(2) or ''
            bits = body.split('|')
            if tag == 'h': return 'Dano: '
            if tag in ('hit','dc'): return ('+' if tag == 'hit' and not body.startswith('-') else '') + body
            return bits[2] if len(bits) > 2 and bits[2] else bits[0]
        for _ in range(5):
            value = re.sub(r'\{@(\w+)\s*([^{}]*)\}', tag, value)
        return value
    if isinstance(value, list): return '\n\n'.join(filter(None, (text(v) for v in value)))
    if isinstance(value, dict):
        if value.get('type') == 'table':
            return '\n'.join(' | '.join(text(c) for c in row) for row in [value.get('colLabels', []), *value.get('rows', [])])
        return '\n'.join(filter(None, [text(value.get('name', '')), *[text(value.get(k, '')) for k in ['entries','entry','items','text']]]))
    return str(value) if value is not None else ''

features = {}
subclass_features = {}
for _, key, row in raw:
    if key == 'classFeature':
        features[(row['name'].lower(), row.get('className','').lower(), row.get('classSource','PHB').lower(), str(row.get('level')), row['source'].lower())] = row
    elif key == 'subclassFeature':
        subclass_features[(
            row['name'].lower(),
            row.get('className','').lower(),
            row.get('classSource','PHB').lower(),
            row.get('subclassShortName','').lower(),
            row.get('subclassSource', row.get('source','')).lower(),
            str(row.get('level')),
            row['source'].lower(),
        )] = row

def feature(ref):
    ref = ref if isinstance(ref, str) else ref.get('classFeature', '')
    bits = ref.split('|')
    if len(bits) < 4: return None
    name, cls, source, level = bits[:4]; source = source or 'PHB'
    return features.get((name.lower(), cls.lower(), source.lower(), level, (bits[4] if len(bits)>4 and bits[4] else source).lower()))

def subclass_feature(ref):
    ref = ref if isinstance(ref, str) else ref.get('subclassFeature', '')
    bits = ref.split('|')
    if len(bits) < 6: return None
    name, cls, class_source, short_name, subclass_source, level = bits[:6]
    class_source = class_source or 'PHB'
    subclass_source = subclass_source or class_source
    feature_source = bits[6] if len(bits) > 6 and bits[6] else subclass_source
    return subclass_features.get((name.lower(), cls.lower(), class_source.lower(), short_name.lower(), subclass_source.lower(), level, feature_source.lower()))

counts, skipped, seen = Counter(), [], set()
with output.open('w') as dest:
    for kind, key, original in raw:
        row = resolve(key, original)
        if row is None:
            skipped.append({'kind': kind, 'name': original['name'], 'source': original['source'], 'reason':'unresolved copy or copy modifiers'})
            continue
        source = row['source']; book = books.get(source, {})
        edition = '5e-2024' if row.get('edition') == 'one' or (row.get('edition') != 'classic' and (source in ['XPHB','XDMG','XMM'] or book.get('published','') >= '2024-09-17')) else '5e-2014'
        identity = '|'.join(str(row.get(k,'')) for k in ['name','source','className','classSource','subclassShortName','level'])
        slug = hashlib.sha256((key+'|'+identity).encode()).hexdigest()
        if slug in seen: continue
        seen.add(slug)
        desc = text([row.get('entries', []), row.get('additionalEntries', []), row.get('entriesHigherLevel', [])])
        data = {'description':desc, 'sourceName':book.get('name',source), 'page':row.get('page'), 'raw':row, 'format':'5etools', 'editionBasis':'explicit' if row.get('edition') else 'source publication', 'srd':bool(row.get('srd52') if edition=='5e-2024' else row.get('srd'))}
        if kind == 'classes':
            data['levelFeatures'] = [{'name':f['name'],'level':f['level'],'description':text(f.get('entries',[]))} for ref in row.get('classFeatures',[]) if (f:=feature(ref))]
        if kind == 'subclasses':
            data['levelFeatures'] = [{'name':f['name'],'level':f['level'],'description':text(f.get('entries',[]))} for ref in row.get('subclassFeatures',[]) if (f:=subclass_feature(ref))]
        if kind == 'spells':
            for field in ['time','range','components','duration','school']:
                data[field] = row.get(field)
        if kind == 'items':
            data.update(damage=row.get('dmg1'), weight=row.get('weight'), cost=row.get('value'), type=row.get('type'))
        if kind == 'monsters':
            data.update(abilities={k:row.get(k,10) for k in ['str','dex','con','int','wis','cha']}, hit_points=(row.get('hp') or {}).get('average',10), armor_class=next((a if isinstance(a,int) else a.get('ac',10) for a in row.get('ac',[10])),10), speed=row.get('speed'), type=row.get('type') if isinstance(row.get('type'),str) else row.get('type',{}).get('type',''), size=', '.join(row.get('size',[])), alignment=', '.join(str(v) for v in (row.get('alignment') or [])), challenge_rating=row.get('cr') if isinstance(row.get('cr'),str) else (row.get('cr') or {}).get('cr'), actions=[{'name':a['name'],'desc':text(a.get('entries',[]))} for a in (row.get('action') or [])])
        dest.write(json.dumps({'slug':slug,'kind':kind,'name':row['name'],'source':source,'edition':edition,'level':row.get('level') if isinstance(row.get('level'),int) else None,'data':data}, ensure_ascii=False)+'\n')
        counts[kind+' '+edition] += 1
report = {'imported':dict(counts),'excluded':skipped}
output.with_suffix('.report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
print(json.dumps({'counts':dict(counts),'excluded':len(skipped)},ensure_ascii=False,indent=2))
