<?php
/**
 * Allgemeine Geschäftsbedingungen (Vorlage – bitte anpassen)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$pageTitle = 'Allgemeine Geschäftsbedingungen';
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
                <i class="bi bi-file-text text-warning me-2"></i>Allgemeine Geschäftsbedingungen
            </h1>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">

                    <h2 class="h5 fw-bold">§ 1 Geltungsbereich</h2>
                    <p class="text-muted">
                        Diese AGB gelten für alle Reservierungen über das <?= htmlspecialchars(APP_NAME) ?>.
                        Mit der Registrierung und Nutzung des Systems erkennen Sie diese Bedingungen an.
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">§ 2 Reservierungen</h2>
                    <ul class="text-muted">
                        <li>Reservierungen sind verbindlich nach Buchungsbestätigung.</li>
                        <li>Pro Buchungsvorgang können bis zu 10 Sitzplätze reserviert werden.</li>
                        <li>Jede Reservierung erhält eine eindeutige Buchungsnummer.</li>
                        <li>Der Sitzplatz bleibt bis zum Check-in reserviert (Status: "Geplant").</li>
                    </ul>

                    <hr>

                    <h2 class="h5 fw-bold">§ 3 Stornierung</h2>
                    <ul class="text-muted">
                        <li>Stornierungen können vom Gast selbst über "Meine Reservierungen" vorgenommen werden,
                            solange der Status "Geplant" ist.</li>
                        <li>Eingecheckte Reservierungen können nur durch Kassierer oder Administratoren storniert werden.</li>
                        <li>Bei Stornierung wird der Sitzplatz sofort freigegeben.</li>
                    </ul>

                    <hr>

                    <h2 class="h5 fw-bold">§ 4 Zahlungsbedingungen</h2>
                    <ul class="text-muted">
                        <li>Die Zahlung erfolgt gemäß der bei der Registrierung gewählten Zahlungsart.</li>
                        <li>Bei Überweisung: Verwendungszweck ist die Buchungsnummer.</li>
                        <li>Der Ticketpreis wird bei Buchung festgelegt und ist verbindlich.</li>
                    </ul>

                    <hr>

                    <h2 class="h5 fw-bold">§ 5 Warteliste</h2>
                    <p class="text-muted">
                        Bei ausgebuchten Veranstaltungen können Sie sich auf die Warteliste eintragen.
                        Wird ein Platz frei, erhalten Sie eine E-Mail-Benachrichtigung.
                        Sie haben dann 24 Stunden Zeit, den Platz zu reservieren.
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">§ 6 Hausrecht</h2>
                    <p class="text-muted">
                        Der Veranstalter behält sich vor, Personen ohne Angabe von Gründen vom Veranstaltungsgelände
                        zu verweisen. In diesem Fall wird der Ticketbetrag nicht erstattet.
                    </p>

                    <hr>

                    <h2 class="h5 fw-bold">§ 7 Haftung</h2>
                    <p class="text-muted">
                        Der Veranstalter haftet nur für Schäden, die auf grober Fahrlässigkeit oder Vorsatz basieren.
                        Eine Haftung für höhere Gewalt ist ausgeschlossen.
                    </p>

                    <hr>
                    <p class="text-muted small">
                        <em>Stand: <?= date('m/Y') ?> – Diese AGB sind eine Vorlage und müssen rechtskonform angepasst werden.</em>
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
