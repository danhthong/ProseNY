---
name: NY Court Forms Plugin
overview: Complete NY Court Forms Collector plugin re-implementation with OOP architecture, AJAX batch processing, real-time progress tracking, and export functionality.
todos: []
isProject: false
---

# Plan: Complete NY Court Forms Collector Implementation

## Overview
Rewrite the entire plugin from scratch with proper PHP 8+ OOP architecture, singleton bootstrap, namespaced classes, complete admin interface with AJAX batch processing for crawling.

## File Structure
```
ny-court-forms-collector/
├── ny-court-forms-collector.php       # Singleton bootstrap
├── includes/                          # OOP classes (namespaced)
│   ├── class-admin.php                # Admin page + AJAX handlers
│   ├── class-csv.php                  # CSV parsing & storage
│   ├── class-crawler.php              # DOM processing, extraction
│   └── class-export.php               # Streaming export generation
└── assets/
    ├── admin.js                       # AJAX batch polling UI
    └── admin.css                      # Progress bar + log styling
```

## Key Implementation Details

### 1. Main Plugin File (ny-court-forms-collector.php)
- Define constants for paths/URLs
- Autoload classes or require manually
- Instance bootstrap class on `plugins_loaded` hook
- PHP 8+ namespace: `NYCourtFormsCollector`

### 2. Admin Class (`includes/class-admin.php`)
- Add submenu page under Tools → NY Court Forms Collector
- Render complete admin UI with all required controls:
  - CSV upload form
  - Status panel (start/pause/resume/reset buttons)
  - Progress bar + metrics (rows processed/remaining, speed, ETA)
  - Activity log container
- AJAX handlers for all operations with proper nonce verification

### 3. CSV Class (`includes/class-csv.php`)
- Parse uploaded CSV with BOM handling
- Validate required columns exist (Form Number, Form Title, Form URL)
- Store rows in WordPress option (serialized array or transient)
- Return parsed data as associative array

### 4. Crawler Class (`includes/class-crawler.php`)
- Use `wp_remote_get()` with timeout and user-agent
- DOMDocument + DOMXPath parsing for HTML extraction
- Extract: form_number_detail, case_type, legal_action, pdf_urls
- Store results incrementally in options (crawl_results_*)
- Error handling - continue on failures

### 5. Export Class (`includes/class-export.php`)
- Stream output CSV with UTF-8 BOM
- Include all original and extracted fields + errors
- No memory exhaustion for 5000+ rows

### 6. JavaScript (`assets/admin.js`)
- Poll progress endpoint every 2 seconds
- Handle AJAX batch processing (start/pause/resume/stop)
- Update progress bar, speed, ETA calculations
- Append to activity log in real-time

### 7. CSS (`assets/admin.css`)
- Progress bar styling with color-coded states
- Activity log container with timestamp format
- Button layout for control panel