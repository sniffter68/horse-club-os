param(
	[Parameter(Mandatory = $true)]
	[string] $Version,
	[string] $OutputDirectory = ''
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) {
	$OutputDirectory = Join-Path $root 'dist'
}
$main = Join-Path $root 'horse-club-os.php'
$mainText = Get-Content -Raw -Encoding UTF8 -LiteralPath $main

$escapedVersion = [regex]::Escape($Version)
if ($mainText -notmatch "(?m)^\s*\* Version:\s+$escapedVersion\s*$" -or $mainText -notmatch "define\(\s*'HCOS_VERSION',\s*'$escapedVersion'\s*\)") {
	throw "Version $Version does not match horse-club-os.php."
}

$output = [IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $output -Force | Out-Null
$destination = Join-Path $output ("horse-club-os-{0}-wordpress.zip" -f $Version)
if (Test-Path -LiteralPath $destination) {
	throw "Release file already exists: $destination"
}

$excludedDirectories = @('.git', '.github', 'dist', 'docs', 'scripts', 'tests')
$excludedFiles = @('.gitignore', '.gitattributes', 'CHANGELOG.md', 'SECURITY.md')
$files = Get-ChildItem -LiteralPath $root -File -Recurse | Where-Object {
	$relative = $_.FullName.Substring($root.Length + 1)
	$first = ($relative -split '[\\/]')[0]
	$excludedDirectories -notcontains $first -and $excludedFiles -notcontains $relative
}

$stream = [IO.File]::Open($destination, [IO.FileMode]::CreateNew, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
try {
	$archive = New-Object IO.Compression.ZipArchive($stream, [IO.Compression.ZipArchiveMode]::Create, $false)
	try {
		foreach ($file in $files) {
			$relative = $file.FullName.Substring($root.Length + 1).Replace('\', '/')
			$entry = $archive.CreateEntry("horse-club-os/$relative", [IO.Compression.CompressionLevel]::Optimal)
			$entryStream = $entry.Open()
			$inputStream = [IO.File]::OpenRead($file.FullName)
			try { $inputStream.CopyTo($entryStream) } finally { $inputStream.Dispose(); $entryStream.Dispose() }
		}
	} finally { $archive.Dispose() }
} finally { $stream.Dispose() }

$check = [IO.Compression.ZipFile]::OpenRead($destination)
try {
	$entries = $check.Entries.FullName
	if ($entries | Where-Object { $_ -match '\\' }) { throw 'Archive contains incompatible backslashes.' }
	if (-not ($entries -contains 'horse-club-os/horse-club-os.php')) { throw 'The main plugin file is missing.' }
	if ($entries | Where-Object { $_ -match '^horse-club-os/(\.git|\.github|scripts|docs|dist)/' }) { throw 'Development files entered the release.' }
} finally { $check.Dispose() }

Get-FileHash -LiteralPath $destination -Algorithm SHA256
