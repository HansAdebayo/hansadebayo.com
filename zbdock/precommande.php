<?php
// Réception des précommandes ZB Dock (sans paiement).
// - Enregistre chaque demande dans un CSV HORS du dossier web (jamais accessible en ligne).
// - Envoie un e-mail de notification.
// - Protections anti-spam : champ piège, délai minimum, provenance, limite par IP,
//   doublons, plafond journalier d'e-mails.
// Compatible PHP 7.4 et 8.x.

ini_set('display_errors', '0');          // ne jamais afficher d'erreur (chemins, versions) au visiteur
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const DESTINATAIRE   = 'contact.hansadebayo@gmail.com';
const COULEURS       = ['Sable', 'Blanc', 'Noir', 'Orange', 'Noyer', 'Lavande', 'Bleu', 'Sauge'];
const DELAI_MIN_S    = 3;     // un humain met plus de 3 s à remplir le formulaire
const MAX_PAR_IP_H   = 5;     // envois max par IP et par heure
const MAX_MAILS_JOUR = 50;    // au-delà : on enregistre mais on n'envoie plus d'e-mail

$veutJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function repondre(bool $ok, string $erreur, bool $json, int $code = 200): void {
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'erreur' => $erreur], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $msg = $ok ? 'Merci ! Votre ZB Dock est réservé. On vous recontacte très vite.' : htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'>"
           . "<link rel=stylesheet href='../styles.css'><main><p>$msg</p><p><a href='./'>Retour</a></p></main>";
    }
    exit;
}
// Réponse « faux succès » pour les robots : ils ne savent pas qu'ils ont été bloqués
function ignorer(bool $json): void { repondre(true, '', $json); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    repondre(false, 'Méthode non autorisée', $veutJson, 405);
}

// 1) Provenance : uniquement depuis une page de ce site
$hoteSite = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$origine  = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if ($origine !== '') {
    $hoteOrigine = strtolower((string)parse_url($origine, PHP_URL_HOST));
    if ($hoteOrigine === '' || $hoteOrigine !== $hoteSite) {
        repondre(false, 'Requête refusée', $veutJson, 403);
    }
}
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'cross-site') {
    repondre(false, 'Requête refusée', $veutJson, 403);
}

// 2) Champ piège : un humain ne le remplit jamais
if (!empty($_POST['site'])) {
    ignorer($veutJson);
}

// 3) Délai minimum passé sur le formulaire (en ms, mesuré par le JavaScript de la page)
$ecoule = (int)($_POST['t'] ?? 0);
if ($ecoule < DELAI_MIN_S * 1000) {
    ignorer($veutJson);
}

function champ(string $cle, int $max): string {
    $v = trim((string)($_POST[$cle] ?? ''));
    $v = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);   // pas de retours à la ligne ni caractères de contrôle
    return mb_substr($v, 0, $max, 'UTF-8');
}

$nom       = champ('nom', 80);
$email     = champ('email', 120);
$telephone = champ('telephone', 30);
$iphone    = champ('iphone', 60);
$coque     = champ('coque', 5);
$couleur   = champ('couleur', 20);
$quantite  = (int)($_POST['quantite'] ?? 1);
$message   = mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string)($_POST['message'] ?? ''))), 0, 600, 'UTF-8');
$consent   = ($_POST['consentement'] ?? '') === 'oui';

if ($nom === '')                                       repondre(false, 'Indiquez votre nom', $veutJson, 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))        repondre(false, 'E-mail invalide', $veutJson, 422);
if ($telephone !== '' && !preg_match('/^[0-9+ ().\-]{6,30}$/', $telephone))
                                                       repondre(false, 'Téléphone invalide', $veutJson, 422);
if ($iphone === '')                                    repondre(false, 'Choisissez votre iPhone', $veutJson, 422);
if (!in_array($coque, ['Oui', 'Non'], true))           repondre(false, 'Précisez si vous utilisez une coque', $veutJson, 422);
if (!in_array($couleur, COULEURS, true))               repondre(false, 'Couleur inconnue', $veutJson, 422);
if ($quantite < 1 || $quantite > 10)                   repondre(false, 'Quantité entre 1 et 10', $veutJson, 422);
if (!$consent)                                         repondre(false, 'Merci d\'accepter d\'être recontacté', $veutJson, 422);
// Les liens dans le message sont typiques du spam
if (preg_match('~https?://|www\.~i', $message))        repondre(false, 'Les liens ne sont pas acceptés dans le message', $veutJson, 422);

// Stockage hors web : dossier « precommandes » à côté du dossier www/
$racineWeb = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($racineWeb === '') {
    repondre(false, 'Erreur serveur', $veutJson, 500);
}
$dossier = dirname($racineWeb) . '/precommandes';
if (!is_dir($dossier) && !@mkdir($dossier, 0700, true)) {
    repondre(false, 'Erreur serveur', $veutJson, 500);
}
$fichier = $dossier . '/zbdock.csv';
$etat    = $dossier . '/.etat.json';

// 4) Limites (IP, doublons, plafond journalier), état partagé et verrouillé
$h = @fopen($etat, 'c+');
if ($h === false) {
    repondre(false, 'Erreur serveur', $veutJson, 500);
}
flock($h, LOCK_EX);
$donnees = json_decode((string)stream_get_contents($h), true);
if (!is_array($donnees)) { $donnees = []; }
$heure = date('YmdH');
$jour  = date('Ymd');
$sel   = 'zbdock-' . $jour;                                    // les IP ne sont jamais stockées en clair
$cleIp = 'ip:' . $heure . ':' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $sel);
$cleDb = 'dup:' . $heure . ':' . hash('sha256', strtolower($email) . '|' . $couleur);
$cleJr = 'mails:' . $jour;
// ne garder que l'heure et le jour en cours
$donnees = array_filter($donnees, function ($k) use ($heure, $jour) {
    return strpos($k, ':' . $heure . ':') !== false || $k === 'mails:' . $jour;
}, ARRAY_FILTER_USE_KEY);

$refus = null;
if (($donnees[$cleIp] ?? 0) >= MAX_PAR_IP_H) {
    $refus = 'ip';
} elseif (!empty($donnees[$cleDb])) {
    $refus = 'doublon';
}
if ($refus === null) {
    $donnees[$cleIp] = ($donnees[$cleIp] ?? 0) + 1;
    $donnees[$cleDb] = 1;
    $envoyerMail = ($donnees[$cleJr] ?? 0) < MAX_MAILS_JOUR;
    if ($envoyerMail) { $donnees[$cleJr] = ($donnees[$cleJr] ?? 0) + 1; }
}
ftruncate($h, 0);
rewind($h);
fwrite($h, (string)json_encode($donnees));
fflush($h);
flock($h, LOCK_UN);
fclose($h);

if ($refus === 'ip')      repondre(false, 'Trop de demandes, réessayez plus tard', $veutJson, 429);
if ($refus === 'doublon') ignorer($veutJson);

// Neutralise les formules Excel/Sheets dans le CSV
$csv = function (string $v): string { return preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v; };

$nouveau = !is_file($fichier);
$f = @fopen($fichier, 'a');
if ($f === false) {
    repondre(false, 'Erreur serveur', $veutJson, 500);
}
flock($f, LOCK_EX);
if ($nouveau) {
    @chmod($fichier, 0600);
    fputcsv($f, ['date', 'nom', 'email', 'telephone', 'iphone', 'coque', 'couleur', 'quantite', 'message']);
}
fputcsv($f, array_map($csv, [date('Y-m-d H:i'), $nom, $email, $telephone, $iphone, $coque, $couleur, (string)$quantite, str_replace(["\r", "\n"], ' ', $message)]));
flock($f, LOCK_UN);
fclose($f);

// E-mail de notification (sauf si le plafond du jour est atteint)
if (!empty($envoyerMail)) {
    $hote  = (string)preg_replace('/[^a-z0-9.\-]/', '', preg_replace('/^www\./', '', $hoteSite));
    if ($hote === '') { $hote = 'hansadebayo.fr'; }
    $sujet = '=?UTF-8?B?' . base64_encode("Précommande ZB Dock : $nom ($couleur x$quantite)") . '?=';
    $corps = "Nouvelle réservation ZB Dock\n\n"
           . "Nom : $nom\nE-mail : $email\nTéléphone : $telephone\n"
           . "iPhone : $iphone (coque : $coque)\nCouleur : $couleur\nQuantité : $quantite\n\n"
           . "Message :\n$message\n";
    $entetes = "From: ZB Dock <no-reply@$hote>\r\n"
             . "Reply-To: $email\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";
    @mail(DESTINATAIRE, $sujet, $corps, $entetes);
}

repondre(true, '', $veutJson);
