# Form Import Migration Notes

## When to migrate

Migration is **optional**. Existing forms with flat files under `uploads/prose/forms/` continue to work without changes.

Run migration only if you want to normalize storage into per-form `original/` subdirectories and populate `prose_source_files` metadata for legacy imports.

## WP-CLI command

```bash
# Dry run (default) — reports what would happen
wp prose forms migrate-source-files

# Execute migration
wp prose forms migrate-source-files --execute
```

## What the command does

For each `prose_form` post with a non-empty `prose_file_name`:

1. Locates the file in the legacy flat directory
2. Copies it to `uploads/prose/forms/{form-slug}/original/{filename}`
3. Writes a `prose_source_files` entry with `download_status: success`
4. **Does not delete** the original flat file

## Rollback

Because flat files are preserved, you can delete the copied subdirectories and `prose_source_files` meta if needed. Legacy `prose_file_name` / `prose_file_url` fields are unchanged.

## Database changes

No custom tables are modified. Migration only adds/updates `prose_source_files` post meta (additive).
