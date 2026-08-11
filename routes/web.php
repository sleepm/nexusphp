<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect('index.php');
});

// =============================================================
// 认证与会话（Auth）—— Phase 1 迁移，替代 public/login.php 等遗留页面
// =============================================================
Route::get('/login.php', [\App\Http\Controllers\AuthenticateController::class, 'showLoginForm']);
Route::get('/login', [\App\Http\Controllers\AuthenticateController::class, 'showLoginForm'])->name('nexus.login');
Route::post('/takelogin.php', [\App\Http\Controllers\AuthenticateController::class, 'webLogin']);
Route::post('/login', [\App\Http\Controllers\AuthenticateController::class, 'webLogin']);
Route::any('/logout.php', [\App\Http\Controllers\AuthenticateController::class, 'webLogout']);
Route::get('/logout', [\App\Http\Controllers\AuthenticateController::class, 'webLogout']);

Route::get('/signup.php', [\App\Http\Controllers\AuthenticateController::class, 'showSignupForm']);
Route::get('/signup', [\App\Http\Controllers\AuthenticateController::class, 'showSignupForm'])->name('nexus.signup');
Route::post('/takesignup.php', [\App\Http\Controllers\AuthenticateController::class, 'signup']);
Route::post('/signup', [\App\Http\Controllers\AuthenticateController::class, 'signup']);

Route::get('/recover.php', [\App\Http\Controllers\AuthenticateController::class, 'showRecoverForm']);
Route::get('/recover', [\App\Http\Controllers\AuthenticateController::class, 'showRecoverForm'])->name('nexus.recover');
Route::post('/recover.php', [\App\Http\Controllers\AuthenticateController::class, 'recover']);
Route::post('/recover', [\App\Http\Controllers\AuthenticateController::class, 'recover']);

Route::get('/reset.php', [\App\Http\Controllers\AuthenticateController::class, 'showResetForm']);
Route::get('/reset', [\App\Http\Controllers\AuthenticateController::class, 'showResetForm'])->name('nexus.reset');
Route::post('/reset.php', [\App\Http\Controllers\AuthenticateController::class, 'reset']);
Route::post('/reset', [\App\Http\Controllers\AuthenticateController::class, 'reset']);

Route::get("/error", [\App\Http\Controllers\ToolController::class, "error"]);

Route::group(['prefix' => 'web', 'middleware' => ['auth.nexus:nexus-web']], function () {
    Route::get('torrent-approval-page', [\App\Http\Controllers\TorrentController::class, 'approvalPage']);
    Route::get('torrent-approval-logs', [\App\Http\Controllers\TorrentController::class, 'approvalLogs']);
    Route::post('torrent-approval', [\App\Http\Controllers\TorrentController::class, 'approval']);
    Route::post('token/add', [\App\Http\Controllers\TokenController::class, 'addToken']);
    Route::post('token/del', [\App\Http\Controllers\TokenController::class, 'delToken']);
});

if (!isRunningInConsole()) {
    $passkeyLoginUri = get_setting('security.login_secret');
    if (!empty($passkeyLoginUri) && get_setting('security.login_type') == 'passkey') {
        Route::get("$passkeyLoginUri/{passkey}", [\App\Http\Controllers\AuthenticateController::class, 'passkeyLogin']);
    }
}

Route::group(['prefix' => 'oauth'], function () {
    Route::get("user-info", [\App\Http\Controllers\OauthController::class, 'userInfo'])->name("oauth.user_info")->middleware('auth:api');
    Route::get('redirect/{uuid}', [\App\Http\Controllers\OauthController::class, 'redirect']);
    Route::get('callback/{uuid}', [\App\Http\Controllers\OauthController::class, 'callback']);
});
