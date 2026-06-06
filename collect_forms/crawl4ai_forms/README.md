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
3. Resolves every extracted PDF URL (which are `…/media/<id>` redirects) to its
   final location and filename, up to 20 concurrently (aiohttp HEAD → GET, with
   a `curl` fallback — see note below).
4. Writes an intermediate checkpoint every 50 rows.
5. Exports `forms_enriched.csv` and logs failures to `errors.csv`.

### PDF redirect resolution

NY Courts PDF links look like `https://nycourts.gov/media/14576` — these are
redirects, not the final PDF. The crawler resolves each to its final URL and
filename, e.g.:

```
https://nycourts.gov/media/14461
  -> https://webfiles.nycourts.gov/public/2025-12/doh-2168.pdf  (doh-2168.pdf)
```

> **Note on Cloudflare:** NY Courts sits behind Cloudflare, which rejects plain
> HTTP clients (including aiohttp) with HTTP 403 based on TLS fingerprinting.
> The resolver tries aiohttp first (HEAD then GET) as required, and falls back
> to `curl` (bundled with Windows 10+/macOS/most Linux) which is allowed
> through. If `curl` is unavailable and aiohttp is blocked, the original URL is
> kept and the filename is left blank — the crawler never raises.

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
- Original PDF URLs (pipe-joined `…/media/<id>` redirect URLs)
- Resolved PDF URLs (pipe-joined final PDF URLs)
- PDF Filenames (pipe-joined, from `Content-Disposition` or the final URL path)
- Local PDF Path (only present when `--download-pdfs` is used)

Failures are written to `errors.csv`.

## Downloading PDFs (optional)

Disabled by default. Pass `--download-pdfs` to download each resolved PDF into
`download_pdfs/` (created automatically), saving with the resolved filename and
skipping files that already exist. The local path is recorded in the
`Local PDF Path` column.

```powershell
.\run.ps1 --download-pdfs
# custom directory:
.\run.ps1 --download-pdfs --download-dir my_pdfs
```

## Environment note

Crawl4AI and its dependencies (`lxml`, Playwright) do **not** yet ship
prebuilt wheels for Python 3.14, and building `lxml` from source on Windows
fails without a local `libxml2`. Use **Python 3.11 or 3.12** instead.

Requires [`uv`](https://docs.astral.sh/uv/) for the recommended setup scripts below.

## Part of the forms pipeline

This enricher is **stage 2** of the `collect_forms` pipeline. The environment
(`collect_forms/.venv`) and setup are managed by the parent project — see
`../README.md`. Set it up once with:

```powershell
.\collect_forms\setup.ps1
```

Run the full collect → enrich pipeline with:

```powershell
.\collect_forms\run.ps1
```

## Running this stage on its own

From the `collect_forms` folder, using the shared venv:

```powershell
.\.venv\Scripts\python.exe crawl4ai_forms\enrich_forms.py
.\.venv\Scripts\python.exe crawl4ai_forms\enrich_forms.py --output forms_enriched.csv --errors errors.csv
```

## Setup (manual, standalone)

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
