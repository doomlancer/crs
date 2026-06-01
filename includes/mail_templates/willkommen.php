<?php
// Vars: vorname, nachname, email, login_url
$content = '
<h2>&#127881; Willkommen beim Karneval!</h2>
<p>Hallo <strong>' . htmlspecialchars($vorname) . '</strong>,</p>
<p>Herzlich willkommen beim ' . htmlspecialchars(APP_NAME) . '! Ihr Konto wurde erfolgreich erstellt.</p>

<div class="info-box">
  <p><span class="label">Ihr Konto</span></p>
  <p class="value">' . htmlspecialchars($email) . '</p>
</div>

<p>Sie können sich jetzt anmelden und Plätze für unsere Karnevals-Veranstaltungen reservieren:</p>
<a href="' . htmlspecialchars($login_url) . '" class="btn">Jetzt anmelden</a>

<hr>
<p style="font-size:13px;color:#666;">
  Wir freuen uns, Sie dabei zu haben! Bei Fragen steht Ihnen unser Kassierer-Team gerne zur Verfügung.
</p>
';
echo mailLayout($content, 'Willkommen beim Karneval');
