<?php
// Loads all framework classes and controllers. No Composer / autoloader needed.
error_reporting(E_ALL);
ini_set('display_errors', '0'); // errors returned as JSON, not echoed into responses

require __DIR__ . '/Config.php';
require __DIR__ . '/Util.php';
require __DIR__ . '/Http.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Jwt.php';
require __DIR__ . '/Tokens.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Rbac.php';
require __DIR__ . '/License.php';
require __DIR__ . '/Audit.php';
require __DIR__ . '/Mailer.php';
require __DIR__ . '/Telegram.php';
require __DIR__ . '/Notify.php';
require __DIR__ . '/Payroll.php';
require __DIR__ . '/Obligation.php';
require __DIR__ . '/PayslipService.php';
require __DIR__ . '/Router.php';

foreach (glob(__DIR__ . '/Controllers/*.php') as $f) {
    require $f;
}
