"""Collect every NY Courts divorce form listing into a CSV.

Crawls the paginated NY Courts forms listing with Crawl4AI's AsyncWebCrawler,
extracts each form's number, title, and URL with BeautifulSoup, follows
pagination until no next page remains, deduplicates by Form URL, and exports
``Divorce_Forms_Extract.csv``. Failed pages are retried up to 3 times and any
that still fail are logged to ``errors.csv``.

Run:
    python collect_form_links.py
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Optional
from urllib.parse import urljoin

import pandas as pd
from bs4 import BeautifulSoup
from tqdm import tqdm

from crawl4ai import AsyncWebCrawler, BrowserConfig, CacheMode, CrawlerRunConfig

# --- Configuration -----------------------------------------------------------

BASE_URL = "https://www.nycourts.gov"

START_URL = (
    "https://www.nycourts.gov/forms?search_api_forms_fulltext="
    "&field_case_type%5B4121%5D=4121&field_case_type%5B4291%5D=4291"
    "&field_case_type%5B241%5D=241&field_case_type%5B1476%5D=1476"
    "&field_case_type%5B4326%5D=4326&field_case_type%5B1441%5D=1441"
    "&field_case_type%5B4281%5D=4281&field_case_type%5B1436%5D=1436"
    "&field_case_type%5B4306%5D=4306&field_case_type%5B1431%5D=1431"
    "&field_case_type%5B4191%5D=4191&field_case_type%5B4311%5D=4311"
    "&field_case_type%5B4256%5D=4256&field_case_type%5B4261%5D=4261"
    "&node_field_court_type%5B161%5D=161&node_field_court_type%5B2221%5D=2221"
)

DEFAULT_OUTPUT = Path(__file__).resolve().parent / "Divorce_Forms_Extract.csv"
DEFAULT_ERRORS = Path(__file__).resolve().parent / "errors.csv"

MAX_RETRIES = 3
MAX_PAGES = 100  # hard safety cap to prevent runaway pagination loops

SELECTOR_ROW = ".teaser-pills.views-row"
SELECTOR_FORM_NUMBER = ".views-field-field-form-number .field-content"
SELECTOR_TITLE_LINK = ".views-field-title .field-content a"
SELECTOR_NEXT = 'a[rel="next"]'

OUTPUT_FIELDS = ["Form Number", "Form Title", "Form URL"]

logger = logging.getLogger("collect_form_links")


# --- Data structures ---------------------------------------------------------


@dataclass(frozen=True)
class FormLink:
    form_number: str
    form_title: str
    form_url: str


@dataclass
class PageError:
    page_url: str
    attempts: int
    error: str


# --- Extraction --------------------------------------------------------------


def extract_forms(html: str, page_url: str) -> list[FormLink]:
    """Extract all form rows from a listing page's HTML."""
    soup = BeautifulSoup(html, "lxml")
    forms: list[FormLink] = []
    for row in soup.select(SELECTOR_ROW):
        link = row.select_one(SELECTOR_TITLE_LINK)
        if link is None:
            continue
        href = (link.get("href") or "").strip()
        if not href:
            continue
        number_node = row.select_one(SELECTOR_FORM_NUMBER)
        forms.append(
            FormLink(
                form_number=number_node.get_text(strip=True) if number_node else "",
                form_title=link.get_text(strip=True),
                form_url=urljoin(page_url, href),
            )
        )
    return forms


def find_next_page_url(html: str, page_url: str) -> Optional[str]:
    """Return the absolute URL of the next pagination page, or None."""
    soup = BeautifulSoup(html, "lxml")
    next_link = soup.select_one(SELECTOR_NEXT)
    if next_link is None:
        return None
    href = (next_link.get("href") or "").strip()
    if not href:
        return None
    return urljoin(page_url, href)


# --- Crawling ----------------------------------------------------------------


async def fetch_html(
    crawler: AsyncWebCrawler, url: str, run_config: CrawlerRunConfig
) -> str:
    """Fetch one page's raw HTML, raising on failure."""
    result = await crawler.arun(url=url, config=run_config)
    if not result.success:
        raise RuntimeError(result.error_message or "crawl failed")
    if not result.html:
        raise RuntimeError("empty HTML returned")
    return result.html


async def fetch_with_retries(
    crawler: AsyncWebCrawler, url: str, run_config: CrawlerRunConfig
) -> str:
    """Fetch a page, retrying up to MAX_RETRIES times with backoff."""
    last_error: Optional[Exception] = None
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            return await fetch_html(crawler, url, run_config)
        except Exception as exc:  # noqa: BLE001 - retry any failure
            last_error = exc
            logger.warning("Attempt %d/%d failed: %s", attempt, MAX_RETRIES, exc)
            if attempt < MAX_RETRIES:
                await asyncio.sleep(2 ** (attempt - 1))
    raise last_error if last_error else RuntimeError("unknown fetch error")


async def crawl_all_pages(
    start_url: str,
) -> tuple[list[FormLink], list[PageError], int]:
    """Crawl every listing page, returning forms, errors, and pages crawled."""
    browser_config = BrowserConfig(headless=True, verbose=False)
    run_config = CrawlerRunConfig(
        cache_mode=CacheMode.BYPASS,
        wait_until="domcontentloaded",
        page_timeout=60000,
        verbose=False,
    )

    forms: list[FormLink] = []
    seen_urls: set[str] = set()
    errors: list[PageError] = []
    pages_crawled = 0

    current_url: Optional[str] = start_url
    visited_pages: set[str] = set()

    async with AsyncWebCrawler(config=browser_config) as crawler:
        with tqdm(desc="Crawling pages", unit="page") as progress:
            while current_url and pages_crawled < MAX_PAGES:
                if current_url in visited_pages:
                    logger.info("Already visited page, stopping: %s", current_url)
                    break
                visited_pages.add(current_url)
                page_no = pages_crawled + 1

                try:
                    html = await fetch_with_retries(crawler, current_url, run_config)
                except Exception as exc:  # noqa: BLE001 - log and continue
                    logger.error("Page %d failed after retries: %s", page_no, exc)
                    errors.append(
                        PageError(
                            page_url=current_url,
                            attempts=MAX_RETRIES,
                            error=f"{type(exc).__name__}: {exc}",
                        )
                    )
                    break

                pages_crawled += 1
                progress.update(1)

                page_forms = extract_forms(html, current_url)
                new_count = 0
                for form in page_forms:
                    if form.form_url not in seen_urls:
                        seen_urls.add(form.form_url)
                        forms.append(form)
                        new_count += 1

                logger.info(
                    "Page %d: found %d forms (%d new) | total unique so far: %d",
                    page_no,
                    len(page_forms),
                    new_count,
                    len(forms),
                )

                current_url = find_next_page_url(html, current_url)

    return forms, errors, pages_crawled


# --- Output ------------------------------------------------------------------


def export_forms(path: Path, forms: list[FormLink]) -> None:
    """Write the collected forms to CSV via pandas."""
    frame = pd.DataFrame(
        [
            {
                "Form Number": f.form_number,
                "Form Title": f.form_title,
                "Form URL": f.form_url,
            }
            for f in forms
        ],
        columns=OUTPUT_FIELDS,
    )
    path.parent.mkdir(parents=True, exist_ok=True)
    frame.to_csv(path, index=False, encoding="utf-8-sig")


def export_errors(path: Path, errors: list[PageError]) -> None:
    """Write failed page URLs to CSV via pandas."""
    frame = pd.DataFrame(
        [
            {"Page URL": e.page_url, "Attempts": e.attempts, "Error": e.error}
            for e in errors
        ],
        columns=["Page URL", "Attempts", "Error"],
    )
    path.parent.mkdir(parents=True, exist_ok=True)
    frame.to_csv(path, index=False, encoding="utf-8-sig")


# --- CLI ---------------------------------------------------------------------


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument(
        "start_url",
        nargs="?",
        default=START_URL,
        help="Listing page URL to start from (positional). Defaults to the "
        "built-in NY Courts divorce forms URL.",
    )
    parser.add_argument(
        "--start-url",
        dest="start_url_opt",
        default=None,
        help="Alternative way to provide the start URL (overrides positional).",
    )
    parser.add_argument(
        "--output", type=Path, default=DEFAULT_OUTPUT, help="Output CSV path."
    )
    parser.add_argument(
        "--errors", type=Path, default=DEFAULT_ERRORS, help="Errors CSV path."
    )
    args = parser.parse_args(argv)
    if args.start_url_opt:
        args.start_url = args.start_url_opt
    return args


async def run(args: argparse.Namespace) -> int:
    forms, errors, pages_crawled = await crawl_all_pages(args.start_url)

    export_forms(args.output, forms)
    export_errors(args.errors, errors)

    total_found = sum(1 for _ in forms)
    logger.info("=" * 48)
    logger.info("Total Pages Crawled    : %d", pages_crawled)
    logger.info("Total Forms Found      : %d", total_found)
    logger.info("Total Unique Forms Exported: %d", len(forms))
    if errors:
        logger.info("Failed Pages           : %d (see %s)", len(errors), args.errors)
    logger.info("Output                 : %s", args.output)
    logger.info("=" * 48)
    return 0


def main(argv: Optional[list[str]] = None) -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
        stream=sys.stdout,
    )
    args = parse_args(argv)
    return asyncio.run(run(args))


if __name__ == "__main__":
    raise SystemExit(main())
