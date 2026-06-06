# NY Courts Forms Pipeline

Two-stage pipeline that collects every NY Courts form from a paginated listing
and then enriches each form with metadata and resolved PDF links.

```
collect_forms/
├── collect_form_links.py     # Stage 1: crawl listing -> Divorce_Forms_Extract.csv
├── crawl4ai_forms/
│   └── enrich_forms.py       # Stage 2: enrich each form -> forms_enriched.csv
├── run_pipeline.py           # Orchestrator: runs stage 1 then stage 2
├── requirements.txt
├── setup.ps1                 # One-time environment setup
└── run.ps1                   # Run the full pipeline
```

## Stages

1. **Collect** (`collect_form_links.py`) — crawls the paginated listing with
   Crawl4AI, extracts `Form Number`, `Form Title`, and absolute `Form URL` from
   every `.teaser-pills.views-row`, follows pagination until no next page
   remains, deduplicates by Form URL, and writes `Divorce_Forms_Extract.csv`.
2. **Enrich** (`crawl4ai_forms/enrich_forms.py`) — visits each Form URL, extracts
   form number / case type / legal action / PDF links, resolves each
   `…/media/<id>` redirect to its final PDF URL and filename, and writes
   `crawl4ai_forms/forms_enriched.csv`.

The intermediate `Divorce_Forms_Extract.csv` is the hand-off file between the
two stages (stage 1's output is stage 2's input).

## Environment note

Crawl4AI and its dependencies (`lxml`, Playwright) do **not** ship prebuilt
wheels for Python 3.14, and building `lxml` from source on Windows fails without
a local `libxml2`. Use **Python 3.11 or 3.12**. Setup uses
[`uv`](https://docs.astral.sh/uv/).

## Setup (one-time)

```powershell
.\collect_forms\setup.ps1
```

This creates `collect_forms/.venv` (Python 3.11), installs all dependencies for
both stages, and installs Playwright Chromium.

Manual equivalent:

```bash
cd collect_forms
uv venv --python 3.11 .venv
uv pip install --python .venv\Scripts\python.exe -r requirements.txt
.venv\Scripts\python.exe -m playwright install chromium
```

## Run the pipeline

Default NY Courts divorce-forms URL:

```powershell
.\collect_forms\run.ps1
```

Custom listing URL (passed straight through to stage 1):

```powershell
.\collect_forms\run.ps1 "https://www.nycourts.gov/forms?search_api_forms_fulltext=&field_case_type%5B4121%5D=4121&..."
```

Also download every resolved PDF:

```powershell
.\collect_forms\run.ps1 --download-pdfs
```

### Pipeline flags

- `start_url` (positional) — listing URL to crawl. Defaults to the built-in
  divorce-forms URL.
- `--download-pdfs` — download each resolved PDF into
  `crawl4ai_forms/download_pdfs/`.
- `--skip-collect` — reuse the existing `Divorce_Forms_Extract.csv` and only
  enrich.
- `--skip-enrich` — only collect form links (stage 1).

## Running stages individually

```powershell
# Stage 1 only (custom URL):
.\.venv\Scripts\python.exe collect_form_links.py "https://www.nycourts.gov/forms?..."

# Stage 2 only:
.\.venv\Scripts\python.exe crawl4ai_forms\enrich_forms.py
```

## Outputs

- `Divorce_Forms_Extract.csv` — `Form Number, Form Title, Form URL`
- `crawl4ai_forms/forms_enriched.csv` — adds `Extracted Form Number`,
  `Case Type`, `Legal Action`, `Original PDF URLs`, `Resolved PDF URLs`,
  `PDF Filenames` (and `Local PDF Path` with `--download-pdfs`)
- `errors.csv` (stage 1) and `crawl4ai_forms/errors.csv` (stage 2) — failures

See `crawl4ai_forms/README.md` for enrichment details, including the Cloudflare
note about PDF redirect resolution.

## Verified run

A full run collected **489 forms** across 4 pages and enriched all 489 with
**0 failures**.
