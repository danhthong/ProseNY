# NY Courts Knowledge Corpus

Static markdown knowledge extracted from [nycourts.gov](https://www.nycourts.gov/) for form explanations and intake chat reference context.

## Layout

- `forms/` — one file per court form (seeded from `docs/forms/**/*.json` `official_url`)
- `topics/` — CourtHelp procedural guides (family, divorce, safety, routing)
- `manifest.json` — crawl run metadata
- `errors.csv` — failed URLs from the last crawl

## Refresh

From the repo root, using the `collect_forms` Python environment:

```powershell
cd collect_forms
.\.venv\Scripts\python.exe crawl_knowledge.py
.\.venv\Scripts\python.exe crawl_knowledge.py --forms-only
.\.venv\Scripts\python.exe crawl_knowledge.py --topics-only
```

After crawling, sync empty form summaries into WordPress:

```bash
wp prose forms sync-knowledge-summaries
```

## Consumption

Loaded by `ProSe\Core\Search\Knowledge_Article_Loader` (filter: `prose_court_knowledge_dir`) and injected into intake chat via `Knowledge_Context_Provider`.
