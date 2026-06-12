# PDF Template Resolution Audit

**Generated:** 2026-06-12 04:58:30 UTC
**Source of truth:** `prose_form` -> `prose_file_url` (+ `prose_pdf_fillable`, `prose_pdf_field_count`, `prose_pdf_fields_json`)
**Mode:** Audit only — no rendering logic or mappings modified.

## Summary

- Forms audited: **10**
- Resolved to a `prose_file_url`: **6 / 10**
- Official PDF is a fillable AcroForm (can be filled): **0 / 10**
- Renderer currently selected: **builtin** for all (registry never consults `prose_file_url`)

## Audit table

| Form Code | prose_form | prose_file_url | File Exists | PDF Type | Field Count | Renderer | Can Fill | Fallback Reason |
|-----------|-----------|----------------|-------------|----------|-------------|----------|----------|-----------------|
| UD-1 | yes | `ud-1.pdf` | yes | Flat | 0 | builtin | no | official PDF has no AcroForm fields (flat document) |
| UD-2 | yes | `ud-2.pdf` | yes | Flat | 0 | builtin | no | official PDF has no AcroForm fields (flat document) |
| UD-3 | yes | `ud-3.pdf` | yes | Flat | 0 | builtin | no | official PDF has no AcroForm fields (flat document) |
| UD-4 | yes | `ud-4.pdf` | yes | Flat | 0 | builtin | no | official PDF has no AcroForm fields (flat document) |
| UD-6 | yes | `ud-6.pdf` | yes | Flat | 0 | builtin | no | official PDF has no AcroForm fields (flat document) |
| UD-7 | yes | `ud-7.pdf` | yes | Flat | 0 | builtin | no | official PDF has no AcroForm fields (flat document) |
| FC-1 | no | — | no | — | 0 | builtin | no | prose_form post not found for form code |
| FC-2 | no | — | no | — | 0 | builtin | no | prose_form post not found for form code |
| FC-3 | no | — | no | — | 0 | builtin | no | prose_form post not found for form code |
| FC-7 | no | — | no | — | 0 | builtin | no | prose_form post not found for form code |

## Field registry (AcroForm forms only)

_No audited form resolved to a fillable AcroForm PDF, so the field registry is empty. None of these forms can currently be field-filled from the official template._
## Conclusion

- The renderer falls back to `builtin` because `Pdf_Template_Registry` resolves templates from a local `templates/{CODE}.pdf` directory and never reads `prose_file_url`.
- Even with that wiring fixed, the audited official PDFs that resolve are **flat (0 AcroForm fields)**, so they cannot be field-filled as-is.
- No PDF fill toolchain (`pdftk`) is installed in this environment.

