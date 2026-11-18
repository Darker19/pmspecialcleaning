<?php
// Verstuurt bewaarde berichten alsnog (daglimiet bereikt of Migadu was even niet bereikbaar).
// Draait elk uur via cron op de VPS: docker exec pmcleaning-contact-php-1 php /app/lib/bewaard-versturen.php
// Houdt zich aan dezelfde daglimiet; wat niet past, blijft staan voor de volgende keer.

require '/app/lib/mail.php';

$bestanden = glob(BEWAARD . '/*.json') ?: [];
sort($bestanden);
$verstuurd = 0;
foreach ($bestanden as $bestand) {
    $b = json_decode((string)file_get_contents($bestand), true);
    if (!is_array($b)) {
        error_log('Onleesbaar bewaard bericht: ' . basename($bestand));
        continue;
    }
    if (dagTeller(1) < 0) {
        break;
    }
    if (!verstuur($b)) {
        break;
    }
    unlink($bestand);
    $verstuurd++;
}
$over = count(glob(BEWAARD . '/*.json') ?: []);
if ($verstuurd > 0 || $over > 0) {
    echo date('c') . " bewaard verstuurd: $verstuurd, nog bewaard: $over\n";
}
