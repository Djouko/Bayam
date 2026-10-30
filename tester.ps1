# Lance tout ce qu'il faut pour montrer BAYAM sur un téléphone Android branché en USB (ou sur l'émulateur).
# Usage (PowerShell, depuis n'importe où) :
#   & "F:\Workspace\Freelance\Cameroon E-Translate\inaf\principal\plateforme-locale\tester.ps1"
#   & "...\tester.ps1" -Recompiler      (recompile l'application avant de l'installer, après une modification du code)
# Étapes :
#   1. serveur local (WordPress, WooCommerce, WCFM) : démarré s'il est arrêté, rien à faire s'il tourne déjà ;
#      données de démonstration installées automatiquement si la base est vide (premier lancement, volumes effacés) ;
#   2. application : compilée si l'APK manque (ou avec -Recompiler) ;
#   3. pour chaque appareil branché : renvoi du port 8080, installation (même si l'application a été supprimée du
#      téléphone), lancement ;
#   4. affichage des comptes de démonstration.
param([switch]$Recompiler)
# Erreurs vérifiées une à une (codes de sortie) : avec 'Stop', les messages normaux que docker et adb écrivent
# sur la sortie d'erreur arrêteraient le script sous Windows PowerShell 5.1.
$ErrorActionPreference = 'Continue'
$ici = Split-Path -Parent $MyInvocation.MyCommand.Path
$projet = Join-Path $ici '..\bayam-project'
$apk = Join-Path $projet 'build\app\outputs\flutter-apk'
$adb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'
$flutter = Join-Path $env:USERPROFILE 'fvm\versions\3.44.9\bin\flutter.bat'
$compose = Join-Path $ici 'docker-compose.yml'
$paquet = 'com.inspireui.fluxstore'
$env:MSYS_NO_PATHCONV = '1'

Write-Host '1. Serveur local' -ForegroundColor Green
docker compose -f $compose up -d 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker ne répond pas : lancer Docker Desktop, attendre qu''il soit prêt, puis relancer ce script.' }
docker compose -f $compose run --rm cli wp core is-installed 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host '   Base vide : installation des données de démonstration (10 à 15 minutes, connexion Internet nécessaire)...'
    docker compose -f $compose run --rm cli bash /scripts/installer.sh
    if ($LASTEXITCODE -ne 0) { throw 'L''installation des données a échoué : relancer le script (chaque étape reprend où elle s''est arrêtée).' }
}
$ok = $false
foreach ($i in 1..40) {
    try { if ((Invoke-WebRequest 'http://localhost:8080/shop/' -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop).StatusCode -eq 200) { $ok = $true; break } } catch { Start-Sleep -Seconds 3 }
}
if (-not $ok) { throw 'Le serveur ne répond pas sur http://localhost:8080 : vérifier Docker Desktop.' }
Write-Host '   http://localhost:8080 répond.'

Write-Host '2. Application' -ForegroundColor Green
$apkTelephone = Join-Path $apk 'app-arm64-v8a-release.apk'
if ($Recompiler -or -not (Test-Path $apkTelephone)) {
    Write-Host '   Compilation de la version de diffusion (10 à 20 minutes)...'
    Push-Location $projet
    try { & $flutter build apk --release --split-per-abi --no-pub --target-platform android-arm64,android-x64 }
    finally { Pop-Location }
    if ($LASTEXITCODE -ne 0) { throw 'La compilation a échoué : voir les messages ci-dessus.' }
}
Write-Host "   APK du $((Get-Item $apkTelephone).LastWriteTime.ToString('dd/MM/yyyy HH:mm'))"

Write-Host '3. Appareils Android' -ForegroundColor Green
$appareils = & $adb devices | Select-Object -Skip 1 | Where-Object { $_ -match '\tdevice$' } | ForEach-Object { ($_ -split '\t')[0] }
if (-not $appareils) { throw 'Aucun appareil : brancher le téléphone en USB (débogage USB activé, autoriser cet ordinateur) ou démarrer l''émulateur.' }
foreach ($a in $appareils) {
    $abi = (& $adb -s $a shell getprop ro.product.cpu.abi).Trim()
    $fichier = Join-Path $apk $(if ($abi -like 'x86_64*') { 'app-x86_64-release.apk' } else { 'app-arm64-v8a-release.apk' })
    Write-Host "   $a ($abi) : port 8080, installation, lancement"
    & $adb -s $a reverse tcp:8080 tcp:8080 | Out-Null
    $resultat = & $adb -s $a install -r $fichier 2>&1 | Out-String
    if ($resultat -notmatch 'Success') {
        # Version déjà installée plus récente ou incompatible : on la remplace (ses données locales sont effacées).
        & $adb -s $a uninstall $paquet 2>&1 | Out-Null
        $resultat = & $adb -s $a install $fichier 2>&1 | Out-String
        if ($resultat -notmatch 'Success') { throw "Installation refusée sur $a : $resultat" }
    }
    & $adb -s $a shell monkey -p $paquet -c android.intent.category.LAUNCHER 1 2>$null | Out-Null
}

$motDePasse = (Get-Content (Join-Path $ici '.env') | Where-Object { $_ -like 'WP_VENDOR_PASSWORD=*' }) -replace '^WP_VENDOR_PASSWORD=', ''
Write-Host ''
Write-Host 'Prêt.' -ForegroundColor Green
Write-Host "Comptes de démonstration (même mot de passe pour tous : $motDePasse) :"
Write-Host '   Acheteur : brice (Brice Essomba)'
Write-Host '   Vendeurs : aicha (Mode Aïcha), akwa (Accessoires Akwa), bonamoussadi (Musique Bonamoussadi), mireille@bayam.local (boutique créée depuis l''application)'
Write-Host 'Sur l''ordinateur : site http://localhost:8080/shop/   espace vendeur http://localhost:8080/store-manager/   administration http://localhost:8080/wp-admin/'
