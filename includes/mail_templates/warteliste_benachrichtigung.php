<?php
// Vars: vorname, event_name, event_datum, accept_url, expires_in
$content = '
<h2>&#9203; Platz wurde frei!</h2>
<p>Hallo <strong>' . htmlspecialchars($vorname) . '</strong>,</p>
<p>Gute Neuigkeiten! Ein Platz für die folgende Veranstaltung ist frei geworden:</p>

<div class="info-box">
  <p><span class="label">Veranstaltung</span></p>
  <p class="value">' . htmlspecialchars($event_name) . '</p>
  <p><span class="label">Datum</span></p>
  <p class="value">' . htmlspecialchars($event_datum) . '</p>
</div>

<p><strong>Sie haben ' . htmlspecialchars($expires_in) . ', um diesen Platz zu reservieren!</strong></p>
<p>Danach wird der nächste Gast auf der Warteliste benachrichtigt.</p>

<a href="' . htmlspecialchars($accept_url) . '" class="btn">&#9989; Platz jetzt reservieren</a>

<hr>
<p style="font-size:13px;color:#666;">
  Wenn Sie nicht mehr interessiert sind, können Sie diesen Link einfach ignorieren.
</p>
<p style="font-size:12px;color:#aaa;word-break:break-all;">
  Link: ' . htmlspecialchars($accept_url) . '
</p>
';
echo mailLayout($content, 'Platz auf der Warteliste verfügbar');
