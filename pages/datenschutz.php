<?php
/**
 * Datenschutzerklärung (DSGVO-konform – Vorlage, bitte an tatsächliche Gegebenheiten anpassen)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$pageTitle = 'Datenschutzerklärung';
$bodyClass = 'bg-light';
$extraHead = '';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/navbar.php';
?>

<main class="py-5">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-9">

            <h1 class="fw-bold mb-4">
                <i class="bi bi-shield-lock text-warning me-2"></i>Datenschutzerklärung
            </h1>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">

                    <h2 class="h5 fw-bold">1. Verantwortlicher</h2>
                    <p class="text-muted">
                        Verantwortlicher im Sinne der DSGVO ist die für das Karneval-Reservierungssystem
                        verantwortliche Person/Organisation. Kontakt: <?= htmlspecialchars(MAIL_FROM) ?>
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">2. Erhobene Daten</h2>
                    <p class="text-muted">Bei der Registrierung erheben wir folgende Daten:</p>
                    <ul class="text-muted">
                        <li>Vor- und Nachname</li>
                        <li>E-Mail-Adresse</li>
                        <li>Bevorzugte Zahlungsart</li>
                        <li>Optionale Angaben: Adresse, Telefonnummer, Geburtsdatum</li>
                    </ul>
                    <p class="text-muted">Bei der Reservierung speichern wir:</p>
                    <ul class="text-muted">
                        <li>Buchungsnummer, Sitzplatz, Event, Buchungsdatum</li>
                        <li>Zahlungsinformationen (Betrag, Zahlungsart, Status)</li>
                    </ul>
                    <p class="text-muted">Technisch werden außerdem IP-Adressen und Zeitstempel im Audit-Log gespeichert.</p>

                    <hr>

                    <h2 class="h5 fw-bold">3. Zweck der Verarbeitung</h2>
                    <p class="text-muted">
                        Die Daten werden ausschließlich zur Durchführung von Reservierungen für Karnevals-Veranstaltungen
                        verarbeitet (Art. 6 Abs. 1 lit. b DSGVO – Vertragserfüllung).
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">4. Speicherdauer</h2>
                    <p class="text-muted">
                        Ihre Daten werden so lange gespeichert, wie Ihr Konto aktiv ist und keine Löschung
                        beantragt wurde. Reservierungsdaten können aus buchhalterischen Gründen für bis zu
                        10 Jahre aufbewahrt werden.
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">5. Ihre Rechte</h2>
                    <p class="text-muted">Sie haben folgende Rechte:</p>
                    <ul class="text-muted">
                        <li><strong>Auskunft</strong> (Art. 15 DSGVO): Welche Daten wir über Sie gespeichert haben</li>
                        <li><strong>Berichtigung</strong> (Art. 16 DSGVO): Korrektur falscher Daten über Ihr Profil</li>
                        <li><strong>Löschung</strong> (Art. 17 DSGVO): Löschung Ihres Kontos über "Profil → Konto löschen"</li>
                        <li><strong>Widerspruch</strong> (Art. 21 DSGVO): Widerspruch gegen die Verarbeitung</li>
                        <li><strong>Beschwerderecht</strong>: Bei der zuständigen Datenschutzbehörde</li>
                    </ul>

                    <hr>

                    <h2 class="h5 fw-bold">6. Weitergabe an Dritte</h2>
                    <p class="text-muted">
                        Ihre Daten werden nicht an Dritte weitergegeben, außer soweit dies zur
                        Vertragserfüllung notwendig ist oder Sie ausdrücklich eingewilligt haben.
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">7. E-Mail-Kommunikation</h2>
                    <p class="text-muted">
                        Wir senden Ihnen buchungsbezogene E-Mails (Bestätigungen, Wartelisten-Benachrichtigungen).
                        Diese sind notwendig zur Vertragserfüllung und können nicht deaktiviert werden.
                    </p>

                    <hr>
                    <p class="text-muted small">
                        <em>Stand: <?= date('m/Y') ?> – Diese Datenschutzerklärung ist eine Vorlage und muss an die
                        tatsächlichen Gegebenheiten angepasst werden.</em>
                    </p>

                </div>
            </div>

            <div class="text-center mt-3">
                <a href="javascript:history.back()" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Zurück
                </a>
            </div>

        </div>
    </div>
</div>
</main>

<?php
$extraScripts = '';
include __DIR__ . '/../includes/footer.php';
