<?php
// Vars: gast_name, gast_email, buchungsnummer, event_name, event_datum, betrag, storniert_von
$content = '
<h2>&#9888;&#65039; Stornierung – Information</h2>
<p>Eine Reservierung wurde storniert. Details:</p>

<div class="info-box">
  <p><span class="label">Gast</span></p>
  <p class="value">' . htmlspecialchars($gast_name) . ' (' . htmlspecialchars($gast_email) . ')</p>
  <p><span class="label">Buchungsnummer</span></p>
  <p class="value">' . htmlspecialchars($buchungsnummer) . '</p>
  <p><span class="label">Veranstaltung</span></p>
  <p class="value">' . htmlspecialchars($event_name) . '</p>
  <p><span class="label">Datum</span></p>
  <p class="value">' . htmlspecialchars($event_datum) . '</p>
  <p><span class="label">Betrag</span></p>
  <p class="value">' . htmlspecialchars($betrag) . '</p>
  <p><span class="label">Storniert von</span></p>
  <p class="value">' . htmlspecialchars($storniert_von) . '</p>
</div>

<a href="' . APP_URL . '/pages/kassierer_guestlist.php" class="btn">Gästeliste öffnen</a>
';
echo mailLayout($content, 'Stornierung – Admin-Info');
