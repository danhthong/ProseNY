# PDF Template Audit — Why Official Court PDFs Are Not Used

**Date:** 2026-06-12
**Scope:** UD-1, UD-2, UD-3, UD-4, UD-6, UD-7, FC-1, FC-2, FC-3, FC-7
**Source of truth:** `prose_form` post meta `prose_file_url` (+ `prose_pdf_fillable`, `prose_pdf_field_count`, `prose_pdf_analyzed_at`)
**Mode:** Audit only — no code modified.

---

## Executive summary

The Court Filing Packet Engine renders **every** form with the `builtin` text
renderer instead of the official court PDF. There are **three independent root
causes**, any one of which alone is sufficient to force the fallback:

1. **The renderer never reads `prose_file_url`.** `Pdf_Template_Registry`
   resolves templates from a hard-coded local directory
   (`modules/forms/documents/pdf/templates/{CODE}.pdf`), not from the
   `prose_form` catalog meta. That directory **does not exist**, so every form
   resolves to `renderer_type = builtin`.
2. **The official court PDFs are flat (non-fillable).** For UD-1…UD-7 a real PDF
   exists at `prose_file_url`, is reachable and opens fine, but contains **zero
   AcroForm fields** (confirmed by the stored analysis *and* a deep re-scan of
   decompressed object streams). There is nothing to fill.
3. **No PDF-fill toolchain is installed.** `pdftk` is absent from `PATH` and
   FPDI has no writer back-end (no FPDF/TCPDF), so even a fillable AcroForm
   could not be populated in this environment.

Additionally, **FC-1/FC-2/FC-3/FC-7 have no `prose_file_url` keyed to the engine
code** at all (no `fc-*.pdf` exists; the engine's family-court codes are not
linked to any catalog post).

---

## Audit table

| Form Code | PDF URL | File Exists | AcroForm Fields | Field Count | Renderer Used | Fallback Reason |
|-----------|---------|-------------|-----------------|-------------|---------------|-----------------|
| UD-1 | `…/uploads/prose/forms/ud-1.pdf` (post 468) | Yes (HTTP 200, PDF 1.7) | No | 0 | builtin | Registry ignores `prose_file_url`; no `templates/UD-1.pdf`; PDF is flat (0 fields); no `pdftk` |
| UD-2 | `…/uploads/prose/forms/ud-2.pdf` (post 486) | Yes (HTTP 200, PDF 1.7) | No | 0 | builtin | Registry ignores `prose_file_url`; no `templates/UD-2.pdf`; PDF is flat (0 fields); no `pdftk` |
| UD-3 | `…/uploads/prose/forms/ud-3.pdf` (post 62) | Yes (HTTP 200, PDF 1.7) | No | 0 | builtin | Registry ignores `prose_file_url`; no `templates/UD-3.pdf`; PDF is flat (0 fields); no `pdftk` |
| UD-4 | `…/uploads/prose/forms/ud-4.pdf` (post 476) | Yes (HTTP 200, PDF 1.7) | No | 0 | builtin | Registry ignores `prose_file_url`; no `templates/UD-4.pdf`; PDF is flat (0 fields); no `pdftk`; **title drift** (catalog title ≠ engine title) |
| UD-6 | `…/uploads/prose/forms/ud-6.pdf` (post 475) | Yes (HTTP 200, PDF 1.7) | No | 0 | builtin | Registry ignores `prose_file_url`; no `templates/UD-6.pdf`; PDF is flat (0 fields); no `pdftk`; **title drift** |
| UD-7 | `…/uploads/prose/forms/ud-7.pdf` (post 56) | Yes (HTTP 200, PDF 1.7) | No | 0 | builtin | Registry ignores `prose_file_url`; no `templates/UD-7.pdf`; PDF is flat (0 fields); no `pdftk`; **title drift** |
| FC-1 | — (no `fc-1.pdf`; code not linked) | No | N/A | — | builtin | No `prose_file_url` resolvable for engine code; registry ignores meta; no `pdftk` |
| FC-2 | — (no `fc-2.pdf`; code not linked) | No | N/A | — | builtin | No `prose_file_url` resolvable for engine code; fillable equivalent exists but unlinked (see notes); registry ignores meta |
| FC-3 | — (no `fc-3.pdf`; code not linked) | No | N/A | — | builtin | No `prose_file_url` resolvable for engine code; registry ignores meta |
| FC-7 | — (no `fc-7.pdf`; code not linked) | No | N/A | — | builtin | No `prose_file_url` resolvable for engine code; **fillable official AcroForm exists but unlinked** (`ucs-fc8-2-familyoffensepetition-fillable.pdf`, 199 fields); registry ignores meta |

> Full URL prefix for all UD rows: `http://proseny.test/wp-content/uploads/prose/forms/`.

---

## Per-check results

### 1. `prose_file_url` exists
- **UD-1…UD-7:** Yes. Stored on the matching `prose_form` post (code-named files `ud-1.pdf` … `ud-7.pdf`).
- **FC-1/FC-2/FC-3/FC-7:** No `fc-*.pdf` exists, and the engine's FC codes are not linked to any catalog post (`prose_form_code` meta is empty across the catalog).
- Catalog coverage overall: **489 of 490** `prose_form` posts have a non-empty `prose_file_url`.

### 2. URL is reachable
- `ud-1.pdf` returns **HTTP 200**, `content-type: application/pdf` via the Local site (`proseny.test`). The files also exist on disk under `wp-content/uploads/prose/forms/` (534 PDFs present).

### 3. PDF can be downloaded
- Yes for UD-1…UD-7 (present on disk, non-zero sizes: 107 KB–388 KB).

### 4. PDF can be opened
- Yes. All UD files begin with `%PDF-1.7` and parse correctly.

### 5. PDF contains AcroForm fields
- **No** for every UD form. The stored analysis (`prose_pdf_analyzed_at = 2026-06-10`) recorded `prose_pdf_fillable` unset and `prose_pdf_field_count = 0`. An independent deep re-scan (decompressing all `stream`/`endstream` object streams and searching for `/AcroForm` and `/FT /Tx|Btn|Ch|Sig`) confirmed **0 AcroForm fields**. These are flat / print-only PDFs.

### 6. Field count
- UD-1…UD-7: **0**.
- Catalog-wide: only **25 of 489** forms are fillable AcroForms; **464 have 0 fields**.

### 7. Renderer selected
- **builtin** for all 10 forms (the registry returns `renderer_type = builtin` whenever the local template file is missing — which is always, since the `templates/` directory does not exist).

### 8. Fallback reason
- See the table. The *first* trigger encountered is always **"registry does not consult `prose_file_url` and the local `templates/{CODE}.pdf` is absent."** Even if that were fixed, UD forms would still fall back because they are flat (0 fields), and the environment lacks `pdftk`.

---

## Root-cause analysis (code path)

**`Pdf_Template_Registry::descriptor()`** decides the renderer purely from a local file:

```157:171:app/public/wp-content/plugins/prose-core/modules/forms/documents/pdf/class-pdf-template-registry.php
	private function descriptor( string $form_code, string $version ): array {
		$path          = '' === $this->template_dir ? '' : $this->template_dir . $form_code . '.pdf';
		$renderer_type = self::RENDERER_BUILTIN;

		if ( '' !== $path && is_readable( $path ) ) {
			$renderer_type = self::RENDERER_ACROFORM;
		}

		return array(
			'form_code'        => $form_code,
			'template_version' => $version,
			'template_path'    => $path,
			'renderer_type'    => $renderer_type,
		);
	}
```

- `template_dir` defaults to `PROSE_CORE_PATH . 'modules/forms/documents/pdf/templates/'` — **this directory does not exist**, so `is_readable()` is always false → `builtin`.
- `prose_file_url` (the source of truth) is **never queried** anywhere in the PDF module.

**`Court_Pdf_Fill_Service::fill()`** then gates the AcroForm path on three conditions, all of which currently fail:

```text
$use_acroform = renderer_type === 'acroform'           // false (registry returns builtin)
    && template_path !== ''                            // path is a non-existent local file
    && is_readable( template_path )                    // false
    && $this->is_acroform_available();                 // false (no pdftk binary)
```

---

## Secondary findings

- **Flat official PDFs.** The NY uncontested-divorce UD packet PDFs are print-only; they have no form fields. Field-filling is impossible without an overlay/coordinate-based fill strategy or fillable replacements.
- **Code ↔ title ↔ file drift.** The code-named files do not match the engine's `Field_Catalog` titles:
  - `ud-3.pdf` → catalog title *"Affirmation of Service"* vs engine *"Affidavit of Plaintiff"*.
  - `ud-4.pdf` → catalog title *"Sworn Statement of Removal of Barriers…"* vs engine *"Affidavit of Service"*.
  - `ud-6.pdf` → catalog title *"Sworn Affirmation of Plaintiff"* vs engine *"Affidavit of Regularity"*.
  - `ud-7.pdf` → catalog title *"Affirmation of Defendant"* vs engine *"Findings of Fact / Judgment of Divorce"*.
- **Orphaned fillable family-court template.** A fillable official AcroForm for the FC-7 workflow **does exist** in the catalog — *"Petition (Family Offense)"* → `ucs-fc8-2-familyoffensepetition-fillable.pdf` with **199 fields** — but it is keyed by descriptive title only and is not linked to the engine code `FC-7`. A related *"Uniform Support Petition"* (`uifsa-4-…`, 57 fields) exists for the FC-2 workflow. The engine cannot reach either because there is no code→post resolution and the registry ignores `prose_file_url`.
- **No engine-code linkage in the catalog.** `prose_form_code` meta is empty for all 490 posts, so there is currently no deterministic way to map an engine code (UD-1, FC-7, …) to its `prose_form` post / `prose_file_url`.

---

## Why the fallback is *correct* today (not a regression)

Given flat UD PDFs, no `pdftk`, no template directory, and no code→URL linkage,
the builtin renderer is the only path that can produce a valid, populated
output. The fallback is working as designed; the gap is in **data linkage and
toolchain**, not in the rendering fallback.

---

## Remediation options (for a future change — not applied here)

1. **Link engine codes to catalog posts.** Populate `prose_form_code` meta on
   the relevant `prose_form` posts (or maintain an explicit code→`post_id` map)
   so `prose_file_url` can be resolved per form code.
2. **Make the registry data-driven.** Have `Pdf_Template_Registry` resolve
   `template_path` from `prose_file_url` (downloading/caching the official PDF)
   instead of a non-existent local `templates/` directory.
3. **Adopt the fillable equivalents.** Map FC-7 → `ucs-fc8-2-familyoffensepetition-fillable.pdf`
   (199 fields) and FC-2 → `uifsa-4-uniform-support-petition.pdf` (57 fields);
   source fillable AcroForm versions for the UD matrimonial forms (current UD
   PDFs have 0 fields).
4. **Provide a fill toolchain.** Install `pdftk` (already wired via
   `mikehaertl/php-pdftk`) or add an FPDF/TCPDF back-end for FPDI so AcroForm
   fill/merge can run.
5. **Build a field map** from `prose_pdf_fields_json` for each official PDF so
   canonical fields map to real AcroForm field names.

---

## Evidence appendix

- DB: `wp_posts` (`post_type = prose_form`, 490 rows) + `wp_postmeta`
  (`prose_file_url`, `prose_pdf_fillable`, `prose_pdf_field_count`,
  `prose_pdf_analyzed_at`), queried via the Local MySQL socket.
- Disk: `app/public/wp-content/uploads/prose/forms/` (534 PDFs).
- HTTP: `GET/HEAD http://proseny.test/wp-content/uploads/prose/forms/ud-1.pdf` → 200, `application/pdf`.
- AcroForm deep scan: decompressed object streams searched for `/AcroForm` and `/FT /(Tx|Btn|Ch|Sig)` → 0 matches for UD-1…UD-7.
- Toolchain: `pdftk` not on `PATH`; FPDI present but without FPDF/TCPDF writer.
- Code: `class-pdf-template-registry.php` (`descriptor()`), `class-court-pdf-fill-service.php` (`fill()` / `is_acroform_available()`).
```
