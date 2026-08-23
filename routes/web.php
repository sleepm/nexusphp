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
// 投票管理（makepoll / polloverview）—— 替代 public/makepoll.php / public/polloverview.php
// =============================================================
// 创建/编辑投票表单 + 提交（GET/POST，镜像 public/makepoll.php）
Route::match(['get', 'post'], '/makepoll.php', [\App\Http\Controllers\PollController::class, 'webMakePoll'])
    ->middleware('auth.nexus:nexus');

// 投票概况：?id=N 显示投票详情（选项 + 用户投票），否则列出所有投票（镜像 public/polloverview.php）
Route::get('/polloverview.php', [\App\Http\Controllers\PollController::class, 'webOverview'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 聊天室 & 趣味盒子—— 替代 public/shoutbox.php / public/fun.php
// =============================================================
// shoutbox 为 iframe 片段：type=helpbox 允许游客访问（登录页帮助盒），type=shoutbox 需登录；
// ?del=ID 删除消息（sbmanage），?sent=yes&shbox_text=.. 发言（镜像 public/shoutbox.php）
Route::get('/shoutbox.php', [\App\Http\Controllers\ShoutboxController::class, 'web']);

// 趣味盒子：action=view 为 iframe 片段；new/add/edit/delete/ban/vote 均需登录（镜像 public/fun.php）
Route::match(['get', 'post'], '/fun.php', [\App\Http\Controllers\FunController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 好友/黑名单（friends.php）—— GET 渲染好友+屏蔽用户列表；?action=add|delete 增删（镜像 public/friends.php）
Route::match(['get', 'post'], '/friends.php', [\App\Http\Controllers\FriendsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 个人控制面板（usercp.php）—— 首页/个人/种子/论坛/安全设置（镜像 public/usercp.php）
Route::match(['get', 'post'], '/usercp.php', [\App\Http\Controllers\UserCpController::class, 'web'])
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
// 通用提示 / 预览 / 赠魔（静态与信息页收尾，第 12 节 P3）
// 替代 public/ok.php / public/preview.php / public/magic.php
// =============================================================
// 通用提示页（ok.php）：公开页，?type=adminactivate|inviter|signup|sysop|confirmed|confirm
Route::get('/ok.php', [\App\Http\Controllers\ToolController::class, 'notification']);

// BB 代码预览（preview.php）：POST body=.. 返回 format_comment() HTML 片段（textbbcode 编辑器预览）
Route::post('/preview.php', [\App\Http\Controllers\PreviewController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 赠魔（magic.php）：登录用户 POST id+value 给种子加魔力值，返回 JSON
Route::post('/magic.php', [\App\Http\Controllers\RewardController::class, 'web'])
    ->middleware('auth.nexus:nexus');


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
// Offer 管理（offers.php）—— 替代 public/offers.php
// GET: 列表/详情/投票列表/编辑表单/删除确认；POST: new_offer/allow_offer/finish_offer/take_off_edit
// =============================================================
Route::match(['get', 'post'], '/offers.php', [\App\Http\Controllers\OfferController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 求种管理（viewrequests.php）—— 替代 public/viewrequests.php
// GET/POST: list / view / new / newmessage / edit / takeedit / takeadded /
// res / takeres / addamount / delete / confirm / message / search
// =============================================================
Route::match(['get', 'post'], '/viewrequests.php', [\App\Http\Controllers\RequestController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 举报 / 举报处理 / 投诉 / 建议（Phase 5）—— 替代 public/report.php、
// public/reports.php + public/takeupdate.php、public/complains.php、public/suggest.php
// =============================================================
// 举报提交/确认页（GET 显示确认表单，POST 提交举报）
Route::match(['get', 'post'], '/report.php', [\App\Http\Controllers\ReportController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 举报处理（admin）：GET 列出举报，POST 批量标记已处理/删除（含 legacy takeupdate.php）
Route::match(['get', 'post'], '/reports.php', [\App\Http\Controllers\ReportsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 投诉（公开页，禁用用户可通过邮箱创建投诉；admin 管理；内部校验登录状态）
Route::match(['get', 'post'], '/complains.php', [\App\Http\Controllers\ComplainController::class, 'web']);

// 种子名联想（公开文本接口，suggest 表按关键词计数排序，供 js/suggest.js 使用）
Route::get('/suggest.php', [\App\Http\Controllers\SuggestController::class, 'web']);

// =============================================================
// 积分中心（Phase 2 P1）—— mybonus
// =============================================================
Route::get('/mybonus.php', [\App\Http\Controllers\BonusController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// bonus exchange submission (mirrors public/mybonus.php action=exchange)
Route::post('/mybonus.php', [\App\Http\Controllers\BonusController::class, 'webExchange'])
    ->middleware('auth.nexus:nexus');

// =============================================================
// 积分日志 / 捐赠（Phase 2 P2/P3）—— bonus-log / donate / donated
// =============================================================
// user bonus log (mirrors public/bonus-log.php)
Route::get('/bonus-log.php', [\App\Http\Controllers\BonusLogController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// donation page (public, mirrors public/donate.php)
Route::get('/donate.php', [\App\Http\Controllers\DonationController::class, 'web']);

// donated amount update form + submit (SYSOP, mirrors public/donated.php)
Route::match(['get', 'post'], '/donated.php', [\App\Http\Controllers\DonationController::class, 'webDonated'])
    ->middleware('auth.nexus:nexus');

// donor list (admin, mirrors public/donorlist.php)
Route::get('/donorlist.php', [\App\Http\Controllers\DonorListController::class, 'web'])
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
// 消息系统（Phase 7 消息收尾）—— deletemessage / staffmess / takestaffmess /
// staffbox / staffpanel / contactstaff / massmail
// =============================================================
// single-message inbox/sentbox deletion (GET, mirrors public/deletemessage.php)
Route::get('/deletemessage.php', [\App\Http\Controllers\DeleteMessageController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// mass-PM form + submission (GET/POST, mirrors public/staffmess.php / takestaffmess.php)
Route::get('/staffmess.php', [\App\Http\Controllers\StaffMessController::class, 'web'])
    ->middleware('auth.nexus:nexus');
Route::post('/takestaffmess.php', [\App\Http\Controllers\StaffMessController::class, 'webTake'])
    ->middleware('auth.nexus:nexus');
// GET /takestaffmess.php mirrors the legacy stderr() 403 (POST-only page)
Route::get('/takestaffmess.php', [\App\Http\Controllers\StaffMessController::class, 'webTake'])
    ->middleware('auth.nexus:nexus');

// staff inbox: list / view / answer / delete / mark answered (GET+POST, mirrors public/staffbox.php)
Route::match(['get', 'post'], '/staffbox.php', [\App\Http\Controllers\StaffBoxController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// staff control panel (GET, mirrors public/staffpanel.php)
Route::get('/staffpanel.php', [\App\Http\Controllers\StaffPanelController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// contact-staff compose form (GET, mirrors public/contactstaff.php; posts to takecontact.php)
Route::get('/contactstaff.php', [\App\Http\Controllers\ContactStaffController::class, 'web'])
    ->middleware('auth.nexus:nexus');
// contact-staff submission (POST, mirrors public/takecontact.php); GET mirrors the legacy stderr() 400
Route::match(['get', 'post'], '/takecontact.php', [\App\Http\Controllers\ContactStaffController::class, 'webTakeContact'])
    ->middleware('auth.nexus:nexus');

// mass e-mail gateway (GET/POST, mirrors public/massmail.php)
Route::match(['get', 'post'], '/massmail.php', [\App\Http\Controllers\MassMailController::class, 'web'])
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

// 版块管理（forums）—— GET: list / del / edit / new forum; POST: addforum / editforum
Route::match(['get', 'post'], '/forummanage.php', [\App\Http\Controllers\ForumManageController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 版主操作（modtask）—— POST: confirmuser / edituser（userdetails.php / unco.php 表单提交）
Route::match(['get', 'post'], '/modtask.php', [\App\Http\Controllers\ModTaskController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// BB 标签帮助页（tags.php）—— 公开页，POST test 表单回传自身（镜像 public/tags.php）
Route::match(['get', 'post'], '/tags.php', [\App\Http\Controllers\TagController::class, 'web']);

// 站点规则页（rules.php）—— 公开页，按语言渲染 rules 表（镜像 public/rules.php）
Route::get('/rules.php', [\App\Http\Controllers\RulesController::class, 'web']);

// FAQ 页（faq.php）—— 公开页，按语言渲染 faq 表（镜像 public/faq.php）
Route::get('/faq.php', [\App\Http\Controllers\FaqController::class, 'web']);

// 用户协议页（useragreement.php）—— 公开静态页（镜像 public/useragreement.php）
Route::get('/useragreement.php', [\App\Http\Controllers\UserAgreementController::class, 'web']);

// 关于页（aboutnexus.php）—— 公开页，版本/翻译/样式表/联系方式（镜像 public/aboutnexus.php）
Route::get('/aboutnexus.php', [\App\Http\Controllers\AboutNexusController::class, 'web']);

// 管理团队页（staff.php）—— 需 staffmem 权限（镜像 public/staff.php）
Route::get('/staff.php', [\App\Http\Controllers\StaffController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 新闻管理（news.php）—— 需 newsmanage 权限，action 分发 add/edit/delete（镜像 public/news.php）
Route::match(['get', 'post'], '/news.php', [\App\Http\Controllers\NewsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 下载文件格式 / 视频格式帮助页（formats.php / videoformats.php）—— 需登录
// （镜像 public/formats.php / public/videoformats.php，静态指南页）
Route::get('/formats.php', [\App\Http\Controllers\FormatsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

Route::get('/videoformats.php', [\App\Http\Controllers\FormatsController::class, 'video'])
    ->middleware('auth.nexus:nexus');

// 表情列表 / 表情扩展（smilies.php / moresmilies.php）—— 需登录
// （镜像 public/smilies.php / public/moresmilies.php）
Route::get('/smilies.php', [\App\Http\Controllers\SmiliesController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// moresmilies.php 为弹窗页（winop() 通过 window.open 打开），需登录 + 非 parked
Route::get('/moresmilies.php', [\App\Http\Controllers\SmiliesController::class, 'more'])
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

// IMDb / PT-Gen info backfill (updateextinfo, mirrors public/retriver.php)
Route::get('/retriver.php', [\App\Http\Controllers\RetriverController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// user details page
Route::get('/userdetails.php', [\App\Http\Controllers\UserController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// invitation centre (invite.php) — invitee/sent/tmp lists + ?type=new invite form
// (the invite form posts to takeinvite.php; "Confirm Users" posts to takeconfirm.php)
Route::match(['get', 'post'], '/invite.php', [\App\Http\Controllers\InviteController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// invite submission (mirrors public/takeinvite.php)
Route::post('/takeinvite.php', [\App\Http\Controllers\InviteController::class, 'webTakeInvite'])
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

// bulk upload credit grant (staff) — mirrors public/takeamountupload.php
Route::post('/takeamountupload.php', [\App\Http\Controllers\TorrentController::class, 'webTakeAmountUpload'])
    ->middleware('auth.nexus:nexus');

// fast torrent deletion (staff) — mirrors public/fastdelete.php
Route::get('/fastdelete.php', [\App\Http\Controllers\TorrentController::class, 'webFastDelete'])
    ->middleware('auth.nexus:nexus');

// ghost-peer cleanup (owner or staff) — mirrors public/takeflush.php
Route::get('/takeflush.php', [\App\Http\Controllers\FlushController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// reseed request — PMs users who completed the torrent (mirrors public/takereseed.php)
Route::get('/takereseed.php', [\App\Http\Controllers\ReseedController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// torrent deletion (owner or staff) — mirrors public/delete.php
Route::post('/delete.php', [\App\Http\Controllers\TorrentController::class, 'webDelete'])
    ->middleware('auth.nexus:nexus');

// BitBucket attachment image log (admin) — mirrors public/bitbucketlog.php
Route::get('/bitbucketlog.php', [\App\Http\Controllers\BitbucketController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// attachment upload iframe + file download — mirrors public/attachment.php / public/getattachment.php
Route::match(['get', 'post'], '/attachment.php', [\App\Http\Controllers\AttachmentController::class, 'webUpload'])
    ->middleware('auth.nexus:nexus');

Route::get('/getattachment.php', [\App\Http\Controllers\AttachmentController::class, 'webDownload'])
    ->middleware('auth.nexus:nexus');

// site-wide freeleech switcher (admin) — mirrors public/freeleech.php
Route::match(['get', 'post'], '/freeleech.php', [\App\Http\Controllers\FreeleechController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// category management (admin) — mirrors public/catmanage.php, now handled by Filament Section resources
Route::get('/catmanage.php', fn () => redirect()->route('filament.admin.resources.section.categories.index'))
    ->name('catmanage.index');

// advertisement management (admin) — mirrors public/admanage.php, now handled by Filament AdvertisementResource
Route::get('/admanage.php', fn () => redirect()->route('filament.admin.resources.system.advertisements.index'))
    ->name('admanage.index');

// FAQ management (admin) — mirrors public/faqmanage.php, now handled by Filament FaqResource
Route::get('/faqmanage.php', fn () => redirect()->route('filament.admin.resources.system.faqs.index'))
    ->name('faqmanage.index');

// FAQ management actions — mirrors public/faqactions.php (reorder/edit/delete/add),
// all covered by the Filament FaqResource, so the legacy entry point redirects there.
Route::match(['get', 'post'], '/faqactions.php', fn () => redirect()->route('filament.admin.resources.system.faqs.index'))
    ->name('faqactions.index');

// link management — mirrors public/linksmanage.php
// (?action=apply + POST newapply = link-exchange application flow; plain GET = admin,
// redirected to Filament LinksResource)
Route::match(['get', 'post'], '/linksmanage.php', [\App\Http\Controllers\LinksController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// cheat analysis — mirrors public/cheaters.php, now handled by Filament CheaterResource
Route::get('/cheaters.php', fn () => redirect()->route('filament.admin.resources.system.cheaters.stats'))
    ->name('cheaters.stats');

// cheater suspect box — mirrors public/cheaterbox.php, now handled by Filament CheaterResource
Route::match(['get', 'post'], '/cheaterbox.php', fn () => redirect()->route('filament.admin.resources.system.cheaters.index'))
    ->name('cheaterbox.index');

// IP ban management — mirrors public/bans.php, now handled by Filament BansResource
Route::match(['get', 'post'], '/bans.php', fn () => redirect()->route('filament.admin.resources.system.bans.index'))
    ->name('bans.index');

// location management (SYSOP) — mirrors public/location.php, now handled by Filament LocationResource
Route::match(['get', 'post'], '/location.php', fn () => redirect()->route('filament.admin.resources.system.locations.index'))
    ->name('locations.index');

// =============================================================
// IP 工具（第 11 节 P2/P3）—— ipsearch / ipcheck / iphistory / testip
// =============================================================
// IP 历史搜索（userprofile 权限，镜像 public/ipsearch.php）
Route::get('/ipsearch.php', [\App\Http\Controllers\IpSearchController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 重复 IP 用户（MODERATOR+，镜像 public/ipcheck.php）
Route::get('/ipcheck.php', [\App\Http\Controllers\IpCheckController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 单用户 IP 历史（userprofile 权限，镜像 public/iphistory.php）
Route::get('/iphistory.php', [\App\Http\Controllers\IpHistoryController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// IP 封禁测试（MODERATOR+，镜像 public/testip.php）
Route::match(['get', 'post'], '/testip.php', [\App\Http\Controllers\TestIpController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// site log (daily log / chronicle / funbox / news / previous polls) — mirrors public/log.php
Route::match(['get', 'post'], '/log.php', [\App\Http\Controllers\LogController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// warned users list + warning removal (admin) — mirrors public/warned.php / public/nowarn.php
Route::get('/warned.php', [\App\Http\Controllers\WarnedController::class, 'web'])
    ->middleware('auth.nexus:nexus');

Route::post('/nowarn.php', [\App\Http\Controllers\NowarnController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// unconfirmed users (admin) — mirrors public/unco.php
Route::get('/unco.php', [\App\Http\Controllers\UncoController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// task list (claimable tasks) — mirrors public/task.php
Route::get('/task.php', [\App\Http\Controllers\TaskController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// site statistics (moderator+, mirrors public/stats.php)
Route::get('/stats.php', [\App\Http\Controllers\StatsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// MySQL server status (SYSOP, mirrors public/mysql_stats.php)
Route::get('/mysql_stats.php', [\App\Http\Controllers\MysqlStatsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// uploader statistics (uploader+, mirrors public/uploaders.php)
Route::get('/uploaders.php', [\App\Http\Controllers\UploadersController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// cache clearing (moderator+, mirrors public/clearcache.php)
Route::match(['get', 'post'], '/clearcache.php', [\App\Http\Controllers\ClearCacheController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// SMTP mail test (SYSOP, mirrors public/mailtest.php)
Route::match(['get', 'post'], '/mailtest.php', [\App\Http\Controllers\MailTestController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// website settings (SYSOP) — mirrors public/settings.php (menu + sub-forms + save)
Route::match(['get', 'post'], '/settings.php', [\App\Http\Controllers\SettingsController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// banned email management — mirrors public/bannedemails.php, now handled by Filament BannedEmailsResource
Route::match(['get', 'post'], '/bannedemails.php', fn () => redirect()->route('filament.admin.resources.system.banned-emails.index'))
    ->name('bannedemails.index');

// allowed email management — mirrors public/allowedemails.php, now handled by Filament AllowedEmailsResource
Route::match(['get', 'post'], '/allowedemails.php', fn () => redirect()->route('filament.admin.resources.system.allowed-emails.index'))
    ->name('allowedemails.index');

// add user (admin) — mirrors public/adduser.php, now handled by Filament UserResource create page
Route::match(['get', 'post'], '/adduser.php', fn () => redirect()->route('filament.admin.resources.user.users.create'))
    ->name('adduser.index');

// delete account (admin) — mirrors public/delacctadmin.php, now handled by Filament UserResource
Route::match(['get', 'post'], '/delacctadmin.php', fn () => redirect()->route('filament.admin.resources.user.users.index'))
    ->name('delacctadmin.index');

// delete disabled accounts (SYSOP) — mirrors public/deletedisabled.php, now handled by Filament UserResource
Route::match(['get', 'post'], '/deletedisabled.php', fn () => redirect()->route('filament.admin.resources.user.users.index'))
    ->name('deletedisabled.index');

// user list (admin) — mirrors public/users.php, now handled by Filament UserResource
Route::match(['get', 'post'], '/users.php', fn () => redirect()->route('filament.admin.resources.user.users.index'))
    ->name('users.index');

// bulk bonus/upload/etc increment (admin) — mirrors public/increment-bulk.php and
// public/take-increment-bulk.php, now handled by the UserResource bulk action
Route::match(['get', 'post'], '/increment-bulk.php', fn () => redirect()->route('filament.admin.resources.user.users.index'))
    ->name('increment-bulk.index');
Route::match(['get', 'post'], '/take-increment-bulk.php', fn () => redirect()->route('filament.admin.resources.user.users.index'))
    ->name('take-increment-bulk.index');

// user ban log (admin) — mirrors public/user-ban-log.php, now handled by Filament UserBanLogResource
Route::match(['get', 'post'], '/user-ban-log.php', fn () => redirect()->route('filament.admin.resources.system.user-ban-logs.index'))
    ->name('user-ban-log.index');

// rules management (admin) — mirrors public/modrules.php, now handled by Filament RuleResource
Route::match(['get', 'post'], '/modrules.php', fn () => redirect()->route('filament.admin.resources.system.rules.index'))
    ->name('modrules.index');

// custom fields management (admin) — mirrors public/fields.php, now handled by Filament TorrentCustomFieldResource
Route::match(['get', 'post'], '/fields.php', fn () => redirect()->route('filament.admin.resources.torrent-custom-fields.index'))
    ->name('fields.index');

// all clients (admin) — mirrors public/allagents.php, now handled by Filament AgentAllowResource/AgentDenyResource
Route::match(['get', 'post'], '/allagents.php', fn () => redirect()->route('filament.admin.resources.system.agent-allows.index'))
    ->name('allagents.index');

// torrent file download — mirrors public/download.php
// (?downhash= / ?passkey= are anonymous for RSS clients; ?id= requires a session,
// checked internally so the route stays reachable without the auth.nexus middleware)
Route::get('/download.php', [\App\Http\Controllers\DownloadController::class, 'web']);

// pre-download notice gate — mirrors public/downloadnotice.php
Route::match(['get', 'post'], '/downloadnotice.php', [\App\Http\Controllers\DownloadNoticeController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// subtitle management (upload form + paginated list, delete confirm/exec) — mirrors public/subtitles.php
Route::match(['get', 'post'], '/subtitles.php', [\App\Http\Controllers\SubtitleController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// promotion link page + click tracking — mirrors public/promotionlink.php
// (the ?key= click path is public so guests can visit a shared promotion link)
Route::get('/promotionlink.php', [\App\Http\Controllers\PromotionLinkController::class, 'web']);

// personal userbar image — mirrors public/mybar.php (?userid=ID.png)
Route::get('/mybar.php', [\App\Http\Controllers\MyBarController::class, 'web']);

// external-forum userbar image — mirrors public/cc98bar.php (options in the path)
Route::get('/cc98bar.php/{tail?}', [\App\Http\Controllers\Cc98BarController::class, 'web'])
    ->where('tail', '.*');

// =============================================================
// 广告跳转 / 字幕下载 / 验证码图片 / 动态页面（收尾清理）
// 替代 public/adredir.php / public/downloadsubs.php / public/image.php / public/page.php
// =============================================================
// 广告点击跳转 + 点击记录/魔力奖励（adredir.php）
Route::get('/adredir.php', [\App\Http\Controllers\AdRedirController::class, 'web'])
    ->middleware('auth.nexus:nexus');

// 字幕文件下载（downloadsubs.php）—— 内部处理登录态，guest 重定向到首页（legacy 行为）
Route::get('/downloadsubs.php', [\App\Http\Controllers\DownloadSubsController::class, 'web']);

// 验证码图片输出（image.php?action=regimage）
Route::get('/image.php', [\App\Http\Controllers\ImageController::class, 'web']);

// 动态页面 / 插件视图加载（page.php?view=..&plugin=..）
Route::get('/page.php', [\App\Http\Controllers\PageController::class, 'web']);

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
