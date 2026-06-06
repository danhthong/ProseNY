"""End-to-end pipeline: collect NY Courts form links, then enrich them.

Runs two stages in sequence, sharing one intermediate CSV:

1. ``collect_form_links`` crawls the paginated listing and writes
   ``Divorce_Forms_Extract.csv`` (Form Number, Form Title, Form URL).
2. ``enrich_forms`` visits each Form URL, extracts metadata, resolves PDF
   redirect URLs, and writes ``forms_enriched.csv``.

Run:
    python run_pipeline.py
    python run_pipeline.py "https://www.nycourts.gov/forms?...your filter..."
    python run_pipeline.py --download-pdfs
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Optional

HERE = Path(__file__).resolve().parent
ENRICH_DIR = HERE / "crawl4ai_forms"

# Make both stage modules importable from this single entry point.
sys.path.insert(0, str(HERE))
sys.path.insert(0, str(ENRICH_DIR))

import collect_form_links  # noqa: E402  (path setup must run first)
import enrich_forms  # noqa: E402

# Shared intermediate file produced by stage 1 and consumed by stage 2.
FORMS_CSV = HERE / "Divorce_Forms_Extract.csv"
COLLECT_ERRORS = HERE / "errors.csv"
ENRICHED_CSV = ENRICH_DIR / "forms_enriched.csv"
ENRICH_ERRORS = ENRICH_DIR / "errors.csv"
DOWNLOAD_DIR = ENRICH_DIR / "download_pdfs"


def parse_args(argv: Optional[list[str]] = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument(
        "start_url",
        nargs="?",
        default=collect_form_links.START_URL,
        help="Listing page URL to crawl (positional). Defaults to the built-in "
        "NY Courts divorce forms URL.",
    )
    parser.add_argument(
        "--download-pdfs",
        action="store_true",
        help="Also download each resolved PDF during enrichment.",
    )
    parser.add_argument(
        "--skip-collect",
        action="store_true",
        help="Skip stage 1 and enrich the existing Divorce_Forms_Extract.csv.",
    )
    parser.add_argument(
        "--skip-enrich",
        action="store_true",
        help="Run only stage 1 (collect form links).",
    )
    return parser.parse_args(argv)


def main(argv: Optional[list[str]] = None) -> int:
    args = parse_args(argv)

    if not args.skip_collect:
        print("=" * 60)
        print("STAGE 1/2: Collecting form links")
        print("=" * 60)
        collect_rc = collect_form_links.main(
            [
                args.start_url,
                "--output",
                str(FORMS_CSV),
                "--errors",
                str(COLLECT_ERRORS),
            ]
        )
        if collect_rc != 0:
            print("Stage 1 failed; aborting.", file=sys.stderr)
            return collect_rc

    if args.skip_enrich:
        return 0

    if not FORMS_CSV.exists():
        print(
            f"Cannot enrich: {FORMS_CSV} not found. Run stage 1 first.",
            file=sys.stderr,
        )
        return 1

    print("=" * 60)
    print("STAGE 2/2: Enriching forms")
    print("=" * 60)
    enrich_argv = [
        "--input",
        str(FORMS_CSV),
        "--output",
        str(ENRICHED_CSV),
        "--errors",
        str(ENRICH_ERRORS),
    ]
    if args.download_pdfs:
        enrich_argv += ["--download-pdfs", "--download-dir", str(DOWNLOAD_DIR)]

    return enrich_forms.main(enrich_argv)


if __name__ == "__main__":
    raise SystemExit(main())
