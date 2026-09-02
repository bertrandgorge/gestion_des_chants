<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Controllers\AuthController;
use App\Controllers\ChantController;
use App\Controllers\ClocherController;
use App\Controllers\FeuilleController;
use App\Controllers\ParoisseController;
use App\Controllers\PublicController;
use App\Controllers\UtilisateurController;
use App\Router;

$router = new Router();

// --- Accueil ------------------------------------------------------------
$router->get('/', function () {
    redirect(Auth::check() ? '/app' : '/login');
});

// --- Authentification --------------------------------------------------
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/connexion/{token}', [AuthController::class, 'connexion']);
$router->get('/invitation/{token}', [AuthController::class, 'invitation']);

// --- Administration de la paroisse (admin) ----------------------------
$router->get('/admin/paroisse', [ParoisseController::class, 'edit']);
$router->post('/admin/paroisse', [ParoisseController::class, 'update']);
$router->get('/admin/utilisateurs', [UtilisateurController::class, 'index']);
$router->post('/admin/utilisateurs', [UtilisateurController::class, 'invite']);
$router->post('/admin/utilisateurs/{id}/role', [UtilisateurController::class, 'updateRole']);
$router->post('/admin/utilisateurs/{id}/supprimer', [UtilisateurController::class, 'delete']);
$router->post('/admin/utilisateurs/{id}/relancer', [UtilisateurController::class, 'resend']);
$router->get('/admin/clochers', [ClocherController::class, 'index']);
$router->post('/admin/clochers', [ClocherController::class, 'store']);
$router->get('/admin/clochers/{id}', [ClocherController::class, 'edit']);
$router->post('/admin/clochers/{id}', [ClocherController::class, 'update']);
$router->post('/admin/clochers/{id}/supprimer', [ClocherController::class, 'delete']);
$router->get('/admin/clochers/{id}/qrcode.svg', [ClocherController::class, 'qrcode']);

// --- Interface chantre -----------------------------------------------
$router->get('/app', [FeuilleController::class, 'index']);
$router->get('/app/anciennes', [FeuilleController::class, 'past']);
$router->get('/app/feuilles/nouveau', [FeuilleController::class, 'createForm']);
$router->post('/app/feuilles/nouveau', [FeuilleController::class, 'create']);
$router->get('/app/feuilles/{id}', [ChantController::class, 'editSheet']);
$router->post('/app/feuilles/{id}/copier', [FeuilleController::class, 'copy']);
$router->post('/app/feuilles/{id}/supprimer', [FeuilleController::class, 'delete']);
$router->post('/app/feuilles/{id}/resync', [FeuilleController::class, 'resync']);
$router->post('/app/feuilles/{id}/sections', [ChantController::class, 'sections']);
$router->get('/app/sections/{id}', [ChantController::class, 'editSection']);
$router->post('/app/sections/{id}', [ChantController::class, 'saveSection']);
$router->post('/app/sections/{id}/apercu', [ChantController::class, 'previewSection']);
$router->post('/app/sections/{id}/reprendre-ordinaire', [ChantController::class, 'reprendreOrdinaire']);
$router->get('/app/chants/recherche', [ChantController::class, 'search']);

// --- Interface paroissien (catch-all, en dernier) --------------------
$router->get('/{paroisse}/{clocher}', [PublicController::class, 'show']);
$router->get('/{paroisse}/{clocher}/{datetime}', [PublicController::class, 'show']);

// --- Distribution ----------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'POST') {
    Csrf::check();
}

try {
    $router->dispatch($method, $path);
} catch (Throwable $e) {
    error_log('[gdc] ' . $e);
    http_response_code(500);
    if (ini_get('display_errors')) {
        echo '<pre>' . e((string) $e) . '</pre>';
    } else {
        echo view('errors/500');
    }
}
