# Run ProSe Core PHPUnit tests (Windows).
param(
	[string]$Suite = '',
	[string]$Filter = '',
	[Parameter(ValueFromRemainingArguments = $true)]
	[string[]]$Remaining
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$phpunit = Join-Path $root 'vendor\bin\phpunit.bat'
if (-not (Test-Path $phpunit)) {
	$phpunit = Join-Path $root 'vendor\bin\phpunit'
}

if (-not (Test-Path $phpunit)) {
	Write-Error "PHPUnit not found. Run: composer install"
}

$args = @('-c', 'phpunit.xml.dist')
if ($Suite) {
	$args += @('--testsuite', $Suite)
}
if ($Filter) {
	$args += @('--filter', $Filter)
}
if ($Remaining) {
	$args += $Remaining
}

& php $phpunit @args
