$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$distRoot = Join-Path $projectRoot 'dist'
$releaseSlug = 'ezimport'
$releaseVersion = '1.0.0'
$stageRoot = Join-Path $distRoot ('stage-' + [guid]::NewGuid().ToString('N'))
$pluginRoot = Join-Path $stageRoot $releaseSlug
$zipPath = Join-Path $distRoot "$releaseSlug-$releaseVersion.zip"

New-Item -ItemType Directory -Path $pluginRoot -Force | Out-Null

try {
    Copy-Item -LiteralPath (Join-Path $projectRoot 'wpezimport.php') -Destination (Join-Path $pluginRoot "$releaseSlug.php")
    foreach ($file in @('readme.txt', 'README.md', 'composer.json', 'composer.lock')) {
        Copy-Item -LiteralPath (Join-Path $projectRoot $file) -Destination $pluginRoot
    }
    foreach ($directory in @('admin', 'assets', 'src')) {
        Copy-Item -LiteralPath (Join-Path $projectRoot $directory) -Destination $pluginRoot -Recurse
    }

    Push-Location $pluginRoot
    try {
        & composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
        if ($LASTEXITCODE -ne 0) { throw 'Production Composer install failed.' }
        & composer check-platform-reqs --no-dev
        if ($LASTEXITCODE -ne 0) { throw 'Production platform requirements failed.' }
    }
    finally {
        Pop-Location
    }

    if (-not (Test-Path -LiteralPath (Join-Path $pluginRoot 'vendor/autoload.php'))) {
        throw 'The release is missing vendor/autoload.php.'
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
    [System.IO.Compression.ZipFile]::CreateFromDirectory($stageRoot, $zipPath)

    $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $entries = @($zip.Entries | ForEach-Object FullName)
        foreach ($required in @("$releaseSlug/$releaseSlug.php", "$releaseSlug/readme.txt", "$releaseSlug/vendor/autoload.php")) {
            if ($required -notin $entries) { throw "Release ZIP is missing $required." }
        }
        if (@($entries | Where-Object { $_ -match "^$releaseSlug/(tests|vendor/phpunit|vendor/10up)/" }).Count -gt 0) {
            throw 'Development files were included in the release ZIP.'
        }
        Write-Output "Created $zipPath ($($zip.Entries.Count) entries)"
    }
    finally {
        $zip.Dispose()
    }
}
finally {
    $resolvedDist = [System.IO.Path]::GetFullPath($distRoot)
    $resolvedStage = [System.IO.Path]::GetFullPath($stageRoot)
    if ($resolvedStage.StartsWith($resolvedDist + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase) -and
        (Test-Path -LiteralPath $resolvedStage)) {
        Remove-Item -LiteralPath $resolvedStage -Recurse -Force
    }
}
