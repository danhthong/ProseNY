"""Crawl NY Courts form pages and CourtHelp topics into markdown knowledge files.

Outputs markdown under prose-core/documents/knowledge/ for use by the
Knowledge_Article_Loader and intake chat reference context.

Phase A: form detail pages seeded from docs/forms/**/*.json official_url fields.
Phase B: CourtHelp MVP topic hubs (family/divorce, safety, custody, support, routing).

Usage (from collect_forms with shared venv):
  .venv\\Scripts\\python.exe crawl_knowledge.py
  .venv\\Scripts\\python.exe crawl_knowledge.py --forms-only
  .venv\\Scripts\\python.exe crawl_knowledge.py --topics-only --limit 5
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import json
import re
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional
from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup
from crawl4ai import AsyncWebCrawler, BrowserConfig, CacheMode, CrawlerRunConfig

ROOT = Path(__file__).resolve().parent.parent
FORMS_JSON_DIR = (
    ROOT
    / "public"
    / "wp-content"
    / "plugins"
    / "prose-core"
    / "docs"
    / "forms"
)
KNOWLEDGE_DIR = (
    ROOT
    / "public"
    / "wp-content"
    / "plugins"
    / "prose-core"
    / "documents"
    / "knowledge"
)
FORMS_OUT = KNOWLEDGE_DIR / "forms"
TOPICS_OUT = KNOWLEDGE_DIR / "topics"
ERRORS_CSV = KNOWLEDGE_DIR / "errors.csv"
MANIFEST = KNOWLEDGE_DIR / "manifest.json"

CONCURRENCY = 5
MAX_RETRIES = 3
CHECKPOINT_EVERY = 50
RATE_LIMIT_SECONDS = 0.75

SELECTOR_FORM_NUMBER = (
    ".form-details-sidebar .field--name-field-form-number .field__item"
)
SELECTOR_CASE_TYPE = ".form-details-sidebar .field--name-field-case-type .field__item"
SELECTOR_LEGAL_ACTION = (
    ".form-details-sidebar .field--name-field-legal-action .field__item"
)
SELECTOR_BODY = ".field--name-body, .form-details .content, main #content, article"

COURTHELP_SEEDS = [
    "https://www.nycourts.gov/courthelp/",
    "https://www.nycourts.gov/courthelp/family/",
    "https://www.nycourts.gov/courthelp/safety/",
    "https://www.nycourts.gov/courthelp/goingtocourt/",
    "https://www.nycourts.gov/courthelp/goingtocourt/whichcourt.shtml",
]


@dataclass
class CrawlTarget:
    """A URL to crawl and write as markdown."""

    url: str
    kind: str
    slug: str
    form_code: str = ""
    title: str = ""


@dataclass
class CrawlManifest:
    """Manifest of crawled pages."""

    entries: list[dict] = field(default_factory=list)
    crawled_at: str = ""

    def to_dict(self) -> dict:
        return {
            "crawled_at": self.crawled_at,
            "entries": self.entries,
        }


def slugify(value: str) -> str:
    value = value.strip().lower()
    value = re.sub(r"[^a-z0-9]+", "-", value)
    return value.strip("-") or "page"


def _build_form_prose(
    title: str,
    form_number: str,
    case_types: list[str],
    legal_actions: list[str],
) -> str:
    """Build readable markdown without NY Courts sidebar boilerplate."""
    lines = [f"{title} is an official New York court form.", ""]

    if form_number or case_types or legal_actions:
        lines.append("## About this form")
        if form_number:
            lines.append(f"- **Form Number:** {form_number}")
        if case_types:
            lines.append(f"- **Case Type:** {', '.join(case_types)}")
        if legal_actions:
            lines.append(f"- **Legal Action:** {', '.join(legal_actions)}")
        lines.append("")

    lines.append(
        "Review the PDF preview and gather the required information before filing."
    )
    return "\n".join(lines)


def yaml_escape(value: str) -> str:
    value = value.replace('"', '\\"')
    return f'"{value}"'


def collect_form_targets(limit: Optional[int] = None) -> list[CrawlTarget]:
    targets: list[CrawlTarget] = []
    seen: set[str] = set()

    for json_path in sorted(FORMS_JSON_DIR.rglob("*.json")):
        try:
            data = json.loads(json_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue

        url = str(data.get("official_url") or "").strip()
        if not url or url in seen:
            continue

        form_code = str(data.get("form_code") or data.get("internal_code") or "").strip()
        title = str(data.get("title") or form_code or json_path.stem)
        slug = slugify(form_code or json_path.stem)

        targets.append(
            CrawlTarget(
                url=url,
                kind="form",
                slug=slug,
                form_code=form_code,
                title=title,
            )
        )
        seen.add(url)

        if limit and len(targets) >= limit:
            break

    return targets


def collect_topic_targets(limit: Optional[int] = None) -> list[CrawlTarget]:
    """Collect CourtHelp hub URLs plus one level of child links."""
    targets: list[CrawlTarget] = []
    seen: set[str] = set()

    for seed in COURTHELP_SEEDS:
        if seed not in seen:
            path = urlparse(seed).path.rstrip("/").split("/")[-1] or "courthelp"
            targets.append(
                CrawlTarget(
                    url=seed,
                    kind="topic",
                    slug=slugify(path.replace(".shtml", "")),
                    title=path.replace(".shtml", "").replace("-", " ").title(),
                )
            )
            seen.add(seed)

    return targets[: limit or None]


async def fetch_html(crawler: AsyncWebCrawler, url: str) -> str:
    config = CrawlerRunConfig(cache_mode=CacheMode.BYPASS)
    last_error: Optional[Exception] = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            result = await crawler.arun(url=url, config=config)
            html = getattr(result, "html", None) or getattr(result, "cleaned_html", "")
            if html:
                return html
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            await asyncio.sleep(attempt)

    if last_error:
        raise last_error
    return ""


def extract_form_page(html: str, url: str, target: CrawlTarget) -> dict:
    soup = BeautifulSoup(html, "html.parser")

    def texts(selector: str) -> list[str]:
        return [
            re.sub(r"\s+", " ", el.get_text(" ", strip=True))
            for el in soup.select(selector)
            if el.get_text(strip=True)
        ]

    form_number = " ".join(texts(SELECTOR_FORM_NUMBER)) or target.form_code
    case_types = texts(SELECTOR_CASE_TYPE)
    legal_actions = texts(SELECTOR_LEGAL_ACTION)

    title_el = soup.select_one("h1")
    title = title_el.get_text(" ", strip=True) if title_el else target.title

    body_parts: list[str] = []
    for selector in SELECTOR_BODY.split(", "):
        for el in soup.select(selector):
            text = re.sub(r"\s+", " ", el.get_text("\n", strip=True))
            if text and text not in body_parts and not re.match(
                r"^form details\b", text, re.I
            ):
                body_parts.append(text)

    body = "\n\n".join(body_parts).strip()
    if not body or re.match(r"^form details\b", body, re.I):
        body = _build_form_prose(title, form_number, case_types, legal_actions)

    return {
        "title": title,
        "form_code": form_number or target.form_code,
        "case_types": ", ".join(case_types),
        "legal_action": ", ".join(legal_actions),
        "body": body,
        "source_url": url,
    }


def extract_topic_page(html: str, url: str, target: CrawlTarget) -> dict:
    soup = BeautifulSoup(html, "html.parser")
    title_el = soup.select_one("h1")
    title = title_el.get_text(" ", strip=True) if title_el else target.title

    body_parts: list[str] = []
    for el in soup.select("main, #content, .content, article"):
        text = re.sub(r"\s+", " ", el.get_text("\n", strip=True))
        if len(text) > 80 and text not in body_parts:
            body_parts.append(text)

    body = "\n\n".join(body_parts).strip() or soup.get_text("\n", strip=True)[:8000]

    tags = ["courthelp", "nyc"]
    lower = (title + body).lower()
    if "divorce" in lower or "matrimonial" in lower:
        tags.append("divorce")
    if "custody" in lower or "visitation" in lower:
        tags.append("custody")
    if "support" in lower:
        tags.append("child-support")
    if "protection" in lower or "violence" in lower:
        tags.append("order-of-protection")

    return {
        "title": title,
        "body": body,
        "source_url": url,
        "tags": ", ".join(sorted(set(tags))),
    }


def write_markdown(path: Path, front: dict, body: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    lines = ["---"]
    for key, value in front.items():
        if value:
            lines.append(f"{key}: {yaml_escape(str(value))}")
    lines.append("---")
    lines.append("")
    lines.append(f"# {front.get('title', path.stem)}")
    lines.append("")
    lines.append(body.strip())
    lines.append("")
    path.write_text("\n".join(lines), encoding="utf-8")


def discover_child_topic_links(html: str, base_url: str) -> list[str]:
    soup = BeautifulSoup(html, "html.parser")
    links: list[str] = []
    base_host = urlparse(base_url).netloc

    for anchor in soup.select("a[href]"):
        href = anchor.get("href", "").strip()
        if not href or href.startswith("#"):
            continue
        absolute = urljoin(base_url, href)
        parsed = urlparse(absolute)
        if parsed.netloc != base_host:
            continue
        if "/courthelp/" not in parsed.path:
            continue
        if absolute not in links:
            links.append(absolute)

    return links


async def crawl_targets(
    targets: list[CrawlTarget],
    manifest: CrawlManifest,
    errors: list[dict],
) -> None:
    browser_config = BrowserConfig(headless=True)
    crawled = 0

    async with AsyncWebCrawler(config=browser_config) as crawler:
        for index, target in enumerate(targets, start=1):
            out_dir = FORMS_OUT if target.kind == "form" else TOPICS_OUT
            out_path = out_dir / f"{target.slug}.md"

            try:
                html = await fetch_html(crawler, target.url)
                if not html:
                    raise RuntimeError("empty HTML response")

                if target.kind == "form":
                    extracted = extract_form_page(html, target.url, target)
                    front = {
                        "title": extracted["title"],
                        "form_code": extracted["form_code"],
                        "source_url": extracted["source_url"],
                        "case_types": extracted["case_types"],
                        "legal_action": extracted["legal_action"],
                        "tags": "form, nycourts",
                        "crawled_at": manifest.crawled_at,
                    }
                    write_markdown(out_path, front, extracted["body"])
                else:
                    extracted = extract_topic_page(html, target.url, target)
                    front = {
                        "title": extracted["title"],
                        "source_url": extracted["source_url"],
                        "tags": extracted["tags"],
                        "crawled_at": manifest.crawled_at,
                    }
                    write_markdown(out_path, front, extracted["body"])

                    for child_url in discover_child_topic_links(html, target.url)[:25]:
                        child_slug = slugify(urlparse(child_url).path.rstrip("/").split("/")[-1])
                        child_path = TOPICS_OUT / f"{child_slug}.md"
                        if child_path.exists():
                            continue
                        await asyncio.sleep(RATE_LIMIT_SECONDS)
                        try:
                            child_html = await fetch_html(crawler, child_url)
                            child_data = extract_topic_page(
                                child_html,
                                child_url,
                                CrawlTarget(
                                    url=child_url,
                                    kind="topic",
                                    slug=child_slug,
                                    title=child_slug,
                                ),
                            )
                            child_front = {
                                "title": child_data["title"],
                                "source_url": child_data["source_url"],
                                "tags": child_data["tags"],
                                "crawled_at": manifest.crawled_at,
                            }
                            write_markdown(child_path, child_front, child_data["body"])
                            manifest.entries.append(
                                {
                                    "slug": child_slug,
                                    "url": child_url,
                                    "kind": "topic",
                                    "status": "ok",
                                }
                            )
                        except Exception as child_exc:  # noqa: BLE001
                            errors.append(
                                {
                                    "url": child_url,
                                    "kind": "topic",
                                    "error": str(child_exc),
                                }
                            )

                manifest.entries.append(
                    {
                        "slug": target.slug,
                        "url": target.url,
                        "kind": target.kind,
                        "status": "ok",
                    }
                )
                crawled += 1

                if crawled % CHECKPOINT_EVERY == 0:
                    MANIFEST.write_text(
                        json.dumps(manifest.to_dict(), indent=2),
                        encoding="utf-8",
                    )

            except Exception as exc:  # noqa: BLE001
                errors.append(
                    {
                        "url": target.url,
                        "kind": target.kind,
                        "error": str(exc),
                    }
                )
                manifest.entries.append(
                    {
                        "slug": target.slug,
                        "url": target.url,
                        "kind": target.kind,
                        "status": "error",
                        "error": str(exc),
                    }
                )

            await asyncio.sleep(RATE_LIMIT_SECONDS)


def write_errors(errors: list[dict]) -> None:
    if not errors:
        return
    KNOWLEDGE_DIR.mkdir(parents=True, exist_ok=True)
    with ERRORS_CSV.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=["url", "kind", "error"])
        writer.writeheader()
        writer.writerows(errors)


async def run_async(args: argparse.Namespace) -> None:
    KNOWLEDGE_DIR.mkdir(parents=True, exist_ok=True)
    FORMS_OUT.mkdir(parents=True, exist_ok=True)
    TOPICS_OUT.mkdir(parents=True, exist_ok=True)

    manifest = CrawlManifest(
        crawled_at=datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    )
    errors: list[dict] = []
    targets: list[CrawlTarget] = []

    if not args.topics_only:
        targets.extend(collect_form_targets(args.limit))
    if not args.forms_only:
        targets.extend(collect_topic_targets(args.limit))

    print(f"Crawling {len(targets)} targets...")
    await crawl_targets(targets, manifest, errors)

    MANIFEST.write_text(json.dumps(manifest.to_dict(), indent=2), encoding="utf-8")
    write_errors(errors)
    print(f"Done. Wrote manifest to {MANIFEST}")
    if errors:
        print(f"{len(errors)} errors logged to {ERRORS_CSV}")


def main() -> None:
    parser = argparse.ArgumentParser(description="Crawl NY Courts knowledge into markdown.")
    parser.add_argument("--forms-only", action="store_true", help="Crawl form pages only.")
    parser.add_argument("--topics-only", action="store_true", help="Crawl CourtHelp topics only.")
    parser.add_argument("--limit", type=int, default=None, help="Limit targets per phase.")
    args = parser.parse_args()

    asyncio.run(run_async(args))


if __name__ == "__main__":
    main()
