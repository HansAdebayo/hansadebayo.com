<?php
// Réception des précommandes ZB Dock (sans paiement).
// 1) Enregistre chaque demande dans un CSV HORS du dossier web (non accessible en ligne).
// 2) Envoie un e-mail de notification.

declare(strict_types=1);

const DESTINATAIRE = 'contact.hansadebayo@gmail.com';
const COULEURS = ['Sable', 'Blanc', 'Noir', 'Orange', 'Noyer', 'Lavande', 'Bleu', 'Sauge'];

$veutJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function repondre(bool $ok, string $erreur, bool $json, int $code = 200): never {
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'erreur' => $erreur], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $msg = $ok ? 'Merci ! Votre ZB Dock est réservé. Je vous recontacte très vite.' : htmlspecialchars($erreur);
        echo "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'>"
           . "<link rel=stylesheet href='../styles.css'><main><p>$msg</p><p><a href='./'>Retour</a></p></main>";
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(false, 'Méthode non autorisée', $veutJson, 405);
}

// Piège à robots : un humain ne remplit pas ce champ
if (!empty($_POST['site'])) {
    repondre(true, '', $veutJson);
}

function champ(string $cle, int $max): string {
    $v = trim((string)($_POST[$cle] ?? ''));
    $v = preg_replace('/[\r\n\t]+/', ' ', $v);           // pas de retours à la ligne (anti injection d'en-têtes)
    return mb_substr($v, 0, $max);
}

$nom       = champ('nom', 80);
$email     = champ('email', 120);
$telephone = champ('telephone', 30);
$iphone    = champ('iphone', 60);
$coque     = champ('coque', 5);
$couleur   = champ('couleur', 20);
$quantite  = (int)($_POST['quantite'] ?? 1);
$message   = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 600);
$consent   = ($_POST['consentement'] ?? '') === 'oui';

if ($nom === '')                                    repondre(false, 'Indiquez votre nom', $veutJson, 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))     repondre(false, 'E-mail invalide', $veutJson, 422);
if ($iphone === '')                                 repondre(false, 'Choisissez votre iPhone', $veutJson, 422);
if (!in_array($coque, ['Oui', 'Non'], true))        repondre(false, 'Précisez si vous utilisez une coque', $veutJson, 422);
if (!in_array($couleur, COULEURS, true))            repondre(false, 'Couleur inconnue', $veutJson, 422);
if ($quantite < 1 || $quantite > 10)                repondre(false, 'Quantité entre 1 et 10', $veutJson, 422);
if (!$consent)                                      repondre(false, 'Merci d\'accepter d\'être recontacté', $veutJson, 422);

// Dossier de stockage hors web : à côté du dossier www/
$racineWeb = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/');
$dossier   = dirname($racineWeb) . '/precommandes';
if (!is_dir($dossier)) {
    @mkdir($dossier, 0700, true);
}
$fichier = $dossier . '/zbdock.csv';

// Anti-abus simple : 5 envois max par IP et par heure
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'zbdock');
$limite = $dossier . '/.limite.json';
$compteurs = is_file($limite) ? (json_decode((string)file_get_contents($limite), true) ?: []) : [];
$heure = date('YmdH');
$cle = $heure . ':' . $ipHash;
$compteurs = array_filter($compteurs, fn($k) => str_starts_with($k, $heure), ARRAY_FILTER_USE_KEY);
if (($compteurs[$cle] ?? 0) >= 5) {
    repondre(false, 'Trop de demandes, réessayez plus tard', $veutJson, 429);
}
$compteurs[$cle] = ($compteurs[$cle] ?? 0) + 1;
@file_put_contents($limite, json_encode($compteurs), LOCK_EX);

// Neutralise les formules Excel/Sheets dans le CSV
$csv = fn(string $v) => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;

$nouveau = !is_file($fichier);
$f = @fopen($fichier, 'a');
if ($f === false) {
    repondre(false, 'Erreur serveur', $veutJson, 500);
}
flock($f, LOCK_EX);
if ($nouveau) {
    fputcsv($f, ['date', 'nom', 'email', 'telephone', 'iphone', 'coque', 'couleur', 'quantite', 'message']);
}
fputcsv($f, array_map($csv, [date('Y-m-d H:i'), $nom, $email, $telephone, $iphone, $coque, $couleur, (string)$quantite, str_replace(["\r", "\n"], ' ', $message)]));
flock($f, LOCK_UN);
fclose($f);

// E-mail de notification
$hote = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'hansadebayo.fr'));
$hote = preg_replace('/[^a-z0-9.\-]/i', '', $hote);
$sujet = '=?UTF-8?B?' . base64_encode("Précommande ZB Dock : $nom ($couleur x$quantite)") . '?=';
$corps = "Nouvelle réservation ZB Dock\n\n"
       . "Nom : $nom\nE-mail : $email\nTéléphone : $telephone\n"
       . "iPhone : $iphone (coque : $coque)\nCouleur : $couleur\nQuantité : $quantite\n\n"
       . "Message :\n$message\n";
$entetes = "From: ZB Dock <no-reply@$hote>\r\n"
         . "Reply-To: $email\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";
@mail(DESTINATAIRE, $sujet, $corps, $entetes);

repondre(true, '', $veutJson);
