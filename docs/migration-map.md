# NexusPHP 迁移路线图（Legacy → Laravel）

> 目标：将 `public/*.php` 遗留脚本逐步迁移到 Laravel（Controller + Route + Blade / Filament）。
> 策略：Strangler Fig（绞杀者），一次一个页面，双轨长期共存。
> 状态图例：`✅ 已完成` / `🔄 进行中` / `⬜ 未开始` / `🔒 保留legacy` / `🗑 可下线`

---

## 0. 迁移总览

| 统计项 | 数量 |
| --- | --- |
| 遗留页面总数 (`public/*.php`) | 150 |
| 已完成迁移 | 119 |
| 保留 legacy（tracker/特殊脚本） | 15 |
| 待迁移页面 | 16 |
| 已有 Filament 资源覆盖（admin） | 约 50 个 Resource |
| 已有 Repository | 37 个 |
| 已有 Controller | 50 个（多数仅 API，路由未启用） |

**完成判定标准（DoD）：**
1. 页面逻辑已由 Controller 方法承载，路由注册于 `routes/web.php` 或 `routes/admin.php`
2. 数据库访问使用 Eloquent Model / Repository，不再使用 `sql_query()` / `mysql_*`
3. 登录鉴权使用 `auth.nexus` 中间件 + `Auth::id()`，不再依赖 `$CURUSER` / `loggedinorreturn()`
4. 视图为 Blade（或 Filament），不再使用 `stdhead()` / `stderr()` / `stdtail()`
5. 遗留 `public/*.php` 文件已删除，请求经 `/nexus.php`（Laravel）路由处理
6. 配套 `tests/Feature/` 测试通过

**迁移切换机制**：nginx 中 `location ~ \.php$` 优先于 Laravel 前端控制器，
故切换动作 = **删除/重命名遗留 `.php` 文件**，让 `try_files` 落到 `/nexus.php`。

---

## 1. 认证与会话（Auth）

> Phase 1 优先完成，其余批次依赖此处打通的 `auth.nexus` 中间件。

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| login.php | 139 | ✅ | P0 | `AuthenticateController::showLoginForm()` + Blade `auth/login`，登录尝试跟踪 |
| takelogin.php | 117 | ✅ | P0 | `AuthenticateController::webLogin()`，挑战响应/验证码/失败锁定 |
| logout.php | 8 | ✅ | P0 | `AuthenticateController::webLogout()` |
| signup.php | 134 | ✅ | P1 | `showSignupForm()` + Blade `auth/signup`，联动 `get_setting('main.*')` 规则 |
| takesignup.php | 263 | ✅ | P1 | `AuthenticateController::signup()`，`main.enableschool` / `main.verification` |
| confirm.php | 48 | ✅ | P2 | `AuthenticateController::confirm()`，无效参数 404 |
| takeconfirm.php | 48 | ✅ | P2 | `AuthenticateController::confirmUser()`（POST，CSRF 豁免） |
| confirm_resend.php | 128 | ✅ | P2 | `showConfirmResendForm()` / `resendConfirmation()` + Blade `auth/confirm_resend` |
| confirmemail.php | 35 | ✅ | P2 | `confirmEmailChange()`，路由 `/confirmemail.php/{id}/{md5}/{email}` |
| recover.php | 152 | ✅ | P1 | `showRecoverForm()` / `recover()` + Blade `auth/recover` |
| reset.php | 64 | ✅ | P1 | `showResetForm()` / `reset()` + Blade `auth/reset` |
| self-enable.php | 62 | ✅ | P2 | `showSelfEnable()` / `selfEnable()` + Blade `auth/self-enable`（auth.nexus:nexus） |
| checkuser.php | 62 | ✅ | P2 | `showCheckUser()` + Blade `auth/checkuser`（auth.nexus:nexus-web） |
| maxlogin.php | 165 | ✅ | P3 | `showMaxLogin()` + Blade `auth/maxlogin`（auth.nexus:nexus-web） |

## 2. 首页与主列表（高流量只读）

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| index.php | 666 | ✅ | P0 | 首页：新闻、轮播、统计。`IndexController::show()` 渲染 Blade `index`，`vote()` 处理投票 POST；根路由 `/` 与 `/index.php` 直接渲染 |
| details.php | 759 | ✅ | P0 | 种子详情。`TorrentController::web()` 迁移遗留 `public/details.php`，渲染 Blade `torrent/details`（下载/魔力值/感谢/字幕/描述/评论等整页），路由 `/details.php` |
| torrents.php | 1344 | ✅ | P0 | 种子列表/搜索/特殊区。`TorrentController::browse()` 迁移遗留 `public/torrents.php`，渲染 Blade `torrent/browse`（搜索/分类/子类筛选、排序、分页、热门搜索），内含 `torrenttable()` 兼容层与 `UC_*` 常量引导；路由 `/torrents.php` 与 `/special.php`（`section=special`） |
| userdetails.php | 685 | ✅ | P1 | 用户详情。`UserController::web()` 迁移遗留 `public/userdetails.php`，渲染 Blade `user/details`（个人信息/分享率/H&R/Claim/魔力值等整页，含管理组编辑框），路由 `/userdetails.php` |
| topten.php | 767 | ✅ | P2 | `ToptenController` 排行榜（type 1/2/3/5/6），数据缓存 60 分钟 |
| usersearch.php | 859 | ✅ | P2 | `UserSearchController` 管理组用户搜索（>=MODERATOR），筛选闭包参数化 |
| userhistory.php | 263 | ✅ | P2 | `UserHistoryController` 帖子/评论历史，`viewhistory` 权限 |
| viewsnatches.php | 67 | ✅ | P3 | 做种记录，`SnatchController::web()` + Blade `viewsnatches`，Eloquent 分页 |
| viewpeerlist.php | 239 | ✅ | P3 | Peer 列表片段，`PeerController::web()` 移植 `dltable()`/`get_location_column()`，text/xml |
| viewfilelist.php | 26 | ✅ | P3 | 文件列表片段，`FileController::web()`，text/xml |
| viewnfo.php | 88 | ✅ | P3 | `ViewNfoController::show()` NFO 查看（magic/latin-1/fonthack），`code_new()` + `format_urls()` |
| torrent_info.php | 99 | 🔒 | 保留 | tracker 结构信息，保留 legacy 或转 API |

## 3. RSS / 搜索 / Ajax

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| getrss.php | 388 | ✅ | P2 | RSS 订阅表单，`RssController::index()` + Blade `getrss` |
| torrentrss.php | 299 | ✅ | P2 | `RssController::feed()` passkey 门控 RSS 2.0 XML，`whereIn` 参数化 |
| search.php | 163 | ✅ | P2 | `SearchController::index()` 搜索（Meili 或 Eloquent）|
| searchsuggest.php | 19 | ✅ | P3 | `SearchSuggestController::index()` 搜索联想 JSON（`suggest` 表按关键词计数排序，最多 5 条、>25 字跳过），OpenSearch suggestions 数据源，无鉴权 |
| ajax.php | 243 | ✅ | P2 | `AjaxController::web()` 通用 ajax 分发，action 级登录校验 |
| getusertorrentlistajax.php | 363 | ✅ | P2 | `GetUserTorrentListAjaxController::web()` 用户种子列表（上传/做种/下载），权限分级 |
| getextinfoajax.php | 27 | ✅ | P3 | `ExtInfoAjaxController::show()` IMDb 工具提示 XML 片段，`parse_imdb_id()` + 复用 `getimdb()`，按 imdb id + mode 缓存 1 天 |
| opensearch.php | 57 | ✅ | P3 | `OpenSearchController::index()` OpenSearch 1.1 描述 XML，站点设置驱动，缓存 1 天 |
| page.php | 29 | 🔒 | 保留 | 动态页面（可能被插件使用） |

## 4. 种子操作（写）

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| upload.php | 258 | ✅ | P1 | 上传表单。`UploadController::web()` 迁移遗留 `public/upload.php`，渲染 Blade `upload/upload`（上传表单/分类/质量/描述/Offer/Pick 等整页），路由 `/upload.php` |
| takeupload.php | 532 | ✅ | P1 | 上传提交，`UploadController::webTakeUpload()` 迁移遗留 `public/takeupload.php`，POST 路由 `/takeupload.php`（`auth.nexus`），保留 legacy 表单字段（`*_sel[mode]`）、existing→`existed=1` 重定向与 offer 完成流程；`TakeUploadPageTest` 覆盖 |
| takeamountupload.php | 41 | ✅ | P3 | 批量加量上传。`TorrentController::webTakeAmountUpload()` 迁移遗留 `public/takeamountupload.php`（POST `clases[]`+`amount`(GB)+`msg`+`subject`+`sender`，SYSOP 门槛、类校验、`User::increment('uploaded')` 批量加量 + `Message::add()` 逐人 PM，成功重定向 `amountupload.php?sent=1`），路由 `/takeamountupload.php`（POST，auth.nexus，CSRF 豁免），遗留文件已删；`TakeAmountUploadPageTest` 覆盖 |
| edit.php | 331 | ✅ | P1 | 编辑种子表单。`TorrentController::webEdit()` 迁移遗留 `public/edit.php`，渲染 Blade `torrent/edit`（名称/描述/分类/质量/自定义字段/HR/标签/Pick/删除区等整页），路由 `/edit.php` |
| takeedit.php | 312 | ✅ | P1 | 编辑提交，`TorrentController::webTakeEdit()` 迁移遗留 `public/takeedit.php`，POST 路由 `/takeedit.php`（`auth.nexus`），保留 legacy 表单字段（`*_sel[mode]`/`tags[mode]`/`hr[mode]`/`custom_fields[mode]`），`torrent_updated` 事件 + 操作日志；`TakeEditPageTest` 覆盖 |
| takeflush.php | 29 | ⬜ | P3 | 清空 peer |
| fastdelete.php | 67 | ✅ | P2 | 快速删除（admin）。`TorrentController::webFastDelete()` 迁移遗留 `public/fastdelete.php`（`id`+`sure=1` 确认页、ES 删除、`deletetorrent()`、上传者魔力值扣除、操作日志、PM 通知上传者，成功重定向 `torrents.php`），路由 `/fastdelete.php`（GET，auth.nexus），遗留文件已删；`FastDeletePageTest` 覆盖 |
| delete.php | 97 | ✅ | P2 | 删除种子。`TorrentController::webDelete()` 迁移遗留 `public/delete.php`（POST `id`+`reasontype`+`reason[]`、`torrent-delete` 权限门、ES 删除、`deletetorrent()`、带删除理由的站点日志、上传者魔力值扣除、PM 通知上传者、删除成功页），路由 `/delete.php`（POST，auth.nexus，CSRF 豁免），遗留文件已删；`DeletePageTest` 覆盖 |
| download.php | 212 | 🔒 | 保留 | 种子下载，保留 legacy 或转专有 Route（涉及 Passkey 校验） |
| downloadnotice.php | 161 | ✅ | P3 | 下载须知。`DownloadNoticeController::web()` 迁移遗留 `public/downloadnotice.php`（GET 渲染 firsttime/client/ratio 须知页面，POST `id`+`type`+`hidenotice` 更新 `users.showdlnotice`/`showclienterror` 后重定向 `download.php?id=..&letdown=1`），路由 `/downloadnotice.php`（GET+POST，auth.nexus），遗留文件已删；`DownloadNoticePageTest` 覆盖 |
| downloadsubs.php | 63 | 🔒 | 保留 | 字幕下载 |
| getattachment.php | 57 | 🔒 | 保留 | 附件下载，`AttachmentController` 可承接 |
| attachment.php | 291 | 🔒 | 保留 | 附件展示 |
| bitbucket-upload.php | 93 | 🔒 | 保留 | 附件上传 |
| bitbucketlog.php | 53 | ✅ | P3 | 附件记录。`BitbucketController::web()` 迁移遗留 `public/bitbucketlog.php`（管理员查看附件图片列表、分页、`?delete=ID` 删除行+文件），路由 `/bitbucketlog.php`（GET，auth.nexus），遗留文件已删；`BitbucketLogPageTest` 覆盖 |
| torrentrss/getrss | — | — | — | （见 RSS 节） |
| freeleech.php | 49 | ✅ | P3 | 全站免种开关。`FreeleechController::web()` 迁移遗留 `public/freeleech.php`（action 分发 `setallfree`/`setall2up`/`setall2up_free`/`setallhalf_down`/`setall2up_half_down`/`setallnormal`，`TorrentState::query()->update()` + `flushCache()`），路由 `/freeleech.php`（GET+POST，auth.nexus，CSRF 豁免），遗留文件已删；`FreeleechPageTest` 覆盖 |

## 5. 评论 / 收藏 / 感谢 / 签到（轻量互动）

> 此批 Repository / Controller 已基本齐全，优先做示范批次。

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| comment.php | 309 | ✅ | P0 | `CommentController::web()` 分发 add/edit/delete/vieworiginal + Blade `comment` |
| bookmark.php | 31 | ✅ | P0 | `BookmarkController::toggle()` 纯文本 added/deleted/failed |
| thanks.php | 26 | ✅ | P0 | `ThankController::sayThanks()`，复用 `createThanks()` 事务 |
| attendance.php | 191 | ✅ | P0 | `AttendanceController::showPage()/attendPage()` + Blade `attendance`（fullcalendar） |
| claim.php | 177 | ✅ | P1 | `ClaimController::index()` + Blade `claim`，sort/order + torrent_id/uid 双入口 |
| medal.php | 148 | ✅ | P1 | `MedalController::showPage()` + Blade `medal`，沿用 ajax.php buyMedal/giftMedal |
| myhr.php | 131 | ✅ | P1 | `HitAndRunController::showPage()` + Blade `myhr`，沿用 ajax.php removeHitAndRun |

## 6. 积分 / 捐赠 / 等级

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| mybonus.php | 823 | ✅ | P1 | 积分中心。`BonusController::web()` 迁移遗留 `public/mybonus.php`，渲染 Blade `mybonus`（兑换表 + 积分说明），`webExchange()` 处理 POST 兑换（上传/下载/VIP/邀请/临时邀请/自定义头衔/免广告/慈善/赠礼/消H&R/补签卡/彩虹ID/改名卡），复用 `BonusRepository` + `NexusLock` 防重；路由 `/mybonus.php`（CSRF 豁免），遗留文件已删 |
| bonus-log.php | 110 | ✅ | P2 | 积分日志。`BonusLogController::web()` 网格化展示（uid/category/business_type 过滤 + 分页，查看他人需 viewhistory），`BonusLogs` Eloquent，路由 `/bonus-log.php`（auth.nexus），遗留文件已删；回归 `BonusLogPageTest` |
| donate.php | 106 | ✅ | P2 | 捐赠（PayPal/Alipay/自定义文案，公开页）。`DonationController::web()` + Blade `donate`，路由 `/donate.php`，遗留文件已删 |
| donated.php | 31 | ✅ | P3 | 捐赠提交（SYSOP）。`DonationController::webDonated()` 更新 `users.donated` + Blade `donated`，路由 `/donated.php`（CSRF 豁免，auth.nexus），遗留文件已删；回归 `DonationPageTest` |
| donorlist.php | 43 | ✅ | P3 | 捐赠榜（管理员+）。`DonorListController::web()` 分页列出 `donor='yes'` 用户 + Blade `donorlist`，路由 `/donorlist.php`（auth.nexus），遗留文件已删；回归 `DonorListPageTest` |
| promotionlink.php | 71 | ✅ | P3 | 推广链接。`PromotionLinkController::web()` 迁移遗留 `public/promotionlink.php`（`?key=` 点击奖励防重复、`updatekey`/缺 key 重新生成、推广页 XHTML/HTML/BBCode 片段渲染，公开点击路径 + `?key=` 场景 `Auth::shouldUse('nexus')` 兼容 legacy 权限助手），路由 `/promotionlink.php`，遗留文件已删；`PromotionLinkPageTest` 覆盖 |
| mybar.php | 109 | ✅ | P3 | 签名档。`MyBarController::web()` 迁移遗留 `public/mybar.php`（`?userid=ID.png` 路径校验、GD 绘制用户名/上传/下载 + `noname`/`noup`/`nodown` 与颜色/字号/坐标覆盖、类/`strong` 隐私门、300s 缓存），路由 `/mybar.php`，遗留文件已删；`MyBarPageTest` 覆盖 |
| cc98bar.php | 129 | ✅ | P3 | 外站签名档。`Cc98BarController::web()` 迁移遗留 `public/cc98bar.php`（路径编码选项解析 `nn..ny`/`ur..uy`/`dr..dy`/`bg` + `id.ID.png`，共用 `RendersUserBar` trait 渲染与缓存），路由 `/cc98bar.php/{tail?}`，遗留文件已删；`Cc98BarPageTest` 覆盖 |

## 7. 消息系统

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| messages.php | 725 | ✅ | P1 | 站内信。`MessageController::web()` 迁移遗留 `public/messages.php`，渲染 Blade `messages`（收件箱/发件箱/自定义邮箱列表、搜索、分页、转发、邮箱管理），`Pmbox` + `Message` Eloquent 模型，路由 `/messages.php`（GET/POST，CSRF 豁免），遗留文件已删 |
| takemessage.php | 192 | ✅ | P1 | 发送消息。`MessageController::webTakeMessage()` 迁移遗留 `public/takemessage.php`（普通发送/转发/回复时删除原消息、限流、接收方 acceptpms/parked 检查、通知邮件），接收方检查用 `blocks`/`friends` 数据表查询，路由 `/takemessage.php`（POST，CSRF 豁免），遗留文件已删 |
| sendmessage.php | 60 | ✅ | P2 | 发送短讯页。`MessageController::webSendMessage()` 迁移遗留 `public/sendmessage.php`（新建/回复 compose 表单：receiver/replyto 校验、Re:/Re(n): 主题续接、删除原消息/保存发件箱复选，复用 `begin_compose`/`textbbcode` 编辑器），渲染 Blade `sendmessage`，路由 `/sendmessage.php`（GET，auth.nexus），遗留文件已删；`SendMessagePageTest` 覆盖 |
| deletemessage.php | 43 | ✅ | P3 | 删除消息。`DeleteMessageController::web()` 迁移遗留 `public/deletemessage.php`（GET `?id=`+`type=in/sent` 删除单封站内信，归属校验、`ignorepm` 关系不受影响），路由 `/deletemessage.php`（GET，auth.nexus），遗留文件已删；`DeleteMessagePageTest` 覆盖 |
| staffmess.php | 72 | ✅ | P2 | 管理组消息。`StaffMessController::web()` 迁移遗留 `public/staffmess.php`（管理员多选用户类群发 PM 表单，`?sent=1` 成功提示，`form_role_filter` 插件钩子），路由 `/staffmess.php`（GET，auth.nexus），遗留文件已删；`StaffMessPageTest` 覆盖 |
| takestaffmess.php | 57 | ✅ | P2 | 发管理组消息。`StaffMessController::webTake()` 迁移遗留 `public/takestaffmess.php`（POST `classes[]`+`subject`+`msg`+`sender`，管理员门槛、类校验、`role_query_conditions` 过滤 + `chunkById` 分批插入 `messages` 并更新 `last_pm`，成功重定向 `staffmess.php?sent=1`），路由 `/takestaffmess.php`（POST，auth.nexus；GET 镜像 legacy 403），遗留文件已删；`StaffMessPageTest` 覆盖 |
| staffbox.php | 275 | ✅ | P2 | 管理信箱。`StaffBoxController::web()` 迁移遗留 `public/staffbox.php`（action 分发 list/viewpm/answermessage/takeanswer/deletestaffmessage/setanswered/takecontactanswered，`user_can('staffmem')`+permission 门、回答后回 PM 并标记 answered、批量标记/删除），渲染 Blade `staffbox`，路由 `/staffbox.php`（GET+POST，auth.nexus），遗留文件已删；`StaffBoxPageTest` 覆盖 |
| staffpanel.php | 86 | ✅ | P3 | 管理面板。`StaffPanelController::web()` 迁移遗留 `public/staffpanel.php`（MODERATOR 门槛）按类分级输出站点管理链接快捷入口），渲染 Blade `staffpanel`，路由 `/staffpanel.php`（GET，auth.nexus），遗留文件已删；`StaffPanelPageTest` 覆盖 |
| contactstaff.php | 14 | ✅ | P3 | 联系管理组。`ContactStaffController::web()` 迁移遗留 `public/contactstaff.php`（联系表单页，提交走 legacy `takecontact.php`，渲染时校验收件人），渲染 Blade `contactstaff`，路由 `/contactstaff.php`（GET，auth.nexus），遗留文件已删；`ContactStaffPageTest` 覆盖 |
| massmail.php | 81 | ✅ | P2 | 群发邮件（admin）。`MassMailController::web()` 迁移遗留 `public/massmail.php`（SYSOP 门槛、`or` 比较符（`<`/`>`/`=`/`<=`/`>=`）+`class` 筛选用户、subject 截断为 80 字符并前缀 `Fw: `、逐用户 `sent_mail()` 群发，成功/失败结果页），路由 `/massmail.php`（GET+POST，auth.nexus），遗留文件已删；`MassMailPageTest` 覆盖 |

## 8. 论坛

> 论坛是单体最大的页面群（forums.php 1645 行），建议整体独立迁移。

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| forums.php | 1645 | ✅ | P1 | 论坛首页/版块。`ForumController::web()` 迁移遗留 `public/forums.php`（portal/viewforum/viewtopic/viewunread/search/newtopic/reply/quote/edit/post、setsticky/setlocked/hltopic、movetopic/deletetopic/deletepost、catchup），渲染 Blade `forums`，路由 `/forums.php`（GET+POST，CSRF 豁免），遗留文件已删 |
| moforums.php | 215 | ✅ | P1 | 论坛分区管理。`OverForumController::web()` 迁移遗留 `public/moforums.php`（分区列表/新增/编辑/删除），渲染 Blade `moforums`，路由 `/moforums.php`（GET+POST，CSRF 豁免），遗留文件已删；`OverForumPageTest` 覆盖 |
| forummanage.php | 302 | ✅ | P2 | 版块管理。`ForumManageController::web()` 迁移遗留 `public/forummanage.php`（版块列表/新增/编辑/删除，删除连带 topics/posts/forummods、moderator 逗号分隔最多 3 人），路由 `/forummanage.php`（GET+POST，CSRF 豁免），遗留文件已删；`ForumManagePageTest` 覆盖 |
| modtask.php | 496 | ✅ | P2 | 版主操作。`ModTaskController::web()` 迁移遗留 `public/modtask.php`（confirmuser 确认/取消确认待激活账号、edituser 应用 userdetails 表单的标题/头像/签名/隐私/警告/上传下载发帖权限/捐赠等修改），路由 `/modtask.php`（POST，CSRF 豁免），遗留文件已删；`ModTaskPageTest` 覆盖 |
| makepoll.php | 177 | ✅ | P2 | 建投票。`PollController::webMakePoll()` 迁移遗留 `public/makepoll.php`（GET 渲染新建/编辑表单，新建且距上次发布 <3 天时给出提醒；POST 校验必填 question/option0/option1、创建或更新投票并清 `current_poll_content`/`current_poll_result` 缓存、按 `returnto` 重定向），渲染 Blade `makepoll`，路由 `/makepoll.php`（GET+POST，auth.nexus），遗留文件已删；`PollPageTest` 覆盖 |
| polloverview.php | 80 | ✅ | P3 | 投票结果。`PollController::webOverview()` 迁移遗留 `public/polloverview.php`（无 `?id=` 列出全部投票；有 `?id=` 显示详情：概况行 + 选项表 + 按 username 排序的分页用户投票），渲染 Blade `polloverview`（复用 `partials.pagination`），路由 `/polloverview.php`（GET，auth.nexus），遗留文件已删；`PollPageTest` 覆盖 |
| shoutbox.php | 147 | ✅ | P3 | 聊天室。`ShoutboxController::web()` 迁移遗留 `public/shoutbox.php`（iframe 片段，`type=shoutbox|helpbox` 渲染消息列表，`?sent=yes&shbox_text=..` 发言：helpbox 游客可发、shoutbox 需登录，NexusLock 60s 限频；`?del=ID` 删除消息（sbmanage）；`sbnum`/`sbrefresh`/`hidehb` 用户偏好），渲染 Blade `shoutbox`，路由 `/shoutbox.php`（GET），遗留文件已删；`ShoutboxPageTest` 覆盖 |
| friends.php | 356 | ✅ | P2 | 好友/屏蔽列表。`FriendsController::web()` 迁移遗留 `public/friends.php`（action 分发 add/delete，add 查重、delete `sure` 确认页、`friends`/`blocks` 表增删、清理 neighbors 缓存，主页面渲染好友列表（头像/类名/最后活动/移除/发 PM）+ 屏蔽用户列表 + `viewuserlist` 链接），渲染 Blade `friends`，路由 `/friends.php`（GET+POST，auth.nexus），遗留文件已删；`FriendsPageTest` 覆盖 |
| tags.php | 303 | ✅ | P2 | BB 标签帮助页。`TagController::web()` 迁移遗留 `public/tags.php`（公开页，无鉴权；BB 标签 syntax/example/result 表格 + "Test this code" 表单 POST 预览，`format_comment()` 渲染结果），渲染 Blade `tags`，路由 `/tags.php`（GET+POST，CSRF 豁免），遗留文件已删；`TagPageTest` 覆盖 |
| fun.php | 304 | ✅ | P3 | 趣味页面。`FunController::web()` 迁移遗留 `public/fun.php`（action 分发 `view`/`new`/`add`/`edit`/`delete`/`ban`/`vote`；new/add 距上篇 <24h 拦截，edit/delete/ban 需 `funmanage`（delete 额外管理员），ban 附理由并 PM 原作者，vote 去重 + 满 20 票按比率置 notfunny/dull/funny/veryfunny、25/50/100/200 票给作者积分奖励 + PM），渲染 Blade `fun`/`fun_form`/`fun_page`，路由 `/fun.php`（GET+POST，auth.nexus），遗留文件已删；`FunPageTest` 覆盖 |

## 9. 请求 / 求种 / Offer

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| offers.php | 905 | ✅ | P2 | Offer 管理。`OfferController::web()` 迁移遗留 `public/offers.php`（列表/搜索/排序/详情/评论/投票/增删改/allow-finish，Eloquent 查询），渲染 Blade `offer/index`、`offer/details`、`offer/message`，路由 `/offers.php`（GET+POST，auth.nexus），遗留文件已删；`OfferPageTest` 覆盖 |
| viewrequests.php | 498 | ✅ | P2 | 求种列表。`RequestController::web()` 迁移遗留 `public/viewrequests.php`（action 分发 `list`/`view`/`new`/`newmessage`/`edit`/`takeedit`/`takeadded`/`res`/`takeres`/`addamount`/`delete`/`confirm`/`message`/`search`，`finished` 过滤 yes/no/all/ing/my + `query` 搜索 + 分页、供种 `resreq`、完成确认派发魔力值/PM、评论留言），渲染 Blade `request/index`、`request/details`、`request/message`，路由 `/viewrequests.php`（GET+POST，auth.nexus），遗留文件已删；`RequestPageTest` 覆盖 |
| takeupload（请求相关） | — | — | — | 见种子操作 |
| suggest.php | 27 | ✅ | P3 | 种子名联想。`SuggestController::web()` 迁移遗留 `public/suggest.php`（公开文本接口，`suggest` 表按关键词计数排序、>25 字跳过、最多 5 条，返回 `keyword\r\ncount` 文本，供 `js/suggest.js` 搜索框自动补全），路由 `/suggest.php`，遗留文件已删；`SuggestPageTest` 覆盖 |

## 10. 举报 / 投诉 / 申诉

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| report.php | 237 | ✅ | P2 | 举报。`ReportController::web()` 迁移遗留 `public/report.php`（GET 显示确认表单，POST 提交举报，支持 torrent/user/offer/request/post/comment/subtitle 类型，去重检查、缓存失效，Elquent 查询），路由 `/report.php`（GET+POST，auth.nexus），遗留文件已删；`ReportPageTest` 覆盖 |
| reports.php | 154 | ✅ | P2 | 举报处理（admin）。`ReportsController::web()` 迁移遗留 `public/reports.php`（分页列表，批量标记已处理/删除，合并 `public/takeupdate.php` 逻辑），路由 `/reports.php`（GET+POST，auth.nexus），遗留文件已删；`ReportsPageTest` 覆盖 |
| complains.php | 181 | ✅ | P2 | 投诉。`ComplainController::web()` 迁移遗留 `public/complains.php`（公开页，禁用用户可创建投诉；admin 管理列表/查看/回复/关闭；内部校验登录状态），路由 `/complains.php`（GET+POST），遗留文件已删；`ComplainsPageTest` 覆盖 |

## 11. 管理后台（→ Filament）

> 以下页面迁移目标为 Filament Resource。部分已有对应 Resource，无对应则新建。

| 页面 | 行数 | 状态 | 优先级 | 已有 Filament 对应 |
| --- | --- | --- | --- | --- |
| catmanage.php | 836 | ✅ | P1 | `Section\CategoryResource` ✅（Section/Icon/SecondIcon/Source/Media/Codec/Standard/Processing/Team/AudioCodec Resource 全覆盖，`/catmanage.php` 重定向到 Filament，遗留文件已删；`CatManagePageTest` 覆盖） |
| admanage.php | 427 | ✅ | P2 | `Advertisement`（`System\AdvertisementResource` ✅，列表/创建/编辑 + 类型化参数 + `code` 自动生成，`/admanage.php` 重定向到 Filament，遗留文件已删；`AdManagePageTest` 覆盖） |
| faqmanage.php | 118 | ✅ | P2 | `FaqResource`（`System\FaqResource` ✅，categ/item 增删改 + 自动 link_id/order + `faq` 缓存清理，`/faqmanage.php` 重定向到 Filament，遗留文件已删；`FaqManagePageTest` 覆盖） |
| forummanage.php | 302 | ✅ | P2 | `ForumManageController`（已落地） |
| linksmanage.php | 167 | ✅ | P2 | `LinksResource`（新建，`System\LinksResource` ✅ 列表/创建/编辑 + 删除 + `index_links` 缓存清理，`/linksmanage.php` 无 action 重定向到 Filament；`?action=apply`/POST `newapply` 保留用户申请友链流程：`LinksController::web()` + Blade `links/apply`，校验并写入 `staffmessages`，遗留文件已删；`LinksManagePageTest` 覆盖） |
| medals.php → medal.php | 148 | ⬜ | P1 | `MedalResource` ✅ |
| tags.php | 303 | ✅ | P1 | `TagResource` ✅ |
| cheaters.php | 111 | ✅ | P2 | `Cheater`（需新建）Filament Resource：作弊值统计页（`CheatStats`）+ 路由 `/cheaters.php` 重定向 |
| cheaterbox.php | 84 | ✅ | P3 | 同上（`CheaterResource` 列表：`ListCheaters`，批量 set-dealt/delete），路由 `/cheaterbox.php` 重定向；`CheaterPageTest` 覆盖 |
| bans.php | 71 | ✅ | P2 | `BansResource`（新建）Filament Resource：列表/新增/编辑 IP 封禁，路由 `/bans.php` 重定向；`BansPageTest` 覆盖 |
| bannedemails.php | 29 | ✅ | P3 | `BannedEmailsResource`（新建）Filament Resource：单条记录管理封禁邮箱地址（`value` 文本域，空格分隔），路由 `/bannedemails.php` 重定向；`BannedEmailsPageTest` 覆盖 |
| allowedemails.php | 32 | ✅ | P3 | `AllowedEmailsResource`（新建）Filament Resource：单条记录管理允许邮箱地址（`value` 文本域，空格分隔），路由 `/allowedemails.php` 重定向；`AllowedEmailsPageTest` 覆盖 |
| adduser.php | 70 | ✅ | P2 | `UserResource` 创建页，`/adduser.php` 重定向到 Filament `user.users.create`，遗留文件已删；`AddUserPageTest` 覆盖 |
| delacctadmin.php | 36 | ✅ | P3 | 用户禁用/删除，并入 `UserResource`，`/delacctadmin.php` 重定向到 Filament `user.users.index`，遗留文件已删；`DelAcctAdminPageTest` 覆盖 |
| deletedisabled.php | 45 | ✅ | P3 | 同上，`/deletedisabled.php` 重定向到 Filament `user.users.index`，遗留文件已删；`DeleteDisabledPageTest` 覆盖 |
| users.php | 144 | ✅ | P1 | `UserResource` ✅，`/users.php` 重定向到 Filament `user.users.index`，遗留文件已删；`UsersPageTest` 覆盖 |
| user-ban-log.php | 37 | ✅ | P2 | `UserBanLogResource`（新建，`System\UserBanLogResource` ✅ uid/username/operator/reason 列表 + UID 筛选，`/user-ban-log.php` 重定向到 Filament，遗留文件已删；`UserBanLogPageTest` 覆盖） |
| modrules.php | 107 | ✅ | P3 | 版规管理。`RuleResource`（新建，`System\RuleResource` ✅ 列表/创建/编辑/删除 + 按语言筛选 + 增删改自动清 `rules` 缓存，`/modrules.php` 重定向到 Filament，遗留文件已删；`ModRulesPageTest` 覆盖） |
| fields.php | 57 | ✅ | P3 | 自定义字段。`TorrentCustomFieldResource` ✅（列表/创建/编辑/删除，补齐 Create/Edit 页，`/fields.php` 重定向到 Filament，遗留文件已删；`FieldsPageTest` 覆盖） |
| formats.php | 215 | ✅ | P3 | 下载文件格式帮助页（静态指南）。`FormatsController::web()` 迁移遗留 `public/formats.php`（Compression/Multimedia/CD Image/Other Files 分节说明），渲染 Blade `formats`（`layouts.guest`），路由 `/formats.php`（auth.nexus），遗留文件已删；`FormatsPageTest` 覆盖 |
| videoformats.php | 204 | ✅ | P3 | 视频格式/发布类型帮助页（静态指南）。`FormatsController::video()` 迁移遗留 `public/videoformats.php`（CAM/TS/TC/SCR/DVDRip 等 + Scene Tags 说明），渲染 Blade `videoformats`（`layouts.guest`），路由 `/videoformats.php`（auth.nexus），遗留文件已删；`FormatsPageTest` 覆盖 |
| allagents.php | 16 | ✅ | P3 | `AgentAllowResource` / `AgentDenyResource` ✅，路由 `/allagents.php` 重定向到 Filament `agent-allows`，遗留文件已删；`AllAgentsPageTest` 覆盖 |
| ipsearch.php | 170 | ✅ | P2 | IP 历史搜索。`IpSearchController::web()` 迁移遗留 `public/ipsearch.php`（`userprofile` 权限门、`?ip=` 精确/CIDR/掩码匹配 `users.ip` 与 `iplog` UNION 分页、order 排序、IP Nums 汇总），路由 `/ipsearch.php`（GET，auth.nexus），遗留文件已删；`IpSearchPageTest` 覆盖 |
| ipcheck.php | 97 | ✅ | P3 | 重复 IP 用户。`IpCheckController::web()` 迁移遗留 `public/ipcheck.php`（MODERATOR 门槛、按 `enabled='yes'` 用户 `GROUP BY ip` 列出 dupl>1 的 IP 及用户/邮箱/注册/最后访问/流量/分享率/Peer 数），路由 `/ipcheck.php`（GET，auth.nexus），遗留文件已删；`IpCheckPageTest` 覆盖 |
| iphistory.php | 90 | ✅ | P3 | IP 历史。`IpHistoryController::web()` 迁移遗留 `public/iphistory.php`（`userprofile` 权限门、`users.ip` + `iplog` 去重并按 access 倒序分页、hostname 反查、`?ip=` 跳转 ipsearch 并标记 Dupe），路由 `/iphistory.php`（GET，auth.nexus），遗留文件已删；`IpHistoryPageTest` 覆盖 |
| testip.php | 47 | ✅ | P3 | IP 封禁测试。`TestIpController::web()` 迁移遗留 `public/testip.php`（MODERATOR 门槛、POST/GET `?ip=` 经 `bans.first <= long(ip) <= last` 查禁段并展示），路由 `/testip.php`（GET+POST，auth.nexus），遗留文件已删；`TestIpPageTest` 覆盖 |
| location.php | 247 | ✅ | P3 | `LocationResource`（新建，`System\LocationResource` ✅ 地区 CRUD：名称/主副地区/起止 IP/理论实际上下行速率/旗帜图片，路由 `/location.php` 重定向到 Filament，遗留文件已删；`LocationPageTest` 覆盖） |
| nowarn.php | 53 | ✅ | P3 | 撤销警告。`NowarnController::web()` 迁移遗留 `public/nowarn.php`（POST `nowarned`+`usernw[]`/`desact[]`，MODERATOR 门槛，空选择提示，移除警告 / 禁用账号后重定向 `warned.php`），路由 `/nowarn.php`（POST，auth.nexus，CSRF 豁免），遗留文件已删；`NowarnPageTest` 覆盖 |
| warned.php | 68 | ✅ | P3 | 警告列表。`WarnedController::web()` 迁移遗留 `public/warned.php`（MODERATOR 门槛，`warned='yes'`+`enabled='yes'` 用户按分享率排序，移除警告/禁用账号复选框表单，ADMINISTRATOR+ 显示 Apply Changes 按钮），路由 `/warned.php`（GET，auth.nexus），遗留文件已删；`WarnedPageTest` 覆盖 |
| unco.php | 53 | ✅ | P3 | 未确认用户列表。`UncoController::web()` 迁移遗留 `public/unco.php`（MODERATOR 门槛，`status='pending'` 用户按用户名排序，逐行表单提交 `modtask.php` action=confirmuser，`?status=1` 更新提示），路由 `/unco.php`（GET，auth.nexus），遗留文件已删；`UncoPageTest` 覆盖 |
| log.php | 451 | ✅ | P2 | 站点日志。`LogController::web()` 迁移遗留 `public/log.php`（action 分发 `dailylog`/`chronicle`/`funbox`/`news`/`poll`：dailylog 按 `confilog` 权限过滤 security_level + 文本搜索 + 行染色，chronicle 增删改（chrmanage 门槛）、funbox/news 标题/正文搜索、poll 历史投票列表 + 删除确认；`SiteLog`/`Chronicle`/`Fun`/`News`/`Poll` Eloquent 查询 + `partials.pagination`），路由 `/log.php`（GET+POST，auth.nexus，CSRF 豁免），遗留文件已删；`LogPageTest` 覆盖 |
| stats.php | 122 | ✅ | P3 | 统计。`StatsController::web()` 迁移遗留 `public/stats.php`（MODERATOR 门槛、Uploader Activity（class=3 与 class>3 用户分组）+ Category Activity 两张表、`uporder`/`catorder` 排序、百分比列，Eloquent Query Builder + 旧版 helper 渲染），路由 `/stats.php`（GET，auth.nexus），遗留文件已删；`StatsPageTest` 覆盖 |
| mysql_stats.php | 370 | 🔒 | 保留 | MySQL 状态页，转 ops 工具 |
| clearcache.php | 32 | ✅ | P3 | 缓存清理。`ClearCacheController::web()` 迁移遗留 `public/clearcache.php`（MODERATOR 门槛、cache name + multilang 表单，POST 经 `$GLOBALS['Cache']->delete_value()` 清 legacy 缓存 + `Cache::forget()` 清 Laravel 缓存，空名报错），路由 `/clearcache.php`（GET+POST，auth.nexus），遗留文件已删；`ClearCachePageTest` 覆盖 |
| mailtest.php | 45 | ✅ | P3 | 邮件测试，`MailTestController::web()` + Blade `mailtest`，SYSOP 门槛，路由 `/mailtest.php`；遗留文件已删；`MailTestPageTest` 覆盖 |
| task.php | 116 | ✅ | P3 | 任务列表。`TaskController::web()` 迁移遗留 `public/task.php`（`Exam::TYPE_TASK`+`STATUS_ENABLED` 任务表，Eloquent 分页 + `withCount('onGoingUsers')` 已领人数/上限、当前用户进行中任务标记 "Already claimed" 禁用领取按钮，领取走 `ajax.php` action=claimTask），渲染 Blade `task`，路由 `/task.php`（GET，auth.nexus），遗留文件已删；`TaskPageTest` 覆盖 |
| take-increment-bulk.php | 80 | ✅ | P3 | 批量增减。并入 `UserResource` 批量操作（`change_bonus_etc`：uploaded/downloaded/invites/seedbonus/attendance_card/tmp_invites 增减、GB 换算、临时邀请 `duration`、原因说明，SYSOP 门槛），复用 `UserRepository::incrementDecrement()` / `addTemporaryInvite()`，路由 `/increment-bulk.php`、`/take-increment-bulk.php` 重定向到 Filament `user.users.index`，遗留文件已删；`IncrementBulkPageTest` 覆盖 |
| increment-bulk.php | 79 | ✅ | P3 | 同上 |
| uploaders.php | 126 | ✅ | P3 | 上传者统计。`UploadersController::web()` 迁移遗留 `public/uploaders.php`（UPLOADER+ 门槛、年/月选择 + 月度上传统计表：用户名/体积/数量/最后上传时间与种子，无上传者零行补充，username/torrent_size/torrent_count 排序），路由 `/uploaders.php`（GET，auth.nexus），遗留文件已删；`UploadersPageTest` 覆盖 |
| subtitles.php | 405 | ✅ | P2 | 字幕管理。`SubtitleController::web()` 迁移遗留 `public/subtitles.php`（上传表单 + 按 search/letter/lang_id 筛选分页列表、`action=upload` 文件上传、`?delete=` 确认/执行删除），`Sub` Eloquent 模型，路由 `/subtitles.php`（GET+POST，auth.nexus），遗留文件已删；`SubtitlesPageTest` 覆盖 |

## 12. 静态 / 信息页

| 页面 | 行数 | 状态 | 优先级 | 目标 / 备注 |
| --- | --- | --- | --- | --- |
| rules.php | 30 | ✅ | P1 | 规则。`RulesController::web()` 迁移遗留 `public/rules.php`（公开页，按 guest 语言渲染 `rules` 表，语言非 rule_lang 时回退 English id 6，`format_comment()` 渲染正文），路由 `/rules.php`，遗留文件已删；`RulesPageTest` 覆盖 |
| faq.php | 108 | ✅ | P1 | FAQ。`FaqController::web()` 迁移遗留 `public/faq.php`（公开页，欢迎语 + 目录 + 分类/条目，`Faq` Eloquent 模型按 lang_id 查询），路由 `/faq.php`，遗留文件已删；`FaqPageTest` 覆盖 |
| faqactions.php | 206 | ✅ | P2 | FAQ 管理（admin → Filament），`FaqResource` 已覆盖全部 CRUD，`/faqactions.php` 重定向到 Filament |
| useragreement.php | 101 | ✅ | P2 | 用户协议。`UserAgreementController::web()` 迁移遗留 `public/useragreement.php`（公开静态协议页，站点名/URL 插值），渲染 Blade `useragreement`（`layouts.guest`），路由 `/useragreement.php`，遗留文件已删；`UserAgreementPageTest` 覆盖 |
| aboutnexus.php | 65 | ✅ | P3 | 关于。`AboutNexusController::web()` 迁移遗留 `public/aboutnexus.php`（版本/关于/授权/翻译状态（language 表）/样式表（stylesheets 表）/联系方式），渲染 Blade `aboutnexus`（`layouts.guest`），路由 `/aboutnexus.php`，遗留文件已删；`AboutNexusPageTest` 覆盖 |
| staff.php | 216 | ✅ | P2 | 管理团队页。`StaffController::web()` 迁移遗留 `public/staff.php`（staffmem 权限门、一线支持/影评人/版主/管理组/VIP 分节，在线状态/国旗/PM 链接，Eloquent 查询 + 15 分钟缓存），渲染 Blade `staff`，路由 `/staff.php`，遗留文件已删；`StaffPageTest` 覆盖 |
| news.php | 130 | ✅ | P1 | 新闻管理。`NewsController::web()` 迁移遗留 `public/news.php`（newsmanage 权限门，action 分发 add/edit/delete + 默认提交表单，compose 编辑器，`news_created` 事件 + `recent_news` 缓存清理），路由 `/news.php`（GET+POST，auth.nexus），遗留文件已删；`NewsPageTest` 覆盖 |
| ok.php | 60 | ⬜ | P3 | 通用提示页，并入 Blade `error/notification` 视图 |
| preview.php | 9 | ⬜ | P3 | 预览 |
| magic.php | 40 | ⬜ | P3 | 通用跳转 |
| special.php | 3 | ✅ | P3 | 特殊区占位页，改走 `TorrentController::browse()`（`/special.php` 路由 `section=special`），遗留文件已删 |
| smilies.php | 9 | ⬜ | P3 | 表情列表 |
| moresmilies.php | 45 | ⬜ | P3 | 表情扩展 |
| retriver.php | 69 | ⬜ | P3 | IMDb/信息回填（admin） |
| image.php | 22 | 🔒 | 保留 | 图片代理，保留 legacy 或转专用 Route |

## 13. Tracker / CLI / 特殊脚本（保留 legacy）

> 不建议迁移，风险高、收益低。仅保证与 Laravel 共存可用。

| 页面 | 行数 | 说明 |
| --- | --- | --- |
| announce.php | 644 | Tracker 核心，走独立 nginx 规则 + Lua 过滤 |
| scrape.php | 73 | Tracker scrape |
| cron.php | 13 | 定时入口（CLI） |
| docleanup.php | 30 | 清理任务（CLI），可迁移为 Laravel Command |
| email-gateway.php | 68 | 邮件网关 |
| adredir.php | 25 | 广告跳转 |
| torrent_info.php | 99 | 结构信息 |

---

## 14. 迁移执行顺序（建议）

| 阶段 | 内容 | 预估 |
| --- | --- | --- |
| Phase 1 | 认证打通（第 1 节）+ 基础 Blade layout 替代 `stdhead/stdtail` | 1-2 周 |
| Phase 2 | 轻量互动示范批（第 5 节：comment/bookmark/thanks/attendance/claim/medal/myhr） | 1-2 周 |
| Phase 3 | 高流量只读（第 2 节：index/details/torrents + RSS） | 2-3 周 |
| Phase 4 | 种子操作 + 消息系统（第 4、7 节） | 2-3 周 |
| Phase 5 | 论坛群 + 请求/举报（第 8、9、10 节） | 3-4 周 |
| Phase 6 | 管理后台补完（第 11 节剩余 Filament Resource） | 2-3 周 |
| Phase 7 | 静态页 + 收尾清理（第 12 节 + 下线残留 `include/` 函数） | 1-2 周 |

**每阶段完成后**：删除对应 `public/*.php` → 验证路由接管 → 跑 `php artisan test` 全量回归。

---

## 15. 配套任务清单

- [ ] 建立 `docs/migration-map.md` 对应 issue 看板，逐页勾选
- [ ] 打通 `auth.nexus` 中间件在 Blade 视图的 `Auth::user()` 用法
- [ ] 编写 Blade 基础 layout（header/nav/footer/`stderr` 等价物）
- [ ] 迁移 `lang/`：Blade 读取现有 `lang_*.php` 或逐步换 `__()`
- [ ] 为每批迁移补 `tests/Feature/` 测试
- [ ] 最后移除 `include/` 中已无引用的全局函数（`loggedinorreturn`、`stdhead` 等）
