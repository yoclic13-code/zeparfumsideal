#!/usr/bin/env python3
"""
Scrape le catalogue zeparfums.com (espace CSE, derrière login).
Sortie JSON sur stdout : { "ok": true, "products": [...], "stats": {...} }

Auth (priorité) :
  ZEPARFUMS_COOKIE   — header Cookie du navigateur (session CSE déjà ouverte)
  ou ZEPARFUMS_EMAIL + ZEPARFUMS_PASSWORD

Autres :
  ZEPARFUMS_BASE_URL, ZEPARFUMS_CATEGORIES, ZEPARFUMS_MAX_PAGES, ZEPARFUMS_DELAY_MS
"""

from __future__ import annotations

import json
import os
import re
import sys
import time
from typing import Any
from urllib.parse import urljoin, urlparse, urlunparse, parse_qs, urlencode

import requests
from bs4 import BeautifulSoup

BASE = os.environ.get("ZEPARFUMS_BASE_URL", "https://zeparfums.com").rstrip("/")
LOGIN_URL = f"{BASE}/module/zeparfumsreg/connexion"
DEFAULT_CATEGORIES = [
    f"{BASE}/2-accueil",
    f"{BASE}/2-parfums",
    f"{BASE}/index.php?id_category=2&controller=category",
]
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)


def fail(msg: str, code: int = 1) -> None:
    print(json.dumps({"ok": False, "error": msg}, ensure_ascii=False))
    sys.exit(code)


def clean_text(value: str | None) -> str:
    if not value:
        return ""
    return re.sub(r"\s+", " ", value).strip()


def parse_price(raw: str | None) -> float | None:
    if not raw:
        return None
    text = (
        raw.replace("\xa0", "")
        .replace(" ", "")
        .replace("€", "")
        .replace(",", ".")
    )
    text = re.sub(r"[^\d.]", "", text)
    try:
        return round(float(text), 2) if text else None
    except ValueError:
        return None


def normalize_url(url: str) -> str:
    url = urljoin(BASE + "/", url)
    parsed = urlparse(url)
    return urlunparse((parsed.scheme, parsed.netloc, parsed.path, "", "", ""))


def absolute_url(url: str | None) -> str:
    if not url:
        return ""
    return urljoin(BASE + "/", url)


def is_login_page(url: str, html: str) -> bool:
    if "zeparfumsreg/connexion" in url:
        return True
    low = html.lower()
    return 'name="password"' in low and 'name="email"' in low and "submitlogin" in low


def parse_cookie_header(raw: str) -> dict[str, str]:
    """Accepte 'a=1; b=2' ou un JSON {"a":"1"} / [{"name":"a","value":"1"}]."""
    raw = raw.strip()
    if not raw:
        return {}

    if raw.startswith("{") or raw.startswith("["):
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            fail("Cookie JSON invalide.")
        cookies: dict[str, str] = {}
        if isinstance(data, dict):
            if "name" in data and "value" in data:
                cookies[str(data["name"])] = str(data["value"])
            else:
                for k, v in data.items():
                    if isinstance(v, (str, int, float)):
                        cookies[str(k)] = str(v)
            return cookies
        if isinstance(data, list):
            for item in data:
                if isinstance(item, dict) and "name" in item and "value" in item:
                    cookies[str(item["name"])] = str(item["value"])
            return cookies
        fail("Format cookie JSON non reconnu.")

    if raw.lower().startswith("cookie:"):
        raw = raw.split(":", 1)[1].strip()

    cookies = {}
    for part in raw.split(";"):
        part = part.strip()
        if not part or "=" not in part:
            continue
        name, value = part.split("=", 1)
        name = name.strip()
        if name:
            cookies[name] = value.strip()
    return cookies


def apply_browser_cookies(session: requests.Session, cookie_raw: str) -> None:
    cookies = parse_cookie_header(cookie_raw)
    if not cookies:
        fail("Aucun cookie valide fourni.")
    domain = urlparse(BASE).hostname or "zeparfums.com"
    for name, value in cookies.items():
        session.cookies.set(name, value, domain=domain, path="/")


def assert_logged_in(session: requests.Session) -> None:
    probes = [
        f"{BASE}/2-accueil",
        f"{BASE}/2-parfums",
        f"{BASE}/index.php?id_category=2&controller=category",
        f"{BASE}/",
        f"{BASE}/mon-compte",
    ]
    last_url = ""
    for url in probes:
        r = session.get(url, timeout=30, allow_redirects=True)
        r.raise_for_status()
        last_url = r.url
        if not is_login_page(r.url, r.text):
            return
    fail(
        "Session CSE invalide ou expirée (redirigé vers la connexion). "
        "Reconnectez-vous sur zeparfums.com puis recollez le cookie navigateur. "
        f"Dernière URL : {last_url}"
    )


def login(session: requests.Session, email: str, password: str) -> None:
    r = session.get(LOGIN_URL, timeout=30)
    r.raise_for_status()
    soup = BeautifulSoup(r.text, "lxml")
    form = soup.find("form")
    action = absolute_url(form.get("action") if form else LOGIN_URL) or LOGIN_URL

    payload: dict[str, str] = {
        "email": email,
        "password": password,
        "submitLogin": "1",
    }
    if form:
        for inp in form.find_all("input"):
            name = inp.get("name")
            if not name or name in payload:
                continue
            payload[name] = inp.get("value") or ""

    r = session.post(action, data=payload, timeout=30, allow_redirects=True)
    r.raise_for_status()
    if is_login_page(r.url, r.text):
        fail("Connexion refusée. Vérifiez l'email / mot de passe du compte CSE.")


def with_page(url: str, page: int) -> str:
    parsed = urlparse(url)
    qs = parse_qs(parsed.query)
    qs["page"] = [str(page)]
    query = urlencode({k: v[0] for k, v in qs.items()})
    return urlunparse((parsed.scheme, parsed.netloc, parsed.path, parsed.params, query, ""))


def extract_from_miniature(article) -> dict[str, Any] | None:
    link = (
        article.select_one("a.product-thumbnail")
        or article.select_one("h2 a")
        or article.select_one("h3 a")
        or article.select_one("a[href*='.html']")
    )
    if not link or not link.get("href"):
        return None

    product_url = normalize_url(link["href"])
    if "/module/zeparfumsreg/" in product_url:
        return None

    name_el = (
        article.select_one(".product-title")
        or article.select_one("h2")
        or article.select_one("h3")
        or link
    )
    name = clean_text(name_el.get_text(" ", strip=True) if name_el else "")
    if not name:
        return None

    price_el = (
        article.select_one(".price")
        or article.select_one(".product-price")
        or article.select_one("[itemprop='price']")
    )
    price_raw = ""
    if price_el:
        price_raw = price_el.get("content") or price_el.get_text(" ", strip=True)

    img = article.select_one("img")
    image_url = ""
    if img:
        image_url = img.get("data-full-size-image-url") or img.get("data-src") or img.get("src") or ""
        image_url = absolute_url(image_url)

    brand_el = article.select_one(".product-brand, .manufacturer, [itemprop='brand']")
    brand = clean_text(brand_el.get_text(" ", strip=True) if brand_el else "")

    ps_id = article.get("data-id-product") or ""
    try:
        prestashop_id = int(ps_id) if ps_id else None
    except ValueError:
        prestashop_id = None

    if prestashop_id is None:
        m = re.search(r"/(\d+)(?:-\d+)?-[^/]+\.html", product_url)
        if m:
            prestashop_id = int(m.group(1))

    return {
        "name": name,
        "brand": brand,
        "price": parse_price(price_raw),
        "image_url": image_url,
        "product_url": product_url,
        "prestashop_id": prestashop_id,
        "is_active": 1,
    }


def extract_products(html: str) -> list[dict[str, Any]]:
    soup = BeautifulSoup(html, "lxml")
    products: list[dict[str, Any]] = []
    seen: set[str] = set()

    selectors = [
        "article.product-miniature",
        "article.js-product-miniature",
        ".product-miniature",
        "[data-id-product]",
        ".js-product",
    ]
    nodes = []
    for sel in selectors:
        found = soup.select(sel)
        if found:
            nodes = found
            break

    for node in nodes:
        item = extract_from_miniature(node)
        if not item:
            continue
        key = item["product_url"]
        if key in seen:
            continue
        seen.add(key)
        products.append(item)

    if not products:
        for a in soup.select("a[href*='.html']"):
            href = a.get("href") or ""
            if not re.search(r"/\d+(?:-\d+)?-[^/]+\.html", href):
                continue
            product_url = normalize_url(href)
            if product_url in seen:
                continue
            name = clean_text(a.get_text(" ", strip=True))
            if len(name) < 3:
                continue
            seen.add(product_url)
            m = re.search(r"/(\d+)(?:-\d+)?-[^/]+\.html", product_url)
            products.append(
                {
                    "name": name,
                    "brand": "",
                    "price": None,
                    "image_url": "",
                    "product_url": product_url,
                    "prestashop_id": int(m.group(1)) if m else None,
                    "is_active": 1,
                }
            )

    return products


def scrape_category(
    session: requests.Session, category_url: str, max_pages: int, delay_s: float
) -> list[dict[str, Any]]:
    collected: list[dict[str, Any]] = []
    seen: set[str] = set()

    for page in range(1, max_pages + 1):
        url = with_page(category_url, page) if page > 1 else category_url
        r = session.get(url, timeout=45, allow_redirects=True)
        r.raise_for_status()
        if is_login_page(r.url, r.text):
            fail(
                "Session expirée pendant le scrape (redirigé vers la connexion). "
                "Recollez un cookie frais."
            )

        page_products = extract_products(r.text)
        if not page_products:
            break

        new_count = 0
        for p in page_products:
            if p["product_url"] in seen:
                continue
            seen.add(p["product_url"])
            collected.append(p)
            new_count += 1

        if new_count == 0:
            break

        time.sleep(delay_s)

    return collected


def discover_category_urls(session: requests.Session) -> list[str]:
    candidates = [
        f"{BASE}/",
        f"{BASE}/2-parfums",
        f"{BASE}/index.php?controller=index",
    ]
    found: list[str] = []
    for url in candidates:
        try:
            r = session.get(url, timeout=30, allow_redirects=True)
        except requests.RequestException:
            continue
        if is_login_page(r.url, r.text):
            continue
        soup = BeautifulSoup(r.text, "lxml")
        for a in soup.select("a[href]"):
            text = clean_text(a.get_text(" ", strip=True)).lower()
            href = absolute_url(a.get("href"))
            if "parfum" in text and href and href not in found:
                if "zeparfumsreg" in href:
                    continue
                found.append(href)
        if found:
            break
    return found[:5] if found else DEFAULT_CATEGORIES.copy()


def main() -> None:
    cookie_raw = os.environ.get("ZEPARFUMS_COOKIE", "").strip()
    email = os.environ.get("ZEPARFUMS_EMAIL", "").strip()
    password = os.environ.get("ZEPARFUMS_PASSWORD", "").strip()

    max_pages = int(os.environ.get("ZEPARFUMS_MAX_PAGES", "200"))
    delay_s = max(0.0, int(os.environ.get("ZEPARFUMS_DELAY_MS", "250")) / 1000.0)
    raw_cats = os.environ.get("ZEPARFUMS_CATEGORIES", "").strip()

    session = requests.Session()
    session.headers.update({"User-Agent": USER_AGENT, "Accept-Language": "fr-FR,fr;q=0.9"})

    try:
        if cookie_raw:
            apply_browser_cookies(session, cookie_raw)
            assert_logged_in(session)
        elif email and password:
            login(session, email, password)
            assert_logged_in(session)
        else:
            fail(
                "Fournissez ZEPARFUMS_COOKIE (recommandé : cookie navigateur CSE) "
                "ou ZEPARFUMS_EMAIL + ZEPARFUMS_PASSWORD."
            )
    except requests.RequestException as exc:
        fail(f"Erreur réseau pendant l'auth : {exc}")

    if raw_cats:
        categories = [c.strip() for c in raw_cats.split("|") if c.strip()]
    else:
        categories = discover_category_urls(session)

    all_products: list[dict[str, Any]] = []
    seen: set[str] = set()
    per_category: dict[str, int] = {}

    for cat in categories:
        try:
            items = scrape_category(session, cat, max_pages=max_pages, delay_s=delay_s)
        except requests.RequestException as exc:
            fail(f"Erreur réseau sur {cat} : {exc}")
        added = 0
        for item in items:
            if item["product_url"] in seen:
                continue
            seen.add(item["product_url"])
            all_products.append(item)
            added += 1
        per_category[cat] = added

    if not all_products:
        fail(
            "Aucun produit trouvé avec cette session. "
            "Vérifiez que vous voyez le catalogue dans le navigateur, "
            "ou renseignez ZEPARFUMS_CATEGORIES."
        )

    print(
        json.dumps(
            {
                "ok": True,
                "count": len(all_products),
                "categories": per_category,
                "products": all_products,
                "auth": "cookie" if cookie_raw else "password",
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
