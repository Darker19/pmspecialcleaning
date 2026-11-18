<?php
// Gedeelde functies voor het contactformulier: versturen via Migadu (SMTP) en bewaren als het niet mag of lukt.
// Instellingen komen uit .env (zie compose.yml en .env.example).

const DATA = '/app/data';
const BEWAARD = DATA . '/bewaard';      // berichten die (nog) niet gemaild zijn
const MAX_PER_DAG = 20;                 // gelijk aan de limiet op formulier@ in Migadu

function instelling(string $naam): string {
    $waarde = getenv($naam);
    if ($waarde === false || $waarde === '') {
        throw new RuntimeException("Instelling $naam ontbreekt in .env");
    }
    return $waarde;
}

// Zet het bericht in een losse mail (platte tekst, UTF-8).
function maakMail(array $b): string {
    $van = instelling('MAIL_FROM');
    $aan = instelling('MAIL_TO');
    $domein = substr(strrchr($van, '@'), 1);
    $replyNaam = str_replace(['"', '<', '>', ',', ';', '\\'], '', $b['naam']);

    $tekst = "Nieuw bericht via het contactformulier van pmspecialcleaning.nl\n\n"
        . "Naam:      {$b['naam']}\n"
        . "E-mail:    {$b['email']}\n"
        . "Telefoon:  " . ($b['telefoon'] !== '' ? $b['telefoon'] : '-') . "\n"
        . "Ontvangen: " . date('d-m-Y H:i', $b['tijd']) . "\n\n"
        . $b['bericht'] . "\n\n"
        . "-- \nBeantwoorden gaat rechtstreeks naar {$b['email']}.\n";

    $kop = [
        'From: ' . mb_encode_mimeheader('Website PM Specialcleaning', 'UTF-8') . " <$van>",
        "To: <$aan>",
        'Reply-To: ' . mb_encode_mimeheader($replyNaam, 'UTF-8') . " <{$b['email']}>",
        'Subject: ' . mb_encode_mimeheader('Contactformulier: ' . $b['naam'], 'UTF-8'),
        'Date: ' . date(DATE_RFC2822, $b['tijd']),
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domein>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    return implode("\r\n", $kop) . "\r\n\r\n" . chunk_split(base64_encode($tekst), 76, "\r\n");
}

// Verstuurt via smtps://smtp.migadu.com:465. Geeft true terug als Migadu het bericht heeft aangenomen.
function verstuur(array $b): bool {
    $mail = maakMail($b);
    $pos = 0;
    $ch = curl_init('smtps://' . instelling('SMTP_HOST') . ':465');
    curl_setopt_array($ch, [
        CURLOPT_USERNAME => instelling('SMTP_USER'),
        CURLOPT_PASSWORD => instelling('SMTP_PASS'),
        CURLOPT_MAIL_FROM => '<' . instelling('MAIL_FROM') . '>',
        CURLOPT_MAIL_RCPT => ['<' . instelling('MAIL_TO') . '>'],
        CURLOPT_UPLOAD => true,
        CURLOPT_READFUNCTION => function ($ch, $fd, $len) use ($mail, &$pos) {
            $stuk = substr($mail, $pos, $len);
            $pos += strlen($stuk);
            return $stuk;
        },
        CURLOPT_USE_SSL => CURLUSESSL_ALL,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $ok = curl_exec($ch) !== false;
    if (!$ok) {
        // Komt in `docker compose logs`; zonder de inhoud van het bericht
        error_log('Versturen via Migadu mislukt: ' . curl_error($ch));
    }
    return $ok;
}

// Bewaart een bericht als JSON-bestand, zodat het later alsnog gemaild kan worden.
function bewaar(array $b, string $reden): void {
    if (!is_dir(BEWAARD)) {
        mkdir(BEWAARD, 0700, true);
    }
    $b['reden'] = $reden;
    $bestand = BEWAARD . '/' . date('Ymd-His', $b['tijd']) . '-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($bestand, json_encode($b, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    error_log("Bericht bewaard ($reden): " . basename($bestand));
}

// Teller per dag voor alles wat via formulier@ verstuurd is. $erbij = 0 leest alleen.
// Geeft het aantal van vandaag terug, of -1 als er geen ruimte meer is.
function dagTeller(int $erbij = 0): int {
    $fp = fopen(DATA . '/verstuurd.json', 'c+');
    flock($fp, LOCK_EX);
    $t = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
    $vandaag = date('Y-m-d');
    if (($t['dag'] ?? '') !== $vandaag) {
        $t = ['dag' => $vandaag, 'aantal' => 0];
    }
    $resultaat = $t['aantal'];
    if ($erbij > 0) {
        if ($t['aantal'] + $erbij > MAX_PER_DAG) {
            $resultaat = -1;
        } else {
            $t['aantal'] += $erbij;
            $resultaat = $t['aantal'];
        }
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($t));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $resultaat;
}
