<?php
// Vars: vorname, buchungsnummer, event_name, event_datum, betrag
$content = '
<h2>&#10007; Reservierung storniert</h2>
<p>Hallo <strong>' . htmlspecialchars($vorname) . '</strong>,</p>
<p>Ihre Reservierung wurde erfolgreich storniert.</p>

<div class="info-box">
  <p><span class="label">Veranstaltung</span></p>
  <p class="value">' . htmlspecialchars($event_name) . '</p>
  <p><span class="label">Datum</span></p>
  <p class="value">' . htmlspecialchars($event_datum) . '</p>
  <p><span class="label">Buchungsnummer</span></p>
  <p class="value">' . htmlspecialchars($buchungsnummer) . '</p>
  <p><span class="label">Betrag</span></p>
  <p class="value">' . htmlspecialchars($betrag) . '</p>
</div>

<p>Der gebuchte Platz ist nun wieder freigegeben.</p>
<a href="' . APP_URL . '/pages/events.php" class="btn">Andere Events entdecken</a>

<hr>
<p style="font-size:13px;color:#666;">
  Wenn Sie diese Stornierung nicht veranlasst haben, kontaktieren Sie bitte sofort den Kassierer.
</p>
';
echo mailLayout($content, 'Stornierungsbestätigung');
