<?php

// Basisversie: nog niet getest op een hostingpartij. Bij de keuze van een host nalopen:
// - $from moet een adres op het domein van de site zijn, anders belandt de mail in spam;
// - de host moet mail() ondersteunen, of vervang dit door SMTP (bijv. PHPMailer).

$to = "pmspecialcleaning@outlook.com";
$from = "noreply@pmspecialcleaning.nl";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

// Honeypot anti-spam veld
if (!empty($_POST["company_website"])) {
    http_response_code(403);
    exit;
}

$formStart = (int)($_POST["form_start"] ?? 0);

if ($formStart === 0 || time() - $formStart < 4) {
    http_response_code(400);
    echo "Formulier te snel verzonden.";
    exit;
}

$naam = trim($_POST["naam"] ?? '');
$email = trim($_POST["email"] ?? '');
$telefoon = trim($_POST["telefoon"] ?? '');
$bericht = trim($_POST["bericht"] ?? '');

if ($naam === "" || $email === "" || $bericht === "") {
    http_response_code(400);
    echo "Verplichte velden ontbreken.";
    exit;
}

// Regeleinden verwijderen uit velden die in de mailheaders
// terechtkomen; voorkomt header-injectie (bijv. een extra "Bcc:" regel).
$naam = str_replace(["\r", "\n"], ' ', $naam);
$email = str_replace(["\r", "\n"], '', $email);
$telefoon = str_replace(["\r", "\n"], ' ', $telefoon);

// Limieten in bytes; ruim boven de maxlength (tekens) in contact.html,
// zodat letters als é of emoji legitieme berichten niet blokkeren.
if (strlen($naam) > 400 || strlen($email) > 254 || strlen($telefoon) > 120 || strlen($bericht) > 20000) {
    http_response_code(400);
    echo "Een of meer velden zijn te lang.";
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo "Ongeldig e-mailadres.";
    exit;
}

$subject = "Nieuw bericht via PM Specialcleaning";

$message = "
Naam: $naam

E-mail: $email

Telefoon: $telefoon

Bericht:
$bericht
";

$headers = "From: PM Specialcleaning <$from>\r\n";
// Tekens die de adresnotatie kunnen breken weghalen uit de weergavenaam.
$replyNaam = str_replace(['"', '<', '>', ',', ';'], '', $naam);
$headers .= "Reply-To: \"$replyNaam\" <$email>\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (mail($to, $subject, $message, $headers)) {
    http_response_code(200);
    echo "OK";
} else {
    http_response_code(500);
    echo "Mail kon niet worden verzonden.";
}

exit;
