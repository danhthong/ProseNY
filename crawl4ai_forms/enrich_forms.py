"""Enrich NY court divorce forms metadata by crawling each form page.

Reads an input CSV of forms, visits every Form URL with Crawl4AI's
AsyncWebCrawler (10 concurrent, 3 retries per URL), extracts metadata with
BeautifulSoup, resolves each PDF redirect URL to its final location and
filename, writes intermediate checkpoints every 50 rows, and exports the final
result to ``forms_enriched.csv``. Failures are logged to ``errors.csv``.

PDF downloading is optional and disabled by default; pass ``--download-pdfs``
to save resolved PDFs into ``download_pdfs/`` and record their local paths.
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import os
import re
import shutil
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional
from urllib.parse import unquote, urljoin, urlsplit

import aiohttp
from bs4 import BeautifulSoup
from tqdm.asyncio import tqdm

from crawl4ai import AsyncWebCrawler, BrowserConfig, CacheMode, CrawlerRunConfig

# --- Configuration -----------------------------------------------------------

DEFAULT_INPUT = Path(__file__).resolve().parent.parent / "Divorce_Forms_Extract.csv"
DEFAULT_OUTPUT = Path(__file__).resolve().parent / "forms_enriched.csv"
DEFAULT_ERRORS = Path(__file__).resolve().parent / "errors.csv"
DEFAULT_DOWNLOAD_DIR = Path(__file__).resolve().parent / "download_pdfs"

CONCURRENCY = 10
MAX_RETRIES = 3
CHECKPOINT_EVERY = 50

PDF_CONCURRENCY = 20
PDF_TIMEOUT_SECONDS = 30

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)
# Some hosts (e.g. NY Courts, behind Cloudflare) reject plain HTTP clients such
# as aiohttp with HTTP 403 based on TLS fingerprinting, while allowing curl.
# When available, curl is used as a fallback so redirect resolution and
# downloads still succeed. Resolved lazily and cached.
_CURL_PATH: Optional[str] = shutil.which("curl")

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

LOCAL_PDF_FIELD = "Local PDF Path"

OUTPUT_FIELDS = [
    "Original Form Number",
    "Original Form Title",
    "Form URL",
    "Extracted Form Number",
    "Case Type",
    "Legal Action",
    "Original PDF URLs",
    "Resolved PDF URLs",
    "PDF Filenames",
]

# Shared HTTP resources for PDF redirect resolution / downloading. Set in run().
_PDF_SESSION: Optional[aiohttp.ClientSession] = None
_PDF_SEMAPHORE: Optional[asyncio.Semaphore] = None


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
    original_pdf_urls: str = ""
    resolved_pdf_urls: str = ""
    pdf_filenames: str = ""
    local_pdf_paths: str = ""

    def to_output_row(self) -> dict:
        return {
            "Original Form Number": self.original_form_number,
            "Original Form Title": self.original_form_title,
            "Form URL": self.form_url,
            "Extracted Form Number": self.extracted_form_number,
            "Case Type": self.case_type,
            "Legal Action": self.legal_action,
            "Original PDF URLs": self.original_pdf_urls,
            "Resolved PDF URLs": self.resolved_pdf_urls,
            "PDF Filenames": self.pdf_filenames,
            LOCAL_PDF_FIELD: self.local_pdf_paths,
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
    fieldnames: list[str]
    download_pdfs: bool = False
    download_dir: Path = DEFAULT_DOWNLOAD_DIR
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


def write_output(
    path: Path, records: list[FormRecord], fieldnames: list[str]
) -> None:
    """Write enriched records to CSV in their original input order."""
    ordered = sorted(records, key=lambda r: r.index)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
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


def _pdf_url_list(soup: BeautifulSoup, selector: str, base_url: str) -> list[str]:
    hrefs: list[str] = []
    for anchor in soup.select(selector):
        href = anchor.get("href")
        if href:
            hrefs.append(urljoin(base_url, href.strip()))
    return hrefs


def extract_fields(html: str, record: FormRecord) -> list[str]:
    """Populate a record's enrichment fields from raw page HTML.

    Returns the list of extracted (unresolved) PDF URLs so the caller can
    resolve their redirects afterwards.
    """
    soup = BeautifulSoup(html, "lxml")
    record.extracted_form_number = _text_single(soup, SELECTOR_FORM_NUMBER)
    record.case_type = _text_joined(soup, SELECTOR_CASE_TYPE)
    record.legal_action = _text_joined(soup, SELECTOR_LEGAL_ACTION)
    extracted_pdf_urls = _pdf_url_list(soup, SELECTOR_PDF_LINKS, record.form_url)
    record.original_pdf_urls = "|".join(extracted_pdf_urls)
    return extracted_pdf_urls


# --- PDF redirect resolution -------------------------------------------------


def _filename_from_content_disposition(header: Optional[str]) -> str:
    """Extract a filename from a Content-Disposition header value, if present."""
    if not header:
        return ""
    # RFC 5987 extended form: filename*=UTF-8''name.pdf
    match = re.search(r"filename\*\s*=\s*[^']*''([^;]+)", header, re.IGNORECASE)
    if match:
        return unquote(match.group(1).strip().strip('"'))
    # Plain form: filename="name.pdf"
    match = re.search(r'filename\s*=\s*"?([^";]+)"?', header, re.IGNORECASE)
    if match:
        return match.group(1).strip()
    return ""


def _filename_from_url(url: str) -> str:
    """Derive a filename from a URL path's final segment."""
    path = urlsplit(url).path
    name = path.rsplit("/", 1)[-1] if path else ""
    return unquote(name)


async def _resolve_with_curl(url: str) -> Optional[tuple[str, str]]:
    """Resolve redirects using curl (impersonates a browser TLS fingerprint).

    Returns ``(final_url, filename)`` or ``None`` if curl is unavailable/fails.
    """
    if not _CURL_PATH:
        return None
    try:
        proc = await asyncio.create_subprocess_exec(
            _CURL_PATH,
            "-sS",
            "-L",
            "-o",
            os.devnull,
            "-A",
            USER_AGENT,
            "--max-time",
            str(PDF_TIMEOUT_SECONDS),
            "-w",
            "%{url_effective}",
            url,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.DEVNULL,
        )
        stdout, _ = await proc.communicate()
    except Exception:  # noqa: BLE001 - curl is a best-effort fallback
        return None
    if proc.returncode != 0:
        return None
    final_url = stdout.decode("utf-8", "replace").strip()
    if not final_url:
        return None
    return (final_url, _filename_from_url(final_url))


async def resolve_redirect_url(url: str) -> tuple[str, str]:
    """Resolve a (possibly redirecting) PDF URL to its final URL and filename.

    Tries an aiohttp HEAD request first, falling back to GET, then to curl
    (which gets past hosts that block aiohttp). Never raises: on total failure
    it returns ``(url, "")``.
    """
    assert _PDF_SESSION is not None and _PDF_SEMAPHORE is not None
    timeout = aiohttp.ClientTimeout(total=PDF_TIMEOUT_SECONDS)

    async with _PDF_SEMAPHORE:
        for method in ("head", "get"):
            try:
                request = getattr(_PDF_SESSION, method)
                async with request(
                    url, allow_redirects=True, timeout=timeout
                ) as response:
                    if response.status >= 400:
                        if method == "get":
                            await response.release()
                        continue
                    final_url = str(response.url)
                    filename = _filename_from_content_disposition(
                        response.headers.get("Content-Disposition")
                    )
                    if not filename:
                        filename = _filename_from_url(final_url)
                    if method == "get":
                        await response.release()
                    return (final_url, filename)
            except Exception:  # noqa: BLE001 - fall through to next method / fallback
                continue

        curl_result = await _resolve_with_curl(url)
        if curl_result is not None:
            return curl_result

    return (url, "")


async def resolve_pdf_urls(pdf_urls: list[str]) -> tuple[list[str], list[str]]:
    """Resolve a list of PDF URLs concurrently.

    Returns parallel lists of final URLs and filenames, preserving input order.
    Individual failures degrade gracefully (original URL, empty filename).
    """
    if not pdf_urls:
        return ([], [])
    results = await asyncio.gather(*(resolve_redirect_url(u) for u in pdf_urls))
    resolved_urls = [final for final, _ in results]
    filenames = [name for _, name in results]
    return (resolved_urls, filenames)


async def download_pdf(url: str, filename: str, dest_dir: Path) -> str:
    """Download one resolved PDF into ``dest_dir``, skipping existing files.

    Returns the local path as a string, or "" on failure / missing filename.
    Never raises.
    """
    if not url or not filename:
        return ""
    assert _PDF_SESSION is not None and _PDF_SEMAPHORE is not None

    dest_dir.mkdir(parents=True, exist_ok=True)
    target = dest_dir / filename
    if target.exists():
        return str(target)

    timeout = aiohttp.ClientTimeout(total=PDF_TIMEOUT_SECONDS)
    async with _PDF_SEMAPHORE:
        data: Optional[bytes] = None
        try:
            async with _PDF_SESSION.get(
                url, allow_redirects=True, timeout=timeout
            ) as response:
                if response.status < 400:
                    data = await response.read()
        except Exception:  # noqa: BLE001 - downloads are best-effort
            data = None

        if data is not None:
            try:
                target.write_bytes(data)
                return str(target)
            except OSError:
                return ""

        # aiohttp blocked or failed; fall back to curl writing directly to disk.
        if await _download_with_curl(url, target):
            return str(target)

    return ""


async def _download_with_curl(url: str, target: Path) -> bool:
    """Download ``url`` to ``target`` using curl. Returns True on success."""
    if not _CURL_PATH:
        return False
    try:
        proc = await asyncio.create_subprocess_exec(
            _CURL_PATH,
            "-sS",
            "-L",
            "-f",
            "-o",
            str(target),
            "-A",
            USER_AGENT,
            "--max-time",
            str(PDF_TIMEOUT_SECONDS),
            url,
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.DEVNULL,
        )
        await proc.communicate()
    except Exception:  # noqa: BLE001 - best-effort
        return False
    return proc.returncode == 0 and target.exists()


async def download_pdfs(
    urls: list[str], filenames: list[str], dest_dir: Path
) -> list[str]:
    """Download a list of resolved PDFs concurrently, returning local paths."""
    if not urls:
        return []
    tasks = [
        download_pdf(url, name, dest_dir) for url, name in zip(urls, filenames)
    ]
    return await asyncio.gather(*tasks)


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
    extracted_pdf_urls: list[str] = []
    async with semaphore:
        for attempt in range(1, MAX_RETRIES + 1):
            try:
                html = await fetch_html(crawler, record.form_url, run_config)
                extracted_pdf_urls = extract_fields(html, record)
                last_error = None
                break
            except Exception as exc:  # noqa: BLE001 - record and retry any failure
                last_error = f"{type(exc).__name__}: {exc}"
                if attempt < MAX_RETRIES:
                    await asyncio.sleep(2 ** (attempt - 1))

    # Resolve PDF redirects outside the crawl semaphore so it does not block
    # other pages from being fetched.
    if last_error is None and extracted_pdf_urls:
        resolved_urls, filenames = await resolve_pdf_urls(extracted_pdf_urls)
        record.resolved_pdf_urls = "|".join(resolved_urls)
        record.pdf_filenames = "|".join(filenames)
        if state.download_pdfs:
            local_paths = await download_pdfs(
                resolved_urls, filenames, state.download_dir
            )
            record.local_pdf_paths = "|".join(local_paths)

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
            write_output(state.output_path, all_records, state.fieldnames)


async def run(
    input_path: Path,
    output_path: Path,
    errors_path: Path,
    download_pdfs_flag: bool = False,
    download_dir: Path = DEFAULT_DOWNLOAD_DIR,
) -> tuple[int, int]:
    global _PDF_SESSION, _PDF_SEMAPHORE

    records = read_input(input_path)
    if not records:
        print(f"No rows with a Form URL found in {input_path}", file=sys.stderr)
        return (0, 0)

    fieldnames = list(OUTPUT_FIELDS)
    if download_pdfs_flag:
        fieldnames.append(LOCAL_PDF_FIELD)

    errors: list[ErrorRecord] = []
    state = RunState(
        output_path=output_path,
        fieldnames=fieldnames,
        download_pdfs=download_pdfs_flag,
        download_dir=download_dir,
    )

    browser_config = BrowserConfig(headless=True, verbose=False)
    run_config = CrawlerRunConfig(
        cache_mode=CacheMode.BYPASS,
        page_timeout=60000,
        wait_until="domcontentloaded",
        verbose=False,
    )
    semaphore = asyncio.Semaphore(CONCURRENCY)
    _PDF_SEMAPHORE = asyncio.Semaphore(PDF_CONCURRENCY)

    async with aiohttp.ClientSession(headers={"User-Agent": USER_AGENT}) as session:
        _PDF_SESSION = session
        try:
            async with AsyncWebCrawler(config=browser_config) as crawler:
                tasks = [
                    process_record(
                        crawler,
                        run_config,
                        semaphore,
                        record,
                        state,
                        records,
                        errors,
                    )
                    for record in records
                ]
                await tqdm.gather(*tasks, desc="Enriching forms", unit="form")
        finally:
            _PDF_SESSION = None

    write_output(output_path, records, fieldnames)
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
    parser.add_argument(
        "--download-pdfs",
        action="store_true",
        help="Download each resolved PDF into the download directory.",
    )
    parser.add_argument(
        "--download-dir",
        type=Path,
        default=DEFAULT_DOWNLOAD_DIR,
        help="Directory for downloaded PDFs (used with --download-pdfs).",
    )
    return parser.parse_args(argv)


def main(argv: Optional[list[str]] = None) -> int:
    args = parse_args(argv)
    if not args.input.exists():
        print(f"Input file not found: {args.input}", file=sys.stderr)
        return 1

    total, failed = asyncio.run(
        run(
            args.input,
            args.output,
            args.errors,
            download_pdfs_flag=args.download_pdfs,
            download_dir=args.download_dir,
        )
    )
    succeeded = total - failed
    print(
        f"Done. {succeeded}/{total} forms enriched. "
        f"{failed} failed (see {args.errors}). Output: {args.output}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
