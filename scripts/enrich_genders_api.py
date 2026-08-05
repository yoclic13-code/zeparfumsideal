#!/usr/bin/env python3
"""
Enrichit le genre (homme/femme/mixte) des parfums encore « mixte » via PerfumAPI.

Prérequis :
  - PerfumAPI démarrée et peuplée (données Fragrantica avec champ gender)
  - PyMySQL : pip install pymysql
  - Variables d'env ou arguments (voir --help)

Usage :
  python scripts/enrich_genders_parfumo.py --limit 50 --offset 0
  python scripts/enrich_genders_parfumo.py --dry-run --limit 10

Note : on n'appelle PAS Fragrantica/Parfumo en direct (Cloudflare).
La source tiers fiable = votre instance PerfumAPI déjà utilisée par le site.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.parse
import urllib.request
from typing import Any

try:
    import pymysql
except ImportError:
    print("Installez pymysql : pip install pymysql", file=sys.stderr)
    sys.exit(1)

PACKAGING = {
    "coffret", "coffrets", "set", "kit", "gift", "cadeau", "recharge",
    "eau", "de", "parfum", "toilette", "edp", "edt", "ml", "intense",
    "absolu", "elixir", "vaporisateur", "flacon", "pour", "du", "la", "le",
}


def line_key(name: str, brand: str | None) -> str:
    s = (name or "").lower()
    if brand:
        b = brand.lower().strip()
        if s.startswith(b):
            s = s[len(b) :].strip()
    for alias in ("ysl", "yves saint laurent", "dior", "chanel", "rabanne"):
        s = re.sub(rf"\b{re.escape(alias)}\b", " ", s)
    s = re.sub(r"[^a-z0-9]+", " ", s)
    words = [w for w in s.split() if w and w not in PACKAGING and not w.isdigit()]
    return " ".join(words)


def normalize_gender(raw: Any) -> str:
    g = str(raw or "mixte").lower()
    if "women" in g or "her" in g or g == "femme":
        return "femme"
    if "men" in g and "women" not in g:
        return "homme"
    if g in ("homme", "femme", "mixte", "unisex", "unisexe"):
        return "mixte" if g in ("unisex", "unisexe", "mixte") else g
    return "mixte"


def api_search(base: str, query: str, limit: int = 8) -> list[dict]:
    url = f"{base.rstrip('/')}/perfumes/search/{urllib.parse.quote(query)}?limit={limit}"
    req = urllib.request.Request(url, headers={"User-Agent": "TrouveZeParfums/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=12) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except Exception as exc:  # noqa: BLE001
        print(f"  API error: {exc}", file=sys.stderr)
        return []
    if isinstance(data, list):
        return data
    for key in ("data", "results", "perfumes", "items"):
        if isinstance(data.get(key), list):
            return data[key]
    return []


def jaccard(a: set[str], b: set[str]) -> float:
    if not a or not b:
        return 0.0
    return len(a & b) / len(a | b)


def main() -> int:
    p = argparse.ArgumentParser(description="Enrichit genres mixte via PerfumAPI")
    p.add_argument("--api", default="http://localhost:9000", help="PERFUM_API_BASE_URL")
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=3306)
    p.add_argument("--user", default="root")
    p.add_argument("--password", default="")
    p.add_argument("--database", default="trouvezeparfums")
    p.add_argument("--limit", type=int, default=40)
    p.add_argument("--offset", type=int, default=0)
    p.add_argument("--dry-run", action="store_true")
    args = p.parse_args()

    conn = pymysql.connect(
        host=args.host,
        port=args.port,
        user=args.user,
        password=args.password,
        database=args.database,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )

    updated = skipped = 0
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, name, brand, gender FROM perfumes
            WHERE is_active = 1 AND LOWER(TRIM(gender)) = 'mixte'
            ORDER BY id ASC
            LIMIT %s OFFSET %s
            """,
            (args.limit, args.offset),
        )
        rows = cur.fetchall()

        for row in rows:
            q = f"{(row['brand'] or '').strip()} {line_key(row['name'], row['brand'])}".strip()
            items = api_search(args.api, q) if q else []
            if not items:
                line = line_key(row["name"], row["brand"])
                if line and line != q:
                    items = api_search(args.api, line)

            local_words = set(line_key(row["name"], row["brand"]).split())
            best = None
            best_score = 0.0
            for item in items:
                api_words = set(
                    line_key(str(item.get("name") or ""), item.get("brand")).split()
                )
                score = jaccard(local_words, api_words)
                if score > best_score:
                    best_score = score
                    best = item

            if not best or best_score < 0.34:
                skipped += 1
                print(f"SKIP  {row['name']}")
                time.sleep(0.2)
                continue

            gender = normalize_gender(best.get("gender"))
            if gender == "mixte":
                skipped += 1
                print(f"UNISEX {row['name']}")
                time.sleep(0.15)
                continue

            print(f"{'DRY' if args.dry_run else 'OK'}   {gender:5} | {row['name']}")
            if not args.dry_run:
                cur.execute(
                    "UPDATE perfumes SET gender = %s, updated_at = NOW() WHERE id = %s",
                    (gender, row["id"]),
                )
                conn.commit()
            updated += 1
            time.sleep(0.25)

    print(f"\nDone: updated={updated} skipped={skipped} checked={len(rows)}")
    conn.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
