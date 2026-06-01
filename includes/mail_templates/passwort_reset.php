<?php
// Vars: reset_url, expires_in (z.B. "1 Stunde")
$content = '
<h2>&#128273; Passwort zurücksetzen</h2>
<p>Sie (oder jemand anderes) hat eine Passwort-Zurücksetzung für dieses Konto angefordert.</p>
<p>Klicken Sie auf den folgenden Button, um ein neues Passwort festzulegen:</p>

<a href="' . htmlspecialchars($reset_url) . '" class="btn">Neues Passwort festlegen</a>

<div class="info-box">
  <p><span class="label">Hinweis</span></p>
  <p>Dieser Link ist <strong>' . htmlspecialchars($expires_in) . '</strong> gültig.</p>
  <p>Danach müssen Sie erneut eine Zurücksetzung anfordern.</p>
</div>

<hr>
<p style="font-size:13px;color:#666;">
  Falls Sie keine Passwort-Zurücksetzung beantragt haben, ignorieren Sie diese E-Mail.
  Ihr Passwort bleibt unverändert.
</p>
<p style="font-size:12px;color:#aaa;word-break:break-all;">
  Link: ' . htmlspecialchars($reset_url) . '
</p>
';
echo mailLayout($content, 'Passwort zurücksetzen');
