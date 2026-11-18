<?php
// Contactformulier van pmspecialcleaning.nl. Caddy stuurt POST /contact-verzenden.php hierheen.
// Controleert het bericht, beperkt het aantal per bezoeker en per dag, en verstuurt via Migadu (formulier@ -> info@).
// Lukt versturen niet, of is de daglimiet bereikt, dan wordt het bericht bewaard en later verstuurd (zie lib/bewaard-versturen.php).

require '/app/lib/mail.php';

const MAX_PER_IP_PER_UUR = 3;

function antwoord(int $status, string $tekst): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $tekst;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    antwoord(405, 'Alleen versturen via het formulier.');
}

// Honeypot: onzichtbaar veld, alleen bots vullen het in. Doe alsof het gelukt is.
if (trim((string)($_POST['company_website'] ?? '')) !== '') {
    antwoord(200, 'OK');
}

// Te snel na het laden van de pagina verstuurd: vrijwel zeker een bot.
$formStart = (int)($_POST['form_start'] ?? 0);
if ($formStart === 0 || time() - $formStart < 4) {
    antwoord(400, 'Formulier te snel verzonden.');
}

$veld = fn(string $naam, int $max) => mb_substr(trim((string)($_POST[$naam] ?? '')), 0, $max);
$naam     = str_replace(["\r", "\n"], ' ', $veld('naam', 100));
$email    = str_replace(["\r", "\n"], '', $veld('email', 254));
$telefoon = str_replace(["\r", "\n"], ' ', $veld('telefoon', 30));
$bericht  = $veld('bericht', 5000);

if ($naam === '' || $email === '' || $bericht === '') {
    antwoord(422, 'Verplichte velden ontbreken.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    antwoord(422, 'Ongeldig e-mailadres.');
}

// Per bezoeker (gehasht IP-adres) maximaal een paar berichten per uur.
$fp = fopen(DATA . '/bezoekers.json', 'c+');
flock($fp, LOCK_EX);
$ips = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
$nu = time();
foreach ($ips as $k => $tijden) {
    $ips[$k] = array_values(array_filter($tijden, fn($t) => $t > $nu - 3600));
    if (!$ips[$k]) unset($ips[$k]);
}
$ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
$teVeel = count($ips[$ip] ?? []) >= MAX_PER_IP_PER_UUR;
if (!$teVeel) {
    $ips[$ip][] = $nu;
}
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($ips));
flock($fp, LOCK_UN);
fclose($fp);
if ($teVeel) {
    antwoord(429, 'Er zijn net al berichten verstuurd. Probeer het later nog eens.');
}

$b = ['naam' => $naam, 'email' => $email, 'telefoon' => $telefoon, 'bericht' => $bericht, 'tijd' => $nu];

// Daglimiet voor formulier@: daarboven bewaren in plaats van mailen. De bezoeker merkt er niets van.
if (dagTeller(1) < 0) {
    bewaar($b, 'daglimiet');
    antwoord(200, 'OK');
}
if (!verstuur($b)) {
    bewaar($b, 'versturen mislukt');
}
antwoord(200, 'OK');
