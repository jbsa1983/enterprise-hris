<?php
// API front controller. All /api/v1/* requests route here.
require __DIR__ . '/../app/bootstrap.php';

$origin = Config::get('cors_origin');
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// Strip everything up to and including /api/v1 to get the route path.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$prefix = '/api/v1';
$pos = strpos($uri, $prefix);
$path = $pos !== false ? substr($uri, $pos + strlen($prefix)) : $uri;
$path = rtrim($path, '/');
if ($path === '') $path = '/';

$router = new Router();
$router->get('/health', fn() => Http::json(['status' => 'ok', 'service' => 'hris-php', 'version' => '1.0.0']));
AuthController::routes($router);
OrganizationController::routes($router);
DashboardController::routes($router);
PeopleController::routes($router);
OrgStructureController::routes($router);
ProjectsController::routes($router);
PayrollController::routes($router);
PayslipController::routes($router);
BankExportController::routes($router);
LoansController::routes($router);
AttendanceController::routes($router);
SpecialPayController::routes($router);
RecruitmentController::routes($router);
AssetsController::routes($router);
BenefitsController::routes($router);
HrModulesController::routes($router);
ReportsController::routes($router);
EssController::routes($router);
AdminController::routes($router);
StorageController::routes($router);
BrandingController::routes($router);
LicenseController::routes($router);
BackupController::routes($router);
TelegramController::routes($router);

// When license enforcement is on, lock everything except sign-in and activation
// until a valid license is installed.
if (License::ENFORCE) {
    $allow = ['/health', '/branding', '/license', '/auth/login', '/auth/refresh', '/auth/me', '/admin/license', '/telegram/webhook'];
    if (!in_array($path, $allow, true) && !License::status()['active']) {
        Http::error('This installation is not activated. Enter a valid license key in Administration → License.', 403);
    }
}

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (HttpError $e) {
    Http::error($e->getMessage(), $e->status, $e->extra);
} catch (Throwable $e) {
    Http::error('Server error: ' . $e->getMessage(), 500);
}
