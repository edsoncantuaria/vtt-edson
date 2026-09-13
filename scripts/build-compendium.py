"""Build the canonical 5etools integration catalog.

Input is the checkout produced by resolve-five-tools.mjs. Every row keeps the
upstream `raw` payload but also exposes a stable `integration.schema == 1`
contract consumed by the VTT. Runtime code never needs to reverse-engineer
5etools prose to execute common mechanics.

Usage: python3 scripts/build-compendium.py RESOLVED_CHECKOUT OUTPUT.ndjson
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import re
import sys
from collections import Counter
from typing import Any

root, output = pathlib.Path(sys.argv[1]) / "data", pathlib.Path(sys.argv[2])


def read(path: pathlib.Path) -> dict[str, Any]:
    return json.loads(path.read_text())


books = {r["source"]: r for r in read(root / "books.json").get("book", [])}

ROOT_SPECS: dict[str, dict[str, str]] = {
    "races.json": {"race": "races"},
    "backgrounds.json": {"background": "backgrounds"},
    "feats.json": {"feat": "feats"},
    "optionalfeatures.json": {"optionalfeature": "features"},
    "items.json": {"item": "items"},
    "items-base.json": {"baseitem": "items"},
    "variantrules.json": {"variantrule": "rules"},
    "conditionsdiseases.json": {"condition": "rules", "disease": "rules"},
    "actions.json": {"action": "rules"},
    "senses.json": {"sense": "rules"},
    "skills.json": {"skill": "rules"},
    "bastions.json": {"facility": "bastions"},
    "vehicles.json": {"vehicle": "vehicles", "vehicleUpgrade": "vehicles"},
    "decks.json": {"deck": "decks", "card": "cards"},
    "recipes.json": {"recipe": "recipes"},
    "psionics.json": {"psionic": "psionics"},
    "rewards.json": {"reward": "rewards"},
    "deities.json": {"deity": "deities"},
    "languages.json": {"language": "languages", "languageScript": "languages"},
    "trapshazards.json": {"trap": "hazards", "hazard": "hazards"},
    "objects.json": {"object": "objects"},
    "cultsboons.json": {"cult": "cults", "boon": "cults"},
    "encounters.json": {"encounter": "encounters"},
    "magicvariants.json": {"magicvariant": "magic-variants"},
    "bestiary/legendarygroups.json": {"legendaryGroup": "legendary-groups"},
}

raw: list[tuple[str, str, dict[str, Any]]] = []
for filename, spec in ROOT_SPECS.items():
    path = root / filename
    if not path.exists():
        continue
    data = read(path)
    for key, kind in spec.items():
        for row in data.get(key, []):
            if not isinstance(row, dict) or not row.get("name"):
                continue
            if not row.get("source") and key == "magicvariant":
                row = {**row, "source": (row.get("inherits") or {}).get("source")}
            if row.get("source"):
                raw.append((kind, key, row))

for folder, key, kind in [("spells", "spell", "spells"), ("bestiary", "monster", "monsters"), ("class", "class", "classes")]:
    directory = root / folder
    if not directory.exists():
        continue
    for path in sorted(directory.glob("*.json")):
        if path.name.startswith(("fluff-", "foundry-")) or path.name in {"index.json", "sources.json", "legendarygroups.json", "foundry.json"}:
            continue
        data = read(path)
        for row in data.get(key, []):
            if isinstance(row, dict) and row.get("name") and row.get("source"):
                raw.append((kind, key, row))
        if folder == "class":
            for row in data.get("subclass", []):
                if isinstance(row, dict) and row.get("name") and row.get("source"):
                    raw.append(("subclasses", "subclass", row))
            for feature_key in ("classFeature", "subclassFeature"):
                for row in data.get(feature_key, []):
                    if isinstance(row, dict) and row.get("name") and row.get("source"):
                        raw.append(("features", feature_key, row))

# Loot has several independent named table families in one file.
loot_path = root / "loot.json"
if loot_path.exists():
    loot = read(loot_path)
    for key in ("individual", "hoard", "dragon", "gems", "artObjects", "magicItems"):
        for row in loot.get(key, []):
            if isinstance(row, dict) and row.get("name") and row.get("source"):
                raw.append(("loot", f"loot:{key}", row))


TAG_RE = re.compile(r"\{@(?P<tag>[A-Za-z]+)\s+(?P<body>[^{}]*)\}")
ABILITY = {
    "strength": "str", "dexterity": "dex", "constitution": "con",
    "intelligence": "int", "wisdom": "wis", "charisma": "cha",
}
DAMAGE_ABBR = {"A": "acid", "B": "bludgeoning", "C": "cold", "F": "fire", "O": "force", "L": "lightning", "N": "necrotic", "P": "piercing", "I": "poison", "Y": "psychic", "R": "radiant", "S": "slashing", "T": "thunder"}


def text(value: Any) -> str:
    if isinstance(value, str):
        def replace(match: re.Match[str]) -> str:
            tag, body = match.group("tag"), match.group("body") or ""
            bits = body.split("|")
            if tag == "h":
                return "Dano: "
            if tag == "hit":
                return ("+" if body and not body.startswith("-") else "") + body
            if tag == "dc":
                return body
            return bits[2] if len(bits) > 2 and bits[2] else bits[0]
        for _ in range(8):
            value = TAG_RE.sub(replace, value)
        return value
    if isinstance(value, list):
        return "\n\n".join(filter(None, (text(v) for v in value)))
    if isinstance(value, dict):
        if value.get("type") == "table":
            rows = [value.get("colLabels", []), *value.get("rows", [])]
            return "\n".join(" | ".join(text(cell) for cell in row) for row in rows if isinstance(row, list))
        return "\n".join(filter(None, [text(value.get("name", "")), *[text(value.get(k, "")) for k in ("entries", "entry", "items", "text")]]))
    return str(value) if value is not None else ""


def serialized(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def tags(value: Any, tag: str) -> list[str]:
    out: list[str] = []
    for match in TAG_RE.finditer(serialized(value)):
        if match.group("tag").lower() == tag.lower():
            out.append(match.group("body").split("|")[0].strip())
    return out


def refs(value: Any) -> list[dict[str, str]]:
    supported = {"creature", "spell", "item", "vehicle", "condition", "status", "class", "race", "background", "feat"}
    found: list[dict[str, str]] = []
    seen: set[tuple[str, str, str]] = set()
    for match in TAG_RE.finditer(serialized(value)):
        tag = match.group("tag").lower()
        if tag not in supported:
            continue
        bits = match.group("body").split("|")
        name = bits[0].strip()
        source = bits[1].strip() if len(bits) > 1 else ""
        key = (tag, name.lower(), source.lower())
        if name and key not in seen:
            seen.add(key)
            found.append({"type": tag, "name": name, **({"source": source} if source else {})})
    return found


def source_book(source: str) -> dict[str, Any]:
    return books.get(source, {})


def edition_for(row: dict[str, Any], source: str) -> str:
    book = source_book(source)
    if row.get("edition") == "one":
        return "5e-2024"
    if row.get("edition") == "classic":
        return "5e-2014"
    return "5e-2024" if source in {"XPHB", "XDMG", "XMM"} or book.get("published", "") >= "2024-09-17" else "5e-2014"


def dice_formula(value: str | None) -> str | None:
    if not value:
        return None
    formula = value.strip().replace(" ", "")
    return formula if re.fullmatch(r"\d*d\d+(?:[+-]\d+)?", formula, re.I) else None


def duration_for_spell(row: dict[str, Any]) -> dict[str, Any]:
    first = next((d for d in row.get("duration", []) if isinstance(d, dict)), {})
    if first.get("type") != "timed":
        return {"unit": "permanent"}
    duration = first.get("duration") or {}
    amount = max(1, int(duration.get("amount") or 1))
    unit = duration.get("type")
    rounds = amount
    if unit in {"minute", "minutes"}:
        rounds = amount * 10
    elif unit in {"hour", "hours"}:
        rounds = amount * 600
    elif unit in {"day", "days"}:
        rounds = amount * 14400
    return {"unit": "rounds", "remaining": rounds}


def infer_save_ability(value: Any) -> str | None:
    saving = value.get("savingThrow") if isinstance(value, dict) else None
    if isinstance(saving, list) and saving:
        return ABILITY.get(str(saving[0]).lower(), str(saving[0])[:3].lower())
    plain = text(value)
    match = re.search(r"\b(Strength|Dexterity|Constitution|Intelligence|Wisdom|Charisma) saving throw\b", plain, re.I)
    return ABILITY.get(match.group(1).lower()) if match else None


def infer_effect(value: Any, name: str, duration: dict[str, Any] | None = None) -> dict[str, Any] | None:
    raw_text = serialized(value)
    plain = text(value).lower()
    conditions = [c.lower() for c in tags(value, "condition")]
    dice = next((dice_formula(v) for v in tags(value, "dice") if dice_formula(v)), None)
    modifiers: list[dict[str, Any]] = []
    if dice and "add" in plain:
        if "attack roll" in plain:
            modifiers.append({"path": "roll.attack", "mode": "add", "value": dice})
        if "saving throw" in plain:
            modifiers.append({"path": "roll.save", "mode": "add", "value": dice})
        if "ability check" in plain or "ability checks" in plain:
            modifiers.append({"path": "roll.skill", "mode": "add", "value": dice})
    # Common static AC bonuses are safe to automate when explicitly worded.
    ac_match = re.search(r"(?:bonus|gains? a bonus) (?:of )?\+?(\d+) (?:bonus )?to (?:its |your )?AC", text(value), re.I)
    if ac_match:
        modifiers.append({"path": "ac", "mode": "add", "value": int(ac_match.group(1))})
    if not conditions and not modifiers:
        return None
    trigger = "on-failed-save" if conditions and ("saving throw" in plain or "savingThrow" in raw_text) else "on-use"
    return {
        "name": name,
        "target": "targets",
        "trigger": trigger,
        "duration": duration or {"unit": "permanent"},
        "modifiers": modifiers,
        "conditions": conditions,
    }


def action_from_entries(name: str, value: Any, economy: str = "action", kind: str = "attack") -> dict[str, Any] | None:
    blob = serialized(value)
    hit = re.search(r"\\\{@hit ([+-]?\d+)\\?\}", blob)
    damage_tags = tags(value, "damage")
    damage = next((dice_formula(v) for v in damage_tags if dice_formula(v)), None)
    save_dc_values = tags(value, "dc")
    save_dc = int(save_dc_values[0]) if save_dc_values and save_dc_values[0].isdigit() else None
    save_ability = infer_save_ability(value)
    effect = infer_effect(value, name)
    if not hit and not damage and not save_ability and not effect:
        return None
    result: dict[str, Any] = {
        "name": name,
        "kind": kind,
        "economy": economy,
        "description": text(value),
    }
    if hit:
        bonus = int(hit.group(1))
        result["attackFormula"] = f"1d20{bonus:+d}"
    if damage:
        result["damageFormula"] = damage
    if save_ability:
        result["saveAbility"] = save_ability
        if save_dc:
            result["saveDc"] = save_dc
        result["saveEffect"] = "half" if "half as much" in text(value).lower() or "half the damage" in text(value).lower() else "none"
    damage_types = tags(value, "damage")
    # Damage type is usually prose immediately following the damage tag; callers can override.
    if effect:
        result["effect"] = effect
    return result


def spell_automation(row: dict[str, Any]) -> dict[str, Any]:
    description = [row.get("entries", []), row.get("entriesHigherLevel", [])]
    level = int(row.get("level") or 0)
    action: dict[str, Any] = {
        "name": row["name"], "kind": "spell", "economy": "bonus" if any(t.get("unit") == "bonus" for t in row.get("time", []) if isinstance(t, dict)) else "reaction" if any(t.get("unit") == "reaction" for t in row.get("time", []) if isinstance(t, dict)) else "action",
        "description": text(description),
    }
    damages = [dice_formula(v) for v in tags(description, "damage")]
    damages = [v for v in damages if v]
    if damages:
        action["damageFormula"] = damages[0]
    if row.get("spellAttack"):
        action["attackAbility"] = "spellcasting"
    save = infer_save_ability(row)
    if save:
        action["saveAbility"] = save
        action["saveEffect"] = "half" if "half as much" in text(description).lower() or "half the damage" in text(description).lower() else "none"
    damage_inflict = row.get("damageInflict") or []
    if damage_inflict and isinstance(damage_inflict[0], str):
        action["damageType"] = damage_inflict[0].lower()
    if level > 0:
        action["spellSlotLevel"] = level
    concentration = any(bool(d.get("concentration")) for d in row.get("duration", []) if isinstance(d, dict))
    if concentration:
        action["concentration"] = True
    effect = infer_effect(description, row["name"], duration_for_spell(row))
    if effect:
        action["effect"] = effect
    return {"actions": [action], "references": refs(row)}


def item_automation(row: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {"references": refs(row)}
    action: dict[str, Any] | None = None
    damage = dice_formula(str(row.get("dmg1") or ""))
    if damage:
        properties = [str(v).split("|")[0] for v in (row.get("property") or [])]
        item_type = str(row.get("type") or "").split("|")[0]
        ability = "weapon" if "F" in properties else ("dex" if item_type == "R" else "str")
        action = {
            "name": row["name"], "kind": "attack", "economy": "action",
            "damageFormula": damage, "attackAbility": ability, "damageAbility": ability,
            "description": text(row.get("entries", [])),
        }
        dmg_type = row.get("dmgType")
        if isinstance(dmg_type, str) and dmg_type in DAMAGE_ABBR:
            action["damageType"] = DAMAGE_ABBR[dmg_type]
        bonus = row.get("bonusWeapon")
        if isinstance(bonus, str) and re.fullmatch(r"[+-]?\d+", bonus):
            action["attackBonus"] = int(bonus)
            action["damageBonus"] = int(bonus)
    elif row.get("entries"):
        action = action_from_entries(row["name"], row.get("entries", []), kind="spell" if tags(row, "spell") else "attack")
    if action:
        out["actions"] = [action]
    charges = row.get("charges")
    if isinstance(charges, int) and charges >= 0:
        recovery = next((dice_formula(v) for v in tags(row.get("entries", []), "dice") if dice_formula(v)), None)
        out["charges"] = {"value": charges, "max": charges, "reset": "manual", **({"recoveryFormula": recovery} if recovery else {})}
    if row.get("reqAttune"):
        out["requiresAttunement"] = True
    return out


def feature_automation(row: dict[str, Any]) -> dict[str, Any]:
    action = action_from_entries(row["name"], row.get("entries", []), kind="attack")
    effect = infer_effect(row.get("entries", []), row["name"])
    return {
        **({"actions": [action]} if action else {}),
        **({"effects": [effect]} if effect and not action else {}),
        "references": refs(row),
    }


def monster_automation(row: dict[str, Any]) -> dict[str, Any]:
    actions: list[dict[str, Any]] = []
    for section, economy in (("action", "action"), ("bonus", "bonus"), ("reaction", "reaction"), ("legendary", "other"), ("mythic", "other")):
        for entry in row.get(section, []) or []:
            if isinstance(entry, dict) and entry.get("name"):
                action = action_from_entries(entry["name"], entry.get("entries", []), economy=economy)
                if action:
                    actions.append(action)
    out: dict[str, Any] = {"actions": actions, "references": refs(row)}
    if isinstance(row.get("legendaryGroup"), dict):
        out["legendaryGroup"] = {"name": row["legendaryGroup"].get("name"), "source": row["legendaryGroup"].get("source")}
    return out


def parse_tag_refs_from_result(result: str) -> list[dict[str, Any]]:
    found: list[dict[str, Any]] = []
    for match in TAG_RE.finditer(result):
        tag = match.group("tag").lower()
        if tag not in {"creature", "vehicle"}:
            continue
        bits = match.group("body").split("|")
        prefix = result[max(0, match.start() - 28):match.start()]
        dice = re.search(r"\{@dice ([^}|]+)[^}]*\}\s*$", prefix)
        number = re.search(r"\b(\d+)\s*$", prefix)
        word = re.search(r"\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)\s*$", prefix, re.I)
        words = {"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10, "eleven": 11, "twelve": 12}
        quantity: str | int = 1
        if dice:
            quantity = dice.group(1).replace(" ", "")
        elif number:
            quantity = int(number.group(1))
        elif word:
            quantity = words[word.group(1).lower()]
        found.append({"type": tag, "name": bits[0], **({"source": bits[1]} if len(bits) > 1 and bits[1] else {}), "quantity": quantity})
    return found


def encounter_automation(row: dict[str, Any]) -> dict[str, Any]:
    tables = []
    for table in row.get("tables", []) or []:
        if not isinstance(table, dict):
            continue
        entries = []
        for item in table.get("table", []) or []:
            if not isinstance(item, dict):
                continue
            result = str(item.get("result") or "")
            entries.append({"min": int(item.get("min") or 0), "max": int(item.get("max") or item.get("min") or 0), "label": text(result), "result": {"text": result, "entities": parse_tag_refs_from_result(result)}})
        if entries:
            tables.append({"formula": str(table.get("diceExpression") or "1d100"), "entries": entries})
    return {"encounterTables": tables, "references": refs(row)}


def loot_automation(row: dict[str, Any], subtype: str) -> dict[str, Any]:
    table_rows = row.get("table", []) if isinstance(row.get("table"), list) else []
    entries = []
    ranged = any(isinstance(item, dict) and "min" in item for item in table_rows)
    for index, item in enumerate(table_rows, 1):
        if isinstance(item, str):
            entries.append({"min": index, "max": index, "label": text(item), "result": {"item": item}})
            continue
        if not isinstance(item, dict):
            continue
        entries.append({
            "min": int(item.get("min") or index), "max": int(item.get("max") or item.get("min") or index),
            "label": text(item) or f"{item.get('min', index)}-{item.get('max', item.get('min', index))}", "result": item,
        })
    formula = "1d100" if ranged else (f"1d{len(entries)}" if len(entries) >= 2 else ("1d2" if entries else None))
    return {
        "loot": {
            "subtype": subtype, "formula": formula, "entries": entries,
            "coins": row.get("coins") or {}, "crMin": row.get("crMin"), "crMax": row.get("crMax"),
            "tableType": row.get("type"),
        },
        "references": refs(row),
    }


def subsystem_automation(kind: str, key: str, row: dict[str, Any]) -> dict[str, Any]:
    state: dict[str, Any] = {"sourceKey": key}
    if kind == "decks" and key == "deck":
        state["cards"] = list(row.get("cards") or [])
    elif kind == "vehicles" and key == "vehicle":
        hp = row.get("hp")
        state.update({"hp": hp.get("average") if isinstance(hp, dict) else hp, "ac": row.get("ac"), "speed": row.get("speed"), "crew": row.get("capCrew")})
    elif kind == "bastions":
        state.update({"level": row.get("level"), "facilityType": row.get("facilityType"), "orders": row.get("orders") or []})
    elif kind == "recipes":
        state.update({"ingredients": row.get("ingredients") or [], "instructions": row.get("instructions") or []})
    return {"subsystem": {"kind": kind, "state": state}, "references": refs(row)}


def nested_items(value: Any) -> list[Any]:
    if isinstance(value, list):
        out: list[Any] = []
        for item in value:
            out.extend(nested_items(item))
        return out
    if isinstance(value, dict) and isinstance(value.get("items"), list):
        return nested_items(value["items"])
    return [value]


def automation(kind: str, key: str, row: dict[str, Any]) -> dict[str, Any]:
    if kind == "spells":
        return spell_automation(row)
    if kind in {"items", "magic-variants"}:
        if kind == "magic-variants" and isinstance(row.get("inherits"), dict):
            item_row = {**row.get("inherits", {}), "name": row["name"], "source": row.get("source")}
        else:
            item_row = row
        base = item_automation(item_row)
        if kind == "magic-variants":
            base["variant"] = {"requires": row.get("requires") or [], "inherits": row.get("inherits") or {}}
        return base
    if kind == "monsters":
        return monster_automation(row)
    if kind == "encounters":
        return encounter_automation(row)
    if kind == "loot":
        return loot_automation(row, key.split(":", 1)[-1])
    if kind == "legendary-groups":
        lair_actions = []
        for index, item in enumerate(nested_items(row.get("lairActions") or [])):
            if not isinstance(item, (str, dict)):
                continue
            action = action_from_entries(f"Lair Action {index + 1}", item, economy="other")
            if action:
                lair_actions.append(action)
        return {
            "legendaryGroup": {
                "lairActions": row.get("lairActions") or [],
                "regionalEffects": row.get("regionalEffects") or [],
                "actions": lair_actions,
            },
            "references": refs(row),
        }
    if kind in {"decks", "vehicles", "bastions", "recipes", "psionics", "rewards", "deities", "languages", "hazards", "objects", "cults"}:
        result = subsystem_automation(kind, key, row)
        if kind in {"hazards", "objects", "psionics", "rewards", "cults"}:
            result.update(feature_automation(row))
        return result
    if kind in {"features", "feats", "races", "backgrounds"}:
        return feature_automation(row)
    return {"references": refs(row)}


# Feature lookup for class/subclass level snapshots.
features: dict[tuple[str, str, str, str, str], dict[str, Any]] = {}
subclass_features: dict[tuple[str, str, str, str, str, str, str], dict[str, Any]] = {}
for _, key, row in raw:
    if key == "classFeature":
        features[(row["name"].lower(), row.get("className", "").lower(), row.get("classSource", "PHB").lower(), str(row.get("level")), row["source"].lower())] = row
    elif key == "subclassFeature":
        subclass_features[(row["name"].lower(), row.get("className", "").lower(), row.get("classSource", "PHB").lower(), row.get("subclassShortName", "").lower(), row.get("subclassSource", row.get("source", "")).lower(), str(row.get("level")), row["source"].lower())] = row


def feature(ref: Any) -> dict[str, Any] | None:
    ref = ref if isinstance(ref, str) else (ref or {}).get("classFeature", "")
    bits = ref.split("|")
    if len(bits) < 4:
        return None
    name, cls, source, level = bits[:4]
    source = source or "PHB"
    return features.get((name.lower(), cls.lower(), source.lower(), level, (bits[4] if len(bits) > 4 and bits[4] else source).lower()))


def subclass_feature(ref: Any) -> dict[str, Any] | None:
    ref = ref if isinstance(ref, str) else (ref or {}).get("subclassFeature", "")
    bits = ref.split("|")
    if len(bits) < 6:
        return None
    name, cls, class_source, short_name, subclass_source, level = bits[:6]
    class_source = class_source or "PHB"
    subclass_source = subclass_source or class_source
    feature_source = bits[6] if len(bits) > 6 and bits[6] else subclass_source
    return subclass_features.get((name.lower(), cls.lower(), class_source.lower(), short_name.lower(), subclass_source.lower(), level, feature_source.lower()))


counts: Counter[str] = Counter()
seen: set[str] = set()
with output.open("w") as dest:
    for kind, key, row in raw:
        source = str(row.get("source") or "")
        if not source:
            continue
        edition = edition_for(row, source)
        identity = "|".join(str(row.get(k, "")) for k in ("name", "source", "className", "classSource", "subclassShortName", "level", "set"))
        slug = hashlib.sha256((key + "|" + identity).encode()).hexdigest()
        if slug in seen:
            continue
        seen.add(slug)
        desc = text([row.get("entries", []), row.get("additionalEntries", []), row.get("entriesHigherLevel", []), row.get("instructions", [])])
        data: dict[str, Any] = {
            "description": desc,
            "sourceName": source_book(source).get("name", source),
            "page": row.get("page"),
            "raw": row,
            "format": "5etools",
            "editionBasis": "explicit" if row.get("edition") else "source publication",
            "srd": bool(row.get("srd52") if edition == "5e-2024" else row.get("srd")),
            "integration": {"schema": 1, "sourceKey": key, "automation": automation(kind, key, row)},
        }
        if kind == "classes":
            data["levelFeatures"] = [{"name": f["name"], "level": f["level"], "description": text(f.get("entries", []))} for ref in row.get("classFeatures", []) if (f := feature(ref))]
        if kind == "subclasses":
            data["levelFeatures"] = [{"name": f["name"], "level": f["level"], "description": text(f.get("entries", []))} for ref in row.get("subclassFeatures", []) if (f := subclass_feature(ref))]
        if kind == "spells":
            for field in ("time", "range", "components", "duration", "school"):
                data[field] = row.get(field)
        if kind in {"items", "magic-variants"}:
            item = row.get("inherits") if kind == "magic-variants" and isinstance(row.get("inherits"), dict) else row
            data.update(damage=item.get("dmg1"), weight=item.get("weight"), cost=item.get("value"), type=item.get("type"))
        if kind == "monsters":
            data.update(
                abilities={k: row.get(k, 10) for k in ("str", "dex", "con", "int", "wis", "cha")},
                hit_points=(row.get("hp") or {}).get("average", 10) if isinstance(row.get("hp"), dict) else row.get("hp", 10),
                armor_class=next((a if isinstance(a, int) else a.get("ac", 10) for a in row.get("ac", [10])), 10),
                speed=row.get("speed"),
                type=row.get("type") if isinstance(row.get("type"), str) else (row.get("type") or {}).get("type", ""),
                size=", ".join(row.get("size", [])),
                alignment=", ".join(str(v) for v in (row.get("alignment") or [])),
                challenge_rating=row.get("cr") if isinstance(row.get("cr"), str) else (row.get("cr") or {}).get("cr"),
                actions=[{"name": a["name"], "desc": text(a.get("entries", []))} for a in (row.get("action") or []) if isinstance(a, dict) and a.get("name")],
            )
        payload = {"slug": slug, "kind": kind, "name": row["name"], "source": source, "edition": edition, "level": row.get("level") if isinstance(row.get("level"), int) else None, "data": data}
        dest.write(json.dumps(payload, ensure_ascii=False) + "\n")
        counts[f"{kind} {edition}"] += 1

report = {"schema": 1, "imported": dict(counts), "total": sum(counts.values())}
output.with_suffix(".report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2))
print(json.dumps(report, ensure_ascii=False, indent=2))
