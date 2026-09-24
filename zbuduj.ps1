# Buduje simplemadeblinds.mx ze źródeł i przekłada wynik do site/.
#
#   .\zbuduj.ps1
#   git add -A; git commit -m "opis zmiany"; git push
#
# Potem w cPanelu: Git Version Control -> Manage -> Pull or Deploy ->
# Update from Remote, a następnie Deploy HEAD Commit. Oba kroki, w tej kolejności.
#
# Build to trzy kroki: klient, SSR i prerender, który wstawia wyrenderowany HTML
# do szablonu. Bez dwóch ostatnich strona przyjechałaby pusta dla robotów.

$ErrorActionPreference = 'Stop'

# Vite pisze ostrzeżenia na stderr, a PowerShell przy 'Stop' traktuje każdą linię
# stderr z programu zewnętrznego jak błąd i przerywa build. Stąd uruchamianie
# node'a przez tę funkcję: liczy się wyłącznie kod wyjścia.
function Uruchom {
  param([string[]]$Argumenty, [string]$Opis)

  $poprzednie = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  try {
    & node @Argumenty
  } finally {
    $ErrorActionPreference = $poprzednie
  }

  if ($LASTEXITCODE -ne 0) { throw "$Opis zwrócił $LASTEXITCODE" }
}

$zrodla = 'C:\Users\USER\Projects\smb-mx-website'
$site   = Join-Path $PSScriptRoot 'site'

if (-not (Test-Path $zrodla)) {
  throw "Nie widzę repo ze źródłami: $zrodla"
}

Write-Host "==> Pobieram najnowszy main ze źródeł" -ForegroundColor Cyan
Push-Location $zrodla
try {
  git fetch origin
  $za = (git rev-list --count HEAD..origin/main)
  if ($za -ne '0') {
    Write-Host "    lokalne repo jest $za commitów w tyle — robię fast-forward" -ForegroundColor Yellow
    git merge --ff-only origin/main
  } else {
    Write-Host "    już aktualne"
  }
} finally { Pop-Location }

Write-Host "==> Buduję" -ForegroundColor Cyan
Push-Location $zrodla
try {
  $env:NODE_ENV = 'production'

  Uruchom @('.\node_modules\vite\bin\vite.js', 'build') 'vite build'
  # root w vite.config.ts to client/, więc wejście SSR i katalog wyjściowy są
  # podane względem client/.
  Uruchom @('.\node_modules\vite\bin\vite.js', 'build', '--ssr', 'src/entry-server.tsx',
            '--outDir', '../dist/server') 'vite build --ssr'
  Uruchom @('.\script\prerender.mjs') 'prerender'
} finally { Pop-Location }

$dist = Join-Path $zrodla 'dist\public'
foreach ($plik in @('index.html', 'products.html', '404.html', '.htaccess', 'api\leads.php', 'sitemap.xml', 'robots.txt')) {
  if (-not (Test-Path (Join-Path $dist $plik))) {
    throw "Build nie zostawił $plik w $dist"
  }
}

# Strona ma przyjechać z treścią, a nie z pustym kontenerem — po to jest prerender.
$html = Get-Content (Join-Path $dist 'index.html') -Raw -Encoding UTF8
if ($html -match '<div id="root"></div>') {
  throw "index.html ma pusty <div id=""root""> — prerender nie zadziałał"
}
if ($html -notmatch 'src="/assets/') {
  throw "index.html nie ma odnośników zaczynających się od /assets/"
}

Write-Host "==> Przekładam do site/" -ForegroundColor Cyan
if (Test-Path $site) { Remove-Item $site -Recurse -Force }
New-Item -ItemType Directory -Path $site | Out-Null
Copy-Item (Join-Path $dist '*') $site -Recurse -Force
# Copy-Item z maską pomija pliki zaczynające się od kropki
Copy-Item (Join-Path $dist '.htaccess') $site -Force

if (-not (Test-Path (Join-Path $site '.htaccess'))) {
  throw "Brakuje .htaccess w site/ — bez niego nie ma HTTPS, przekierowań, 404 ani formularza"
}

$ile = (Get-ChildItem $site -Recurse -File -Force).Count
$mb  = [math]::Round(((Get-ChildItem $site -Recurse -File -Force | Measure-Object Length -Sum).Sum / 1MB), 1)
Write-Host "==> Gotowe: $ile plików, $mb MB w site/" -ForegroundColor Green
Write-Host "    Teraz: git add -A; git commit -m '...'; git push" -ForegroundColor Green
