"""Enrich NY court divorce forms metadata by crawling each form page.

Reads an input CSV of forms, visits every Form URL with Crawl4AI's
AsyncWebCrawler (10 concurrent, 3 retries per URL), extracts metadata with
BeautifulSoup, writes intermediate checkpoints every 50 rows, and exports the
final result to ``forms_enriched.csv``. Failures are logged to ``errors.csv``.
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional
from urllib.parse import urljoin

from bs4 import BeautifulSoup
from tqdm.asyncio import tqdm

from crawl4ai import AsyncWebCrawler, BrowserConfig, CacheMode, CrawlerRunConfig

# --- Configuration -----------------------------------------------------------

DEFAULT_INPUT = Path(__file__).resolve().parent.parent / "Divorce_Forms_Extract.csv"
DEFAULT_OUTPUT = Path(__file__).resolve().parent / "forms_enriched.csv"
DEFAULT_ERRORS = Path(__file__).resolve().parent / "errors.csv"

CONCURRENCY = 10
MAX_RETRIES = 3
CHECKPOINT_EVERY = 50

SELECTOR_FORM_NUMBER = (
    ".form-details-sidebar .field--name-field-form-number .field__item"
)
SELECTOR_CASE_TYPE = ".form-details-sidebar .field--name-field-case-type .field__item"
SELECTOR_LEGAL_ACTION = (
    ".form-details-sidebar .field--name-field-legal-action .field__item"
)
SELECTOR_PDF_LINKS = (
    ".field--name-field-file-set .field--name-field-files .field--name-field-file a"
)

OUTPUT_FIELDS = [
    "Original Form Number",
    "Original Form Title",
    "Form URL",
    "Extracted Form Number",
    "Case Type",
    "Legal Action",
    "PDF URLs",
]


# --- Data structures ---------------------------------------------------------


@dataclass
class FormRecord:
    """A single input row plus its enrichment results."""

    index: int
    original_form_number: str
    original_form_title: str
    form_url: str
    extracted_form_number: str = ""
    case_type: str = ""
    legal_action: str = ""
    pdf_urls: str = ""

    def to_output_row(self) -> dict:
        return {
            "Original Form Number": self.original_form_number,
            "Original Form Title": self.original_form_title,
            "Form URL": self.form_url,
            "Extracted Form Number": self.extracted_form_number,
            "Case Type": self.case_type,
            "Legal Action": self.legal_action,
            "PDF URLs": self.pdf_urls,
        }


@dataclass
class ErrorRecord:
    form_url: str
    original_form_number: str
    attempts: int
    error: str


@dataclass
class RunState:
    """Shared, mutable state guarded by an asyncio lock for checkpointing."""

    output_path: Path
    completed: int = 0
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)


# --- Input / output ----------------------------------------------------------


def read_input(path: Path) -> list[FormRecord]:
    """Load the input CSV into ordered FormRecord objects."""
    records: list[FormRecord] = []
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        for idx, row in enumerate(reader):
            url = (row.get("Form URL") or "").strip()
            if not url:
                continue
            records.append(
                FormRecord(
                    index=idx,
                    original_form_number=(row.get("Form Number") or "").strip(),
                    original_form_title=(row.get("Form Title") or "").strip(),
                    form_url=url,
                )
            )
    return records


def write_output(path: Path, records: list[FormRecord]) -> None:
    """Write enriched records to CSV in their original input order."""
    ordered = sorted(records, key=lambda r: r.index)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_FIELDS)
        writer.writeheader()
        for record in ordered:
            writer.writerow(record.to_output_row())


def write_errors(path: Path, errors: list[ErrorRecord]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=["Form URL", "Original Form Number", "Attempts", "Error"],
        )
        writer.writeheader()
        for err in errors:
            writer.writerow(
                {
                    "Form URL": err.form_url,
                    "Original Form Number": err.original_form_number,
                    "Attempts": err.attempts,
                    "Error": err.error,
                }
            )


# --- Extraction --------------------------------------------------------------


def _text_single(soup: BeautifulSoup, selector: str) -> str:
    node = soup.select_one(selector)
    return node.get_text(strip=True) if node else ""


def _text_joined(soup: BeautifulSoup, selector: str) -> str:
    values = [n.get_text(strip=True) for n in soup.select(selector)]
    return ", ".join(v for v in values if v)


def _pdf_urls(soup: BeautifulSoup, selector: str, base_url: str) -> str:
    hrefs: list[str] = []
    for anchor in soup.select(selector):
        href = anchor.get("href")
        if href:
            hrefs.append(urljoin(base_url, href.strip()))
    return "|".join(hrefs)


def extract_fields(html: str, record: FormRecord) -> None:
    """Populate a record's enrichment fields from raw page HTML."""
    soup = BeautifulSoup(html, "lxml")
    record.extracted_form_number = _text_single(soup, SELECTOR_FORM_NUMBER)
    record.case_type = _text_joined(soup, SELECTOR_CASE_TYPE)
    record.legal_action = _text_joined(soup, SELECTOR_LEGAL_ACTION)
    record.pdf_urls = _pdf_urls(soup, SELECTOR_PDF_LINKS, record.form_url)


# --- Crawling ----------------------------------------------------------------


async def fetch_html(
    crawler: AsyncWebCrawler, url: str, run_config: CrawlerRunConfig
) -> str:
    """Fetch a single URL and return its raw HTML, raising on failure."""
    result = await crawler.arun(url=url, config=run_config)
    if not result.success:
        raise RuntimeError(result.error_message or "crawl failed")
    if not result.html:
        raise RuntimeError("empty HTML returned")
    return result.html


async def process_record(
    crawler: AsyncWebCrawler,
    run_config: CrawlerRunConfig,
    semaphore: asyncio.Semaphore,
    record: FormRecord,
    state: RunState,
    all_records: list[FormRecord],
    errors: list[ErrorRecord],
) -> None:
    """Fetch + extract one record with retries, updating shared state."""
    last_error: Optional[str] = None
    async with semaphore:
        for attempt in range(1, MAX_RETRIES + 1):
            try:
                html = await fetch_html(crawler, record.form_url, run_config)
                extract_fields(html, record)
                last_error = None
                break
            except Exception as exc:  # noqa: BLE001 - record and retry any failure
                last_error = f"{type(exc).__name__}: {exc}"
                if attempt < MAX_RETRIES:
                    await asyncio.sleep(2 ** (attempt - 1))

    if last_error is not None:
        errors.append(
            ErrorRecord(
                form_url=record.form_url,
                original_form_number=record.original_form_number,
                attempts=MAX_RETRIES,
                error=last_error,
            )
        )

    async with state.lock:
        state.completed += 1
        if state.completed % CHECKPOINT_EVERY == 0:
            write_output(state.output_path, all_records)


async def run(
    input_path: Path, output_path: Path, errors_path: Path
) -> tuple[int, int]:
    records = read_input(input_path)
    if not records:
        print(f"No rows with a Form URL found in {input_path}", file=sys.stderr)
        return (0, 0)

    errors: list[ErrorRecord] = []
    state = RunState(output_path=output_path)

    browser_config = BrowserConfig(headless=True, verbose=False)
    run_config = CrawlerRunConfig(
        cache_mode=CacheMode.BYPASS,
        page_timeout=60000,
        wait_until="domcontentloaded",
        verbose=False,
    )
    semaphore = asyncio.Semaphore(CONCURRENCY)

    async with AsyncWebCrawler(config=browser_config) as crawler:
        tasks = [
            process_record(
                crawler, run_config, semaphore, record, state, records, errors
            )
            for record in records
        ]
        await tqdm.gather(*tasks, desc="Enriching forms", unit="form")

    write_output(output_path, records)
    write_errors(errors_path, errors)

    return (len(records), len(errors))


# --- CLI ---------------------------------------------------------------------


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--input", type=Path, default=DEFAULT_INPUT, help="Input CSV path."
    )
    parser.add_argument(
        "--output", type=Path, default=DEFAULT_OUTPUT, help="Output CSV path."
    )
    parser.add_argument(
        "--errors", type=Path, default=DEFAULT_ERRORS, help="Errors CSV path."
    )
    return parser.parse_args(argv)


def main(argv: Optional[list[str]] = None) -> int:
    args = parse_args(argv)
    if not args.input.exists():
        print(f"Input file not found: {args.input}", file=sys.stderr)
        return 1

    total, failed = asyncio.run(run(args.input, args.output, args.errors))
    succeeded = total - failed
    print(
        f"Done. {succeeded}/{total} forms enriched. "
        f"{failed} failed (see {args.errors}). Output: {args.output}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
