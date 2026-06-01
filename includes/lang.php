<?php
// Initialize language from session, default to 'de'
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'de';
}
$GLOBALS['_lang'] = $_SESSION['lang'];

$LANG = [
    'de' => [
        // Navbar
        'nav_events'        => 'Events',
        'nav_tischplan'     => 'Tischplan',
        'nav_my_bookings'   => 'Meine Reservierungen',
        'nav_cashier'       => 'Kassierer',
        'nav_admin'         => 'Admin',
        'nav_dashboard'     => 'Dashboard',
        'nav_guestlist'     => 'Gästeliste',
        'nav_daily_close'   => 'Tagesabschluss',
        'nav_statistics'    => 'Statistiken',
        'nav_event_mgmt'    => 'Event-Management',
        'nav_users'         => 'Benutzer',
        'nav_audit'         => 'Audit-Log',
        'nav_profile'       => 'Mein Profil',
        'nav_logout'        => 'Abmelden',
        'nav_login'         => 'Anmelden',
        'nav_register'      => 'Registrieren',
        // Common
        'btn_reserve'       => 'Jetzt reservieren',
        'btn_cancel'        => 'Stornieren',
        'btn_save'          => 'Speichern',
        'btn_back'          => 'Zurück',
        'lbl_email'         => 'E-Mail-Adresse',
        'lbl_password'      => 'Passwort',
        'lbl_firstname'     => 'Vorname',
        'lbl_lastname'      => 'Nachname',
        'lbl_payment'       => 'Zahlungsart',
        'lbl_status'        => 'Status',
        'lbl_date'          => 'Datum',
        'lbl_price'         => 'Preis',
        'lbl_total'         => 'Gesamt',
        // Events
        'page_events'       => 'Veranstaltungen',
        'lbl_available'     => 'Verfügbar',
        'lbl_sold_out'      => 'Ausgebucht',
        'lbl_seats_free'    => 'Plätze frei',
        // Auth
        'login_title'       => 'Anmelden',
        'register_title'    => 'Registrieren',
        'forgot_password'   => 'Passwort vergessen?',
        'no_account'        => 'Noch kein Konto?',
        'have_account'      => 'Bereits registriert?',
    ],
    'en' => [
        // Navbar
        'nav_events'        => 'Events',
        'nav_tischplan'     => 'Seating Plan',
        'nav_my_bookings'   => 'My Bookings',
        'nav_cashier'       => 'Cashier',
        'nav_admin'         => 'Admin',
        'nav_dashboard'     => 'Dashboard',
        'nav_guestlist'     => 'Guest List',
        'nav_daily_close'   => 'Daily Report',
        'nav_statistics'    => 'Statistics',
        'nav_event_mgmt'    => 'Event Management',
        'nav_users'         => 'Users',
        'nav_audit'         => 'Audit Log',
        'nav_profile'       => 'My Profile',
        'nav_logout'        => 'Log Out',
        'nav_login'         => 'Log In',
        'nav_register'      => 'Register',
        // Common
        'btn_reserve'       => 'Reserve Now',
        'btn_cancel'        => 'Cancel',
        'btn_save'          => 'Save',
        'btn_back'          => 'Back',
        'lbl_email'         => 'E-Mail Address',
        'lbl_password'      => 'Password',
        'lbl_firstname'     => 'First Name',
        'lbl_lastname'      => 'Last Name',
        'lbl_payment'       => 'Payment Method',
        'lbl_status'        => 'Status',
        'lbl_date'          => 'Date',
        'lbl_price'         => 'Price',
        'lbl_total'         => 'Total',
        // Events
        'page_events'       => 'Events',
        'lbl_available'     => 'Available',
        'lbl_sold_out'      => 'Sold Out',
        'lbl_seats_free'    => 'seats available',
        // Auth
        'login_title'       => 'Log In',
        'register_title'    => 'Register',
        'forgot_password'   => 'Forgot password?',
        'no_account'        => 'No account yet?',
        'have_account'      => 'Already registered?',
    ],
];

function __($key): string {
    global $LANG;
    $lang = $_SESSION['lang'] ?? 'de';
    return $LANG[$lang][$key] ?? $LANG['de'][$key] ?? $key;
}
