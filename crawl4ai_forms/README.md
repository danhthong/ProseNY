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

## Setup

```bash
cd crawl4ai_forms
python -m venv .venv
# Windows PowerShell
. .venv\Scripts\Activate.ps1
# macOS/Linux
# source .venv/bin/activate

pip install -r requirements.txt
# One-time browser install used by Crawl4AI (Playwright)
crawl4ai-setup
# or, if the above is unavailable:
# python -m playwright install chromium
```

## Run

```bash
python enrich_forms.py
```

Optional arguments:

```bash
python enrich_forms.py --input ../Divorce_Forms_Extract.csv --output forms_enriched.csv --errors errors.csv
```
