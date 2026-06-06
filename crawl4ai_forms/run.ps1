# Run the forms enricher using the project venv.
# Run from anywhere:  .\crawl4ai_forms\run.ps1

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$python = Join-Path $PSScriptRoot ".venv\Scripts\python.exe"
if (-not (Test-Path $python)) {
    Write-Error "Virtual environment not found. Run .\setup.ps1 first."
}

& $python enrich_forms.py @args
