# NY Court Forms Enricher (Crawl4AI)

Crawls each NY court divorce form page listed in an input CSV and extracts
structured metadata (form number, case type, legal action, and PDF links),
exporting an enriched CSV.

## What it does

For every `Form URL` in the input CSV the script:

1. Visits the page with Crawl4AI's `AsyncWebCrawler` (10 concurrent, 3 retries).
2. Parses the rendered HTML with BeautifulSoup using these selectors:
   - **Form Number** — `.form-details-sidebar .field--name-field-form-number .field__item`
   - **Case Type** — `.form-details-sidebar .field--name-field-case-type .field__item` (comma-joined)
   - **Legal Action** — `.form-details-sidebar .field--name-field-legal-action .field__item` (comma-joined)
   - **PDF URLs** — `.field--name-field-file-set .field--name-field-files .field--name-field-file a` (`href`s, pipe-joined)
3. Writes an intermediate checkpoint every 50 rows.
4. Exports `forms_enriched.csv` and logs failures to `errors.csv`.

## Input

`Divorce_Forms_Extract.csv` (repo root) with columns:

- `Form Number`
- `Form Title`
- `Form URL`

## Output

`forms_enriched.csv` with columns:

- Original Form Number
- Original Form Title
- Form URL
- Extracted Form Number
- Case Type
- Legal Action
- PDF URLs

Failures are written to `errors.csv`.

## Environment note

Crawl4AI and its dependencies (`lxml`, Playwright) do **not** yet ship
prebuilt wheels for Python 3.14, and building `lxml` from source on Windows
fails without a local `libxml2`. Use **Python 3.11 or 3.12** instead.

Requires [`uv`](https://docs.astral.sh/uv/) for the recommended setup scripts below.

## Quick start (Windows)

One-time setup:

```powershell
.\crawl4ai_forms\setup.ps1
```

Run enrichment later:

```powershell
.\crawl4ai_forms\run.ps1
```

Optional arguments are passed through to `enrich_forms.py`:

```powershell
.\crawl4ai_forms\run.ps1 --output forms_enriched.csv --errors errors.csv
```

## Setup (manual)

```bash
cd crawl4ai_forms
uv venv --python 3.11 .venv
uv pip install --python .venv\Scripts\python.exe -r requirements.txt
.venv\Scripts\python.exe -m playwright install chromium
```

Alternative without `uv`:

```bash
cd crawl4ai_forms
python -m venv .venv
# Windows PowerShell
. .venv\Scripts\Activate.ps1
# macOS/Linux
# source .venv/bin/activate

pip install -r requirements.txt
crawl4ai-setup
# or: python -m playwright install chromium
```

## Run (manual)

```powershell
.\crawl4ai_forms\.venv\Scripts\python.exe enrich_forms.py
```

Optional arguments:

```powershell
.\crawl4ai_forms\.venv\Scripts\python.exe enrich_forms.py --input ../Divorce_Forms_Extract.csv --output forms_enriched.csv --errors errors.csv
```
