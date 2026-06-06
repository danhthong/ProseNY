# Run the full collect -> enrich pipeline using the project venv.
# Run from anywhere:
#   .\collect_forms\run.ps1
#   .\collect_forms\run.ps1 "https://www.nycourts.gov/forms?...your filter..."
#   .\collect_forms\run.ps1 --download-pdfs

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$python = Join-Path $PSScriptRoot ".venv\Scripts\python.exe"
if (-not (Test-Path $python)) {
    Write-Error "Virtual environment not found. Run .\setup.ps1 first."
}

& $python run_pipeline.py @args
