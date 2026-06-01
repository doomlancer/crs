<?php
/**
 * E-Mail-System: Wrapper um PHP mail() mit HTML-Template-System
 */

/**
 * HTML-E-Mail versenden
 * @param string $to      Empfänger-E-Mail
 * @param string $subject Betreff
 * @param string $template Template-Dateiname (ohne .php)
 * @param array  $vars    Variablen für das Template
 */
function sendMail(string $to, string $subject, string $template, array $vars = []): bool {
    $templateFile = __DIR__ . '/mail_templates/' . $template . '.php';
    if (!file_exists($templateFile)) {
        error_log("Mail-Template nicht gefunden: {$templateFile}");
        return false;
    }

    // Template rendern
    extract($vars, EXTR_SKIP);
    ob_start();
    include $templateFile;
    $body = ob_get_clean();

    $fromName = MAIL_FROM_NAME;
    $fromMail = MAIL_FROM;

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=utf-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromMail}>\r\n";
    $headers .= "Reply-To: {$fromMail}\r\n";
    $headers .= "X-Mailer: KarnevalRS/1.0\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $result = @mail($to, $encodedSubject, $body, $headers);
    if (!$result) {
        error_log("Mail-Versand fehlgeschlagen an: {$to}, Betreff: {$subject}");
    }
    return $result;
}

/**
 * Gemeinsames E-Mail-Layout (Header + Footer)
 */
function mailLayout(string $content, string $title = ''): string {
    $appName = APP_NAME;
    $appUrl  = APP_URL;
    $year    = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
  body{margin:0;padding:0;background:#1a1a1a;font-family:Arial,Helvetica,sans-serif;}
  .wrapper{max-width:600px;margin:0 auto;background:#ffffff;}
  .header{background:#111;padding:24px 32px;text-align:center;}
  .header h1{color:#f59e0b;margin:0;font-size:22px;letter-spacing:1px;}
  .header p{color:#999;margin:4px 0 0;font-size:13px;}
  .body{padding:32px;}
  .body h2{color:#111;font-size:18px;margin-top:0;}
  .body p{color:#444;line-height:1.6;margin:8px 0;}
  .btn{display:inline-block;background:#f59e0b;color:#111!important;padding:12px 28px;
       border-radius:6px;text-decoration:none;font-weight:bold;font-size:15px;margin:16px 0;}
  .info-box{background:#f8f9fa;border-left:4px solid #f59e0b;padding:12px 16px;margin:16px 0;border-radius:0 6px 6px 0;}
  .info-box p{margin:4px 0;color:#333;font-size:14px;}
  .label{color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px;}
  .value{color:#111;font-size:16px;font-weight:bold;}
  .footer{background:#111;padding:20px 32px;text-align:center;}
  .footer p{color:#666;font-size:12px;margin:4px 0;}
  .footer a{color:#f59e0b;}
  hr{border:none;border-top:1px solid #eee;margin:24px 0;}
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1>🎭 {$appName}</h1>
    <p>Karneval Reservierungssystem</p>
  </div>
  <div class="body">
    {$content}
  </div>
  <div class="footer">
    <p>&copy; {$year} {$appName}</p>
    <p><a href="{$appUrl}">{$appUrl}</a></p>
    <p style="color:#555;font-size:11px;">Diese E-Mail wurde automatisch generiert. Bitte nicht antworten.</p>
  </div>
</div>
</body>
</html>
HTML;
}
