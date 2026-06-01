<?php
// Vars: vorname, buchungsnummern (array), event_name, event_datum, anzahl, betrag_gesamt, zahlungsart, ticket_url
$content = '
<h2>&#10003; Reservierung bestätigt!</h2>
<p>Hallo <strong>' . htmlspecialchars($vorname) . '</strong>,</p>
<p>Ihre Reservierung für die Veranstaltung wurde erfolgreich angelegt. Hier sind Ihre Buchungsdetails:</p>

<div class="info-box">
  <p><span class="label">Veranstaltung</span></p>
  <p class="value">' . htmlspecialchars($event_name) . '</p>
  <p><span class="label">Datum</span></p>
  <p class="value">' . htmlspecialchars($event_datum) . '</p>
  <p><span class="label">Anzahl Plätze</span></p>
  <p class="value">' . (int)$anzahl . '</p>
  <p><span class="label">Buchungsnummer(n)</span></p>
  <p class="value">' . htmlspecialchars(implode(', ', (array)$buchungsnummern)) . '</p>
  <p><span class="label">Gesamtbetrag</span></p>
  <p class="value">' . htmlspecialchars($betrag_gesamt) . '</p>
  <p><span class="label">Zahlungsart</span></p>
  <p class="value">' . htmlspecialchars($zahlungsart) . '</p>
</div>

<p>Ihr digitales Ticket finden Sie unter folgendem Link:</p>
<a href="' . htmlspecialchars($ticket_url) . '" class="btn">&#127915; Ticket anzeigen</a>

<hr>
<p style="font-size:13px;color:#666;">
  Bitte zeigen Sie Ihr Ticket beim Einlass vor. Bei Fragen wenden Sie sich an den Kassierer.
</p>
';
echo mailLayout($content, 'Buchungsbestätigung');
