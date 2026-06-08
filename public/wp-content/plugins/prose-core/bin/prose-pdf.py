#!/usr/bin/env python3
"""
ProSe PDF sidecar — extracts text and AcroForm fields from court PDFs.

Usage:
  python3 prose-pdf.py --check
  python3 prose-pdf.py /path/to/form.pdf [max_pages]

Output: JSON { "text": "...", "fields": [ { "name": "...", "type": "text" } ] }
"""

from __future__ import annotations

import json
import sys
from pathlib import Path


def check_dependencies() -> bool:
    """Return True if at least one PDF library is importable."""
    for module in ("pdfplumber", "fitz", "pypdf"):
        try:
            __import__(module)
            return True
        except ImportError:
            continue
    return False


def extract_text_pdfplumber(path: Path, max_pages: int) -> str:
    import pdfplumber  # type: ignore

    parts: list[str] = []
    with pdfplumber.open(str(path)) as pdf:
        for page in pdf.pages[:max_pages]:
            text = page.extract_text() or ""
            if text.strip():
                parts.append(text.strip())
    return "\n".join(parts)


def extract_text_pymupdf(path: Path, max_pages: int) -> str:
    import fitz  # type: ignore

    parts: list[str] = []
    doc = fitz.open(str(path))
    try:
        for i in range(min(max_pages, doc.page_count)):
            parts.append(doc.load_page(i).get_text("text").strip())
    finally:
        doc.close()
    return "\n".join(p for p in parts if p)


def extract_text_pypdf(path: Path, max_pages: int) -> str:
    from pypdf import PdfReader  # type: ignore

    reader = PdfReader(str(path))
    parts: list[str] = []
    for i, page in enumerate(reader.pages[:max_pages]):
        text = page.extract_text() or ""
        if text.strip():
            parts.append(text.strip())
    return "\n".join(parts)


def extract_text(path: Path, max_pages: int) -> str:
    for fn in (extract_text_pdfplumber, extract_text_pymupdf, extract_text_pypdf):
        try:
            return fn(path, max_pages)
        except ImportError:
            continue
        except Exception:
            continue
    return ""


def field_type_from_ft(ft) -> str:
    if ft is None:
        return "text"
    ft_str = str(ft).lower()
    if "btn" in ft_str:
        return "checkbox"
    if "ch" in ft_str:
        return "choice"
    if "sig" in ft_str:
        return "signature"
    return "text"


def extract_fields_pymupdf(path: Path) -> list[dict]:
    import fitz  # type: ignore

    fields: list[dict] = []
    doc = fitz.open(str(path))
    try:
        for page in doc:
            for widget in page.widgets() or []:
                name = widget.field_name
                if not name:
                    continue
                fields.append(
                    {
                        "name": name,
                        "type": field_type_from_ft(widget.field_type),
                    }
                )
    finally:
        doc.close()
    return dedupe_fields(fields)


def extract_fields_pypdf(path: Path) -> list[dict]:
    from pypdf import PdfReader  # type: ignore

    reader = PdfReader(str(path))
    fields: list[dict] = []
    if reader.get_fields():
        for name, field in reader.get_fields().items():
            ft = getattr(field, "field_type", None) or field.get("/FT")
            fields.append({"name": name, "type": field_type_from_ft(ft)})
    return dedupe_fields(fields)


def extract_fields(path: Path) -> list[dict]:
    for fn in (extract_fields_pymupdf, extract_fields_pypdf):
        try:
            result = fn(path)
            if result:
                return result
        except ImportError:
            continue
        except Exception:
            continue
    return []


def dedupe_fields(fields: list[dict]) -> list[dict]:
    seen: set[str] = set()
    unique: list[dict] = []
    for field in fields:
        name = field.get("name", "")
        if not name or name in seen:
            continue
        seen.add(name)
        unique.append(field)
    return unique


def analyze(path: Path, max_pages: int) -> dict:
    return {
        "text": extract_text(path, max_pages),
        "fields": extract_fields(path),
    }


def main() -> int:
    if len(sys.argv) >= 2 and sys.argv[1] == "--check":
        print(json.dumps({"ok": check_dependencies()}))
        return 0 if check_dependencies() else 1

    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: prose-pdf.py <pdf> [max_pages]"}))
        return 1

    pdf_path = Path(sys.argv[1])
    max_pages = int(sys.argv[2]) if len(sys.argv) > 2 else 3

    if not pdf_path.is_file():
        print(json.dumps({"error": "File not found", "path": str(pdf_path)}))
        return 1

    result = analyze(pdf_path, max(1, max_pages))
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
