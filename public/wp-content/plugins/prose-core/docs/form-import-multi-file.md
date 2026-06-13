# Multi-File Court Form Import

## Overview

The ProSe **Import Forms** workflow (`Form_Importer`) now downloads **all** pipe-delimited court document URLs from CSV imports—not just the first PDF.

Supported source formats:

- `pdf`
- `doc`
- `docx`
- `wpd`
- `rtf`
- `txt`

## Storage Layout

New imports store court source files under per-form directories:

```
wp-content/uploads/prose/forms/
  ud-12/
    original/
      ud-12.pdf
      ud-12-fillable.pdf
      ud-12.wpd
```

Legacy flat files (e.g. `uploads/prose/forms/ud-1.pdf`) are **not moved automatically** and continue to work.

## Metadata

### New: `prose_source_files` (JSON)

```json
{
  "files": [
    {
      "filename": "ud-12.pdf",
      "extension": "pdf",
      "source_url": "https://webfiles.nycourts.gov/public/2026-05/ud-12.pdf",
      "local_path": "/path/to/uploads/prose/forms/ud-12/original/ud-12.pdf",
      "local_url": "http://example.test/wp-content/uploads/prose/forms/ud-12/original/ud-12.pdf",
      "download_status": "success"
    }
  ]
}
```

`download_status` values:

- `success` — downloaded and saved
- `skipped` — URL or file already present; not re-downloaded
- `failed` — download error
- `unsupported` — extension not in the allowlist

### Legacy fields (unchanged)

These remain populated for backward compatibility:

- `prose_file_name`
- `prose_file_url`
- `prose_source_pdf_url`

The primary PDF is the **first successful `.pdf` entry** in the source file list—the same selection rule used before multi-file support.

## Import Reporting

The Import Forms progress screen reports:

- Forms: created / updated / failed
- Files: URLs processed / downloaded / skipped / failed

## Duplicate Protection

- Duplicate URLs within a CSV row are ignored
- Re-import skips URLs already recorded in `prose_source_files` when the file exists
- Existing files are not overwritten when a different URL targets the same filename

## Path Resolution

`Pdf_Analyzer::resolve_pdf_path()` checks:

1. Legacy flat path: `uploads/prose/forms/{filename}`
2. Per-form path: `uploads/prose/forms/{slug}/original/{filename}`
3. First PDF entry in `prose_source_files`

## Related Commands

See [form-import-migration.md](form-import-migration.md) for optional flat-file migration via WP-CLI.
