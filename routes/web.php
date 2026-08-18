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

Route::get('/', [\App\Http\Controllers\IndexController::class, 'show'])
    ->middleware('auth.nexus:nexus');

Route::get('/index.php', [\App\Http\Controllers\IndexController::class, 'show'])
    ->middleware('auth.nexus:nexus');
Route::post('/index.php', [\App\Http\Controllers\IndexController::class, 'vote'])
    ->middleware('auth.nexus:nexus');

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

// ---- 注册确认 / 找回密码续接（Phase 1）----
Route::get('/confirm.php', [\App\Http\Controllers\AuthenticateController::class, 'confirm']);
Route::post('/takeconfirm.php', [\App\Http\Controllers\AuthenticateController::class, 'confirmUser'])
    ->middleware('auth.nexus:nexus-web');
Route::get('/confirm_resend.php', [\App\Http\Controllers\AuthenticateController::class, 'showConfirmResendForm']);
Route::post('/confirm_resend.php', [\App\Http\Controllers\AuthenticateController::class, 'resendConfirmation']);
Route::get('/confirmemail.php/{id}/{md5}/{email}', [\App\Http\Controllers\AuthenticateController::class, 'confirmEmailChange'])
    ->where('email', '.*');
Route::get('/self-enable.php', [\App\Http\Controllers\AuthenticateController::class, 'showSelfEnable'])
    ->middleware('auth.nexus:nexus');
Route::post('/self-enable.php', [\App\Http\Controllers\AuthenticateController::class, 'selfEnable'])
    ->middleware('auth.nexus:nexus');
Route::get('/checkuser.php', [\App\Http\Controllers\AuthenticateController::class, 'showCheckUser'])
    ->middleware('auth.nexus:nexus-web');
Route::any('/maxlogin.php', [\App\Http\Controllers\AuthenticateController::class, 'showMaxLogin'])
    ->middleware('auth.nexus:nexus-web');

Route::get("/error", [\App\Http\Controllers\ToolController::class, "error"]);

// =============================================================
// 轻量互动（Phase 2 P0）—— comment / bookmark / thanks / attendance
// =============================================================
Route::match(['get', 'post'], '/comment.php', [\App\Http\Controllers\CommentController::class, 'web'])
    ->middleware('auth.nexus:nexus');

Route::get('/bookmark.php', [\App\Http\Controllers\BookmarkController::class, 'toggle']);

Route::post('/thanks.php', [\App\Http\Controllers\ThankController::class, 'sayThanks'])
    ->middleware('auth.nexus:nexus');

Route::get('/attendance.php', [\App\Http\Controllers\AttendanceController::class, 'showPage'])
    ->middleware('auth.nexus:nexus');
Route::post('/attendance.php', [\App\Http\Controllers\AttendanceController::class, 'attendPage'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 轻量互动（Phase 2 P1）—— claim / medal / myhr
// =============================================================
Route::get('/claim.php', [\App\Http\Controllers\ClaimController::class, 'index'])
    ->middleware('auth.nexus:nexus');

Route::get('/medal.php', [\App\Http\Controllers\MedalController::class, 'showPage'])
    ->middleware('auth.nexus:nexus');

Route::get('/myhr.php', [\App\Http\Controllers\HitAndRunController::class, 'showPage'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 积分中心（Phase 2 P1）—— mybonus
// =============================================================
Route::get('/mybonus.php', [\App\Http\Controllers\BonusController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// bonus exchange submission (mirrors public/mybonus.php action=exchange)
Route::post('/mybonus.php', [\App\Http\Controllers\BonusController::class, 'webExchange'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 站内信（messages.php）—— Phase 7 P1 迁移
// =============================================================
Route::get('/messages.php', [\App\Http\Controllers\MessageController::class, 'web'])
    ->middleware('auth.nexus:nexus');
Route::post('/messages.php', [\App\Http\Controllers\MessageController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 发送站内信（takemessage.php）—— legacy sendmessage.php/messages.php 表单 POST
Route::get('/takemessage.php', [\App\Http\Controllers\MessageController::class, 'webTakeMessage'])
    ->middleware('auth.nexus:nexus');
Route::post('/takemessage.php', [\App\Http\Controllers\MessageController::class, 'webTakeMessage'])
    ->middleware('auth.nexus:nexus');

// 发送短讯页（sendmessage.php）—— GET: compose form (new / reply)
Route::get('/sendmessage.php', [\App\Http\Controllers\MessageController::class, 'webSendMessage'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 论坛（forums.php / moforums.php）—— Phase 7 P2 迁移
// =============================================================
// GET: portal / viewforum / viewtopic / viewunread / search / compose / confirm pages
// POST: post / movetopic / deletetopic / deletepost / setsticky / setlocked / hltopic
Route::match(['get', 'post'], '/forums.php', [\App\Http\Controllers\ForumController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 论坛分区管理（overforums）—— GET: list / edit forum; POST: del / addforum / editforum
Route::match(['get', 'post'], '/moforums.php', [\App\Http\Controllers\OverForumController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 首页与主列表（Phase 2 P2）—— topten / usersearch / userhistory
// =============================================================
Route::get('/topten.php', [\App\Http\Controllers\ToptenController::class, 'index'])
    ->middleware('auth.nexus:nexus');

Route::get('/usersearch.php', [\App\Http\Controllers\UserSearchController::class, 'index'])
    ->middleware('auth.nexus:nexus');

Route::get('/userhistory.php', [\App\Http\Controllers\UserHistoryController::class, 'index'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 首页与主列表（Phase 2 P3）—— viewsnatches / viewpeerlist / viewfilelist / viewnfo
// =============================================================
Route::get('/viewsnatches.php', [\App\Http\Controllers\SnatchController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// AJAX fragments (text/xml), guarded by the nexus session inside the controllers
Route::get('/viewpeerlist.php', [\App\Http\Controllers\PeerController::class, 'web']);
Route::get('/viewfilelist.php', [\App\Http\Controllers\FileController::class, 'web']);

Route::get('/viewnfo.php', [\App\Http\Controllers\ViewNfoController::class, 'show'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// RSS（Phase 2 P2）—— getrss form + torrentrss XML feed
// =============================================================
Route::match(['get', 'post'], '/getrss.php', [\App\Http\Controllers\RssController::class, 'index'])
    ->middleware('auth.nexus:nexus');

// passkey-gated feed, no session required (mirrors legacy torrentrss.php)
Route::get('/torrentrss.php', [\App\Http\Controllers\RssController::class, 'feed']);

// legacy AJAX JSON interface (no CSRF, session-checked per action inside the controller)
Route::post('/ajax.php', [\App\Http\Controllers\AjaxController::class, 'web']);

// user torrent lists (uploaded/seeding/leeching/completed/incomplete), AJAX fragment
Route::get('/getusertorrentlistajax.php', [\App\Http\Controllers\GetUserTorrentListAjaxController::class, 'web']);

// torrent search (Meili or Eloquent)
Route::get('/search.php', [\App\Http\Controllers\SearchController::class, 'index'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 搜索联想 / IMDb 信息 / OpenSearch（Phase 2 P3）—— 公开 API，无会话
// =============================================================
// search suggestions JSON endpoint
Route::get('/searchsuggest.php', [\App\Http\Controllers\SearchSuggestController::class, 'index']);

// IMDb info tooltip fragment (text/xml)
Route::get('/getextinfoajax.php', [\App\Http\Controllers\ExtInfoAjaxController::class, 'show']);

// OpenSearch description XML
Route::get('/opensearch.php', [\App\Http\Controllers\OpenSearchController::class, 'index']);

Route::get('/details.php', [\App\Http\Controllers\TorrentController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// user details page
Route::get('/userdetails.php', [\App\Http\Controllers\UserController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// torrent list (browse) and special section
Route::get('/torrents.php', [\App\Http\Controllers\TorrentController::class, 'browse'])
    ->middleware('auth.nexus:nexus')
    ->defaults('section', 'torrents');
Route::get('/special.php', [\App\Http\Controllers\TorrentController::class, 'browse'])
    ->middleware('auth.nexus:nexus')
    ->defaults('section', 'special');

// upload form
Route::get('/upload.php', [\App\Http\Controllers\UploadController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// upload submission (mirrors public/takeupload.php)
Route::post('/takeupload.php', [\App\Http\Controllers\UploadController::class, 'webTakeUpload'])
    ->middleware('auth.nexus:nexus');

// edit torrent form
Route::get('/edit.php', [\App\Http\Controllers\TorrentController::class, 'webEdit'])
    ->middleware('auth.nexus:nexus');

// edit torrent submission (mirrors public/takeedit.php)
Route::post('/takeedit.php', [\App\Http\Controllers\TorrentController::class, 'webTakeEdit'])
    ->middleware('auth.nexus:nexus');

// fast torrent deletion (staff) — mirrors public/fastdelete.php
Route::get('/fastdelete.php', [\App\Http\Controllers\TorrentController::class, 'webFastDelete'])
    ->middleware('auth.nexus:nexus');

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
