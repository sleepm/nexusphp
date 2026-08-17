<?php

namespace App\Http\Controllers;

use App\Http\Resources\ForumResource;
use App\Models\Forum;
use App\Models\Message;
use App\Models\OverForum;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ForumController extends Controller
{
    /**
     * Legacy page settings derived from the current user & site configuration.
     */
    protected int $maxSubjectLength = 100;

    protected int $postsPerPage = 10;

    protected int $topicsPerPage = 20;

    protected string $todayDate = '';

    /**
     * Web entry point. Mirrors the legacy public/forums.php action dispatch so
     * the whole forum is served by the Laravel router.
     */
    public function web(Request $request)
    {
        [$curUser, $lang] = $this->bootstrap($request);

        // ---- per-page globals computed once per request ----
        $postsPerPage = (int) ($curUser['postsperpage'] ?? 0);
        if (!$postsPerPage) {
            $postsPerPage = (int) get_setting('main.postsperpage', 10);
        }
        if (!$postsPerPage) {
            $postsPerPage = 10;
        }
        $this->postsPerPage = $postsPerPage;

        $topicsPerPage = (int) ($curUser['topicsperpage'] ?? 0);
        if (!$topicsPerPage) {
            $topicsPerPage = (int) get_setting('main.topicsperpage', 20);
        }
        if (!$topicsPerPage) {
            $topicsPerPage = 20;
        }
        $this->topicsPerPage = $topicsPerPage;

        $this->todayDate = date('Y-m-d', TIMENOW);

        $action = htmlspecialchars(trim((string) ($request->query('action', $request->input('action', '')))));

        switch ($action) {
            case 'newtopic':
                return $this->webNewTopic($request, $curUser, $lang);
            case 'quotepost':
                return $this->webQuotePost($request, $curUser, $lang);
            case 'reply':
                return $this->webReply($request, $curUser, $lang);
            case 'editpost':
                return $this->webEditPost($request, $curUser, $lang);
            case 'post':
                return $this->webPost($request, $curUser, $lang);
            case 'viewtopic':
                return $this->webViewTopic($request, $curUser, $lang);
            case 'movetopic':
                return $this->webMoveTopic($request, $curUser, $lang);
            case 'deletetopic':
                return $this->webDeleteTopic($request, $curUser, $lang);
            case 'deletepost':
                return $this->webDeletePost($request, $curUser, $lang);
            case 'setlocked':
                return $this->webSetLocked($request, $curUser, $lang);
            case 'hltopic':
                return $this->webHlTopic($request, $curUser, $lang);
            case 'setsticky':
                return $this->webSetSticky($request, $curUser, $lang);
            case 'viewforum':
                return $this->webViewForum($request, $curUser, $lang);
            case 'viewunread':
                return $this->webViewUnread($request, $curUser, $lang);
            case 'search':
                return $this->webSearch($request, $curUser, $lang);
            default:
                // `catchup` must run before the unknown-action check (legacy order)
                if ($request->query('catchup') == 1) {
                    $this->catchUp($curUser);
                }
                if ($action !== '') {
                    abort(400, $lang['std_unknown_action'] ?? 'Unknown action');
                }
                return $this->webForumList($request, $curUser, $lang);
        }
    }

    /**
     * Authenticate, set the legacy globals used by the shared helpers and
     * return the current user array + forums lang file.
     */
    private function bootstrap(Request $request): array
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        // whether the internal forum is disabled in favour of an external one
        if (get_setting('main.extforum', 'no') == 'yes') {
            abort(403);
        }

        $lang = get_legacy_lang_file('forums');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_forums'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['bonus_tweak'] = get_setting('tweak.bonus', 'enable');
        if (empty($GLOBALS['Advertisement'])) {
            require_once ROOT_PATH . 'classes/class_advertisement.php';
            $GLOBALS['Advertisement'] = new \ADVERTISEMENT($curUser['id']);
        }

        return [$curUser, $lang];
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * New-topic compose frame (legacy action=newtopic).
     */
    private function webNewTopic(Request $request, array $curUser, array $lang)
    {
        $forumid = intval($request->query('forumid', 0));
        $this->checkWhetherExist($forumid, 'forum', $lang);

        $content = $this->capture(function () use ($forumid, $curUser, $lang) {
            begin_main_frame();
            $this->insertComposeFrame($forumid, 'new', $curUser, $lang);
            end_main_frame();
        });

        return view('forums', compact('content') + ['pageTitle' => $lang['head_new_topic'] ?? 'New Topic', 'lang' => $lang]);
    }

    /**
     * Quote-reply compose frame (legacy action=quotepost).
     */
    private function webQuotePost(Request $request, array $curUser, array $lang)
    {
        $postid = intval($request->query('postid', 0));
        $this->checkWhetherExist($postid, 'post', $lang);
        if (!can_view_post($curUser['id'], $postid)) {
            abort(403);
        }

        $content = $this->capture(function () use ($postid, $curUser, $lang) {
            begin_main_frame();
            $this->insertComposeFrame($postid, 'quote', $curUser, $lang);
            end_main_frame();
        });

        return view('forums', compact('content') + ['pageTitle' => $lang['head_post_reply'] ?? 'Reply', 'lang' => $lang]);
    }

    /**
     * Reply compose frame (legacy action=reply).
     */
    private function webReply(Request $request, array $curUser, array $lang)
    {
        $topicid = intval($request->query('topicid', 0));
        $this->checkWhetherExist($topicid, 'topic', $lang);

        $content = $this->capture(function () use ($topicid, $curUser, $lang) {
            begin_main_frame();
            $this->insertComposeFrame($topicid, 'reply', $curUser, $lang);
            end_main_frame();
        });

        return view('forums', compact('content') + ['pageTitle' => $lang['head_post_reply'] ?? 'Reply', 'lang' => $lang]);
    }

    /**
     * Edit-post compose frame (legacy action=editpost).
     */
    private function webEditPost(Request $request, array $curUser, array $lang)
    {
        $postid = intval($request->query('postid', 0));
        $this->checkWhetherExist($postid, 'post', $lang);

        $post = Post::query()->find($postid);
        $topic = Topic::query()->find($post->topicid);
        $locked = $topic->locked == 'yes';

        $ismod = is_forum_moderator($postid, 'post');
        if (($curUser['id'] != $post->userid || $locked) && !user_can('postmanage') && !$ismod) {
            abort(403);
        }

        $content = $this->capture(function () use ($postid, $curUser, $lang) {
            begin_main_frame();
            $this->insertComposeFrame($postid, 'edit', $curUser, $lang);
            end_main_frame();
        });

        return view('forums', compact('content') + ['pageTitle' => $lang['text_edit_post'] ?? 'Edit Post', 'lang' => $lang]);
    }

    /**
     * Post submission (legacy action=post): new topic / reply / edit.
     */
    private function webPost(Request $request, array $curUser, array $lang)
    {
        if (($curUser['forumpost'] ?? 'yes') == 'no') {
            abort(403, $lang['std_unauthorized_to_post'] ?? 'You do not have permission to post.');
        }

        $id = (string) $request->input('id', '');
        $type = (string) $request->input('type', '');
        $subject = (string) $request->input('subject', '');
        $body = trim((string) $request->input('body', ''));
        $hassubject = false;
        $topicid = 0;
        $forumid = 0;
        $quotepostid = 0;

        switch ($type) {
            case 'new':
                $this->checkWhetherExist($id, 'forum', $lang);
                $forumid = (int) $id;
                $hassubject = true;
                break;
            case 'reply':
                $this->checkWhetherExist($id, 'topic', $lang);
                $topicid = (int) $id;
                $forumid = (int) Topic::query()->whereKey($topicid)->value('forumid');
                $quotepostid = (int) $request->input('postid', 0);
                break;
            case 'edit':
                $this->checkWhetherExist($id, 'post', $lang);
                $post = Post::query()->find($id);
                $topicid = (int) $post->topicid;
                $forumid = (int) Topic::query()->whereKey($topicid)->value('forumid');
                $firstpost = (int) Post::query()->where('topicid', $topicid)->min('id');
                if ($firstpost == $id) {
                    $hassubject = true;
                }
                break;
            default:
                abort(400);
        }

        if ($hassubject) {
            $subject = trim($subject);
            if (!$subject) {
                abort(400, $lang['std_must_enter_subject'] ?? 'You must enter a subject.');
            }
            if (strlen($subject) > $this->maxSubjectLength) {
                abort(400, $lang['std_subject_limited'] ?? 'Subject is too long.');
            }
        }

        // make sure the user has write access in the forum
        $forums = $this->getForums();
        $forumRow = $forums[$forumid] ?? null;
        if (!$forumRow) {
            abort(400, $lang['std_bad_forum_id'] ?? 'Invalid forum id.');
        }
        if (
            get_user_class() < $forumRow['minclassread']
            || get_user_class() < $forumRow['minclasswrite']
            || ($type == 'new' && get_user_class() < $forumRow['minclasscreate'])
        ) {
            abort(403);
        }
        if ($body == '') {
            abort(400, $lang['std_no_body_text'] ?? 'You must enter a body text.');
        }

        $userid = intval($curUser['id'] ?? 0);
        $date = date('Y-m-d H:i:s');

        if ($type != 'new') {
            // make sure the topic is unlocked
            $locked = Topic::query()->whereKey($topicid)->value('locked');
            if ($locked == 'yes' && !user_can('postmanage') && !is_forum_moderator($topicid, 'topic')) {
                abort(400, $lang['std_topic_locked'] ?? 'The topic has been locked.');
            }
        }

        if ($type == 'edit') {
            $postid = (int) $id;
            $topicInfo = Topic::query()->findOrFail($topicid);
            $postInfo = Post::query()->findOrFail($id);
            if ($postInfo->userid != $curUser['id'] && !is_forum_moderator($postid, 'post') && !user_can('postmanage')) {
                abort(403);
            }
            if ($hassubject) {
                Topic::query()->whereKey($topicid)->update(['subject' => $subject]);
                $lastRepliedRow = $GLOBALS['Cache']->get_value('forum_' . $forumid . '_last_replied_topic_content');
                if (!empty($lastRepliedRow) && ($lastRepliedRow['id'] ?? null) == $topicid) {
                    $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_last_replied_topic_content');
                }
            }
            Post::query()->whereKey($id)->update(['body' => $body, 'editdate' => $date, 'editedby' => $curUser['id']]);
            $GLOBALS['Cache']->delete_value('post_' . $postid . '_content');

            // notify the original author about the edit
            $postUrl = sprintf('[url=forums.php?action=viewtopic&topicid=%s&page=p%s#pid%s]%s[/url]', $topicid, $postid, $postid, $topicInfo->subject);
            if (!empty($postInfo->userid) && $postInfo->userid != $curUser['id']) {
                $receiver = $postInfo->user;
                if ($receiver) {
                    $locale = $receiver->locale;
                    $notify = [
                        'sender' => 0,
                        'receiver' => $receiver->id,
                        'subject' => nexus_trans('forum.post.edited_notify_subject', [], $locale),
                        'msg' => nexus_trans('forum.post.edited_notify_body', ['topic_subject' => $postUrl, 'editor' => $curUser['username']], $locale),
                        'added' => now(),
                    ];
                    Message::add($notify);
                }
            }
        } else {
            // Anti-flood: no more than one post every 10 seconds
            if (!user_can('postmanage')) {
                if (strtotime((string) ($curUser['last_post'] ?? '')) > (TIMENOW - 10)) {
                    $secs = 10 - (TIMENOW - strtotime((string) ($curUser['last_post'] ?? '')));
                    abort(429, $lang['std_post_flooding'] . $secs . $lang['std_seconds_before_making']);
                }
            }

            if ($type == 'new') {
                // create topic + bonus
                KPS('+', get_setting('bonus.starttopic', 2), $userid);
                $topic = Topic::query()->create([
                    'userid' => $userid,
                    'forumid' => $forumid,
                    'subject' => $subject,
                ]);
                if (!$topic) {
                    abort(400, $lang['std_no_topic_id_returned'] ?? 'No topic id returned');
                }
                $topicid = $topic->id;
                Forum::query()->whereKey($forumid)->increment('topiccount');
                Forum::query()->whereKey($forumid)->increment('postcount');
            } else {
                // new post + bonus
                KPS('+', get_setting('bonus.makepost', 1), $userid);
                Forum::query()->whereKey($forumid)->increment('postcount');
            }

            $post = Post::query()->create([
                'topicid' => $topicid,
                'userid' => $userid,
                'added' => $date,
                'body' => $body,
                'ori_body' => $body,
            ]);
            if (!$post) {
                abort(400, $lang['std_post_id_not_available'] ?? 'Post id not available');
            }
            $postid = $post->id;

            $topicInfo = Topic::query()->findOrFail($topicid);
            $postUrl = sprintf('[url=forums.php?action=viewtopic&topicid=%s&page=p%s#pid%s]%s[/url]', $topicid, $postid, $postid, $topicInfo->subject);

            if ($type == 'reply') {
                // notify topic author
                if (!empty($topicInfo->userid) && $topicInfo->userid != $curUser['id']) {
                    $receiver = $topicInfo->user;
                    if ($receiver && $receiver->acceptNotification('topic_reply')) {
                        $locale = $receiver->locale;
                        Message::add([
                            'sender' => 0,
                            'receiver' => $receiver->id,
                            'subject' => nexus_trans('forum.topic.replied_notify_subject', [], $locale),
                            'msg' => nexus_trans('forum.topic.replied_notify_body', ['topic_subject' => $postUrl], $locale),
                            'added' => now(),
                        ]);
                    }
                }
                // notify quoted-post author when replying with a quote
                if (!empty($quotepostid)) {
                    $quotePostInfo = Post::query()->find($quotepostid);
                    if ($quotePostInfo && $quotePostInfo->userid != $curUser['id']) {
                        $receiver = $quotePostInfo->user;
                        if ($receiver && $receiver->acceptNotification('topic_reply')) {
                            $locale = $receiver->locale;
                            Message::add([
                                'sender' => 0,
                                'receiver' => $receiver->id,
                                'subject' => nexus_trans('forum.reply.replied_notify_subject', [], $locale),
                                'msg' => nexus_trans('forum.reply.replied_notify_body', ['topic_subject' => $postUrl, 'replyer' => $curUser['username']], $locale),
                                'added' => now(),
                            ]);
                        }
                    }
                }
            }

            $cache = $GLOBALS['Cache'];
            $cache->delete_value('forum_' . $forumid . '_post_' . $this->todayDate . '_count');
            $cache->delete_value('today_' . $this->todayDate . '_posts_count');
            $cache->delete_value('forum_' . $forumid . '_last_replied_topic_content');
            $cache->delete_value('topic_' . $topicid . '_post_count');
            $cache->delete_value('user_' . $userid . '_post_count');

            if ($type == 'new') {
                Topic::query()->whereKey($topicid)->update(['firstpost' => $postid, 'lastpost' => $postid]);
            } else {
                Topic::query()->whereKey($topicid)->update(['lastpost' => $postid]);
            }
            User::query()->whereKey($curUser['id'])->update(['last_post' => $date]);
        }

        // redirect the user to the new/edited post
        $url = 'forums.php?action=viewtopic&topicid=' . $topicid;
        if ($type == 'edit') {
            $url .= '&page=p' . $postid . '#pid' . $postid;
        } else {
            $url .= '&page=last#pid' . $postid;
        }
        return redirect($url);
    }

    /**
     * View a single topic with its posts (legacy action=viewtopic).
     */
    private function webViewTopic(Request $request, array $curUser, array $lang)
    {
        $highlight = htmlspecialchars(trim((string) $request->query('highlight', '')));

        $topicid = intval($request->query('topicid', 0));
        if (!is_valid_id($topicid)) {
            $this->invalidIdAbort();
        }
        $page = $request->query('page', 0);
        $authorid = intval($request->query('authorid', 0));

        if ($authorid) {
            $addparam = 'action=viewtopic&topicid=' . $topicid . '&authorid=' . $authorid;
        } else {
            $addparam = 'action=viewtopic&topicid=' . $topicid;
        }

        // get topic info
        $topic = Topic::query()->whereKey($topicid)->first();
        if (!$topic) {
            abort(400, $lang['std_topic_not_found'] ?? 'Topic not found.');
        }
        $arr = $topic->toArray();
        $forumid = $arr['forumid'];
        $lockedVal = $arr['locked'];
        $locked = $lockedVal == 'yes';
        $orgsubject = $arr['subject'];
        $subject = htmlspecialchars($arr['subject']);
        if ($highlight) {
            $subject = highlight($highlight, $orgsubject);
        }
        $sticky = $arr['sticky'] == 'yes';
        $hlcolor = $arr['hlcolor'];
        $views = $arr['views'];
        $base_posterid = $arr['userid'];

        $row = $this->getForums()[$forumid] ?? [];
        if (!$row) {
            abort(400, $lang['std_forum_not_found'] ?? 'Forum not found.');
        }
        $forumname = $row['name'];
        $is_forummod = is_forum_moderator($forumid, 'forum');

        if (get_user_class() < $row['minclassread']) {
            abort(400, $lang['std_unpermitted_viewing_topic'] ?? 'You are not permitted to view this topic.');
        }
        $maypost = false;
        if (((get_user_class() >= $row['minclasswrite'] && !$locked) || user_can('postmanage') || $is_forummod) && ($curUser['forumpost'] ?? 'yes') == 'yes') {
            $maypost = true;
        }

        // update hits column
        $topic->increment('views');

        // post count
        $postQuery = Post::query()->where('topicid', $topicid);
        if ($authorid) {
            $postQuery->where('userid', $authorid);
        }
        $postcount = (clone $postQuery)->count();
        if (!$authorid) {
            $GLOBALS['Cache']->cache_value('topic_' . $topicid . '_post_count', $postcount, 3600);
        }

        // page menu
        $perpage = $this->postsPerPage;
        $pages = (int) ceil($postcount / $perpage);

        if (isset($page[0]) && $page[0] == 'p') {
            $findpost = substr($page, 1);
            $ids = (clone $postQuery)->orderBy('added')->pluck('id')->toArray();
            $i = 0;
            foreach ($ids as $postIdRow) {
                if ($postIdRow == $findpost) {
                    break;
                }
                ++$i;
            }
            $page = floor($i / $perpage);
        }
        if ($page === 'last') {
            $page = $pages - 1;
        } elseif (isset($page)) {
            if ($page < 0) {
                $page = 0;
            } elseif ($page > $pages - 1) {
                $page = $pages - 1;
            }
        } else {
            $page = 0;
        }
        $page = (int) $page;

        $offset = $page * $perpage;
        $pagerarr = [];
        $dotted = 0;
        $dotspace = 3;
        $dotend = $pages - $dotspace;
        $curdotend = $page - $dotspace;
        $curdotstart = $page + $dotspace;
        for ($i = 0; $i < $pages; ++$i) {
            if (($i >= $dotspace && $i <= $curdotend) || ($i >= $curdotstart && $i < $dotend)) {
                if (!$dotted) {
                    $pagerarr[] = '...';
                }
                $dotted = 1;
                continue;
            }
            $dotted = 0;
            if ($i != $page) {
                $pagerarr[] = '<a href="' . htmlspecialchars('?' . $addparam . '&page=' . $i) . '"><b>' . ($i + 1) . '</b></a>' . "\n";
            } else {
                $pagerarr[] = '<font class="gray"><b>' . ($i + 1) . '</b></font>' . "\n";
            }
        }
        if ($page == 0) {
            $pager = '<font class="gray"><b>&lt;&lt;' . $lang['text_prev'] . '</b></font>';
        } else {
            $pager = '<a href="' . htmlspecialchars('?' . $addparam . '&page=' . ($page - 1)) . '"><b>&lt;&lt;' . $lang['text_prev'] . '</b></a>';
        }
        $pager .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        if ($page == $pages - 1) {
            $pager .= '<font class="gray"><b>' . $lang['text_next'] . ' &gt;&gt;</b></font>' . "\n";
        } else {
            $pager .= '<a href="' . htmlspecialchars('?' . $addparam . '&page=' . ($page + 1)) .
                '"><b>' . $lang['text_next'] . ' &gt;&gt;</b></a>' . "\n";
        }
        $pagerstr = implode(' | ', $pagerarr);
        $pagertop = '<p align="center">' . $pager . '<br />' . $pagerstr . "</p>\n";
        $pagerbottom = '<p align="center">' . $pagerstr . '<br />' . $pager . "</p>\n";

        $posts = (clone $postQuery)->orderBy('id')->offset($offset)->limit($perpage)->get();

        $siteName = $GLOBALS['SITENAME'];
        $content = $this->capture(function () use (
            $siteName, $lang, $forumid, $forumname, $subject, $locked, $views, $maypost, $topicid,
            $posts, $curUser, $authorid, $is_forummod, $sticky,
            $pages, $page, $pagertop, $pagerbottom, $offset, $highlight
        ) {
            print('<h1 align="center"><a class="faqlink" href="forums.php">' . $siteName . '&nbsp;' . $lang['text_forums'] . '</a>--><a class="faqlink" href="' . htmlspecialchars('?action=viewforum&forumid=' . $forumid) . '">' . $forumname . '</a><b>--></b><span id="top">' . $subject . ($locked ? '&nbsp;&nbsp;<b>[<font class="striking">' . $lang['text_locked'] . '</font>]</b>' : '') . '</span></h1>' . "\n");
            print($pagertop);

            begin_main_frame();
            print('<table border="0" class="main" cellspacing="0" cellpadding="5" width="97%"><tr>' . "\n");
            print('<td class="embedded" width="99%">&nbsp;&nbsp;' . $lang['there_is'] . '<b>' . $views . '</b>' . $lang['hits_on_this_topic']);
            print("</td>\n");
            print('<td class="embedded nowrap" width="1%" align="right">');
            if ($maypost) {
                print('<a href="' . htmlspecialchars('?action=reply&topicid=' . $topicid) . '"><img class="f_reply" src="pic/trans.gif" alt="Add Reply" title="' . $lang['title_reply_directly'] . '" /></a>&nbsp;&nbsp;');
            }
            print('</td>');
            print("</tr></table>\n");
            begin_frame();

            $allPosts = $posts->toArray();
            $uidArr = [];
            foreach ($allPosts as $postRow) {
                $uidArr[$postRow['userid']] = 1;
            }
            $uidArr = array_keys($uidArr);
            $neededColumns = ['id', 'noad', 'class', 'enabled', 'privacy', 'avatar', 'signature', 'uploaded', 'downloaded', 'last_access', 'username', 'donor', 'leechwarn', 'warned', 'title'];
            $userInfoArr = User::query()->find($uidArr, $neededColumns)->keyBy('id');
            $lpr = $this->getLastReadPostId($curUser, $topicid);

            // privacy-protected forum check (kept for parity with spam control hooks)
            $pc = count($allPosts);
            $pn = 0;
            foreach ($allPosts as $arr2) {
                if ($pn >= 1) {
                    // adm are echoed between posts
                }
                ++$pn;

                $postid = $arr2['id'];
                $posterid = $arr2['userid'];

                $added = gettime($arr2['added'], true, false);

                $userInfo = $userInfoArr->get($posterid) ?: User::defaultUser();
                $posterArr = $userInfo->toArray();

                $uploaded = mksize($posterArr['uploaded'] ?? 0);
                $downloaded = mksize($posterArr['downloaded'] ?? 0);
                $ratio = get_ratio($posterArr['id']);

                if (!$forumposts = $GLOBALS['Cache']->get_value('user_' . $posterid . '_post_count')) {
                    $forumposts = Post::query()->where('userid', $posterid)->count();
                    $GLOBALS['Cache']->cache_value('user_' . $posterid . '_post_count', $forumposts, 3600);
                }

                $signature = ($curUser['signatures'] ?? 'yes') == 'yes' ? $posterArr['signature'] : '';
                $avatar = ($curUser['avatars'] ?? 'yes') == 'yes' ? htmlspecialchars((string) ($posterArr['avatar'] ?? '')) : '';

                $uclass = get_user_class_image($posterArr['class']);
                $by = get_username($posterid, false, true, true, false, false, true);

                if (!$avatar) {
                    $avatar = 'pic/default_avatar.png';
                }

                if ($pn == $pc) {
                    print('<span id="last"></span>' . "\n");
                    if ($postid > $lpr) {
                        if ($lpr == ($curUser['last_catchup'] ?? 0)) {
                            DB::table('readposts')->insert([
                                'userid' => $curUser['id'],
                                'topicid' => $topicid,
                                'lastpostread' => $postid,
                            ]);
                        } elseif ($lpr > ($curUser['last_catchup'] ?? 0)) {
                            DB::table('readposts')->where('userid', $curUser['id'])->where('topicid', $topicid)->update(['lastpostread' => $postid]);
                        }
                        $GLOBALS['Cache']->delete_value('user_' . $curUser['id'] . '_last_read_post_list');
                    }
                }

                $postId = $postid;
                $topicId = $topicid;
                $addedStr = $added;
                $byStr = $by;
                $posterArr2 = $posterArr;
                print('<div style="margin-top: 8pt; margin-bottom: 8pt;"><table id="pid' . $postid . '" border="0" cellspacing="0" cellpadding="0" width="100%"><tr><td class="embedded" width="99%"><a href="' . htmlspecialchars('forums.php?action=viewtopic&topicid=' . $topicid . '&page=p' . $postid . '#pid' . $postid) . '">#' . $postid . '</a>&nbsp;&nbsp;<font color="gray">' . $lang['text_by'] . '</font>' . $by . '&nbsp;&nbsp;<font color="gray">' . $lang['text_at'] . '</font>' . $added);
                print('&nbsp;&nbsp;<font color="gray">|</font>&nbsp;&nbsp;');
                if ($authorid) {
                    print('<a href="?action=viewtopic&topicid=' . $topicid . '">' . $lang['text_view_all_posts'] . '</a>');
                } else {
                    print('<a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $topicid . '&authorid=' . $posterid) . '">' . $lang['text_view_this_author_only'] . '</a>');
                }
                print('</td><td class="embedded nowrap" width="1%"><font class="big">' . $lang['text_number'] . '<b>' . ($pn + $offset) . '</b>' . $lang['text_lou'] . '&nbsp;&nbsp;</font><a href="#top"><img class="top" src="pic/trans.gif" alt="Top" title="' . $lang['text_back_to_top'] . '" /></a>&nbsp;&nbsp;</td></tr>');
                print("</table></div>\n");

                print('<table class="main" width="100%" border="1" cellspacing="0" cellpadding="5">' . "\n");

                $body = '<div id="pid' . $postid . 'body" style="word-break: break-all;">';
                if ($pn + $offset > 1 && !can_view_post($curUser['id'], $arr2)) {
                    $bodyContent = format_comment($lang['text_post_protected']);
                } else {
                    $bodyContent = format_comment($arr2['body']);
                }
                if ($highlight) {
                    $bodyContent = highlight($highlight, $bodyContent);
                }
                if (is_valid_id($arr2['editedby'])) {
                    $lastedittime = gettime($arr2['editdate'], true, false);
                    $bodyContent .= '<br /><p><font class="small">' . $lang['text_last_edited_by'] . get_username($arr2['editedby']) . $lang['text_last_edit_at'] . $lastedittime . '</font></p>' . "\n";
                }
                $bodyContent = apply_filter('post_body', $bodyContent, $arr2, $allPosts);
                $body .= $bodyContent . '</div>';
                if ($signature) {
                    $body .= '<p style=\'vertical-align:bottom\'><br />____________________<br />' . format_comment($signature, false, false, false, true, 500, true, false, 1, 200) . '</p>';
                }

                $stats = '<br />' . '&nbsp;&nbsp;' . $lang['text_posts'] . $forumposts . '<br />' . '&nbsp;&nbsp;' . $lang['text_ul'] . $uploaded . ' <br />' . '&nbsp;&nbsp;' . $lang['text_dl'] . $downloaded . '<br />' . '&nbsp;&nbsp;' . $lang['text_ratio'] . $ratio;
                print('<tr><td class="rowfollow" width="150" valign="top" align="left" style=\'padding: 0px\'>' .
                    return_avatar_image($avatar) . '<br /><br /><br />&nbsp;&nbsp;<img alt="' . get_user_class_name($posterArr2['class'], false, false, true) . '" title="' . get_user_class_name($posterArr2['class'], false, false, true) . '" src="' . $uclass . '" />' . $stats . '</td><td class="rowfollow" valign="top"><br />' . $body . '</td></tr>' . "\n");
                $secs = 900;
                $dt = date('Y-m-d H:i:s', (TIMENOW - $secs));
                $lastAccess = (string) ($posterArr2['last_access'] ?? '');
                print('<tr><td class="rowfollow" align="center" valign="middle">' . ($lastAccess > $dt ? '<img class="f_online" src="pic/trans.gif" alt="Online" title="' . $lang['title_online'] . '" />' : '<img class="f_offline" src="pic/trans.gif" alt="Offline" title="' . $lang['title_offline'] . '" />') . '<a href="sendmessage.php?receiver=' . htmlspecialchars(trim($posterArr2['id'])) . '"><img class="f_pm" src="pic/trans.gif" alt="PM" title="' . $lang['title_send_message_to'] . htmlspecialchars($posterArr2['username']) . '" /></a><a href="report.php?forumpost=' . $postid . '"><img class="f_report" src="pic/trans.gif" alt="Report" title="' . $lang['title_report_this_post'] . '" /></a></td>');
                print('<td class="toolbox" align="right">');

                do_action('post_toolbox', $arr2, $allPosts, $curUser['id']);

                if ($maypost) {
                    print('<a href="' . htmlspecialchars('?action=quotepost&postid=' . $postid) . '"><img class="f_quote" src="pic/trans.gif" alt="Quote" title="' . $lang['title_reply_with_quote'] . '" /></a>');
                }
                if (user_can('postmanage') || $is_forummod) {
                    print('<a href="' . htmlspecialchars('?action=deletepost&postid=' . $postid) . '"><img class="f_delete" src="pic/trans.gif" alt="Delete" title="' . $lang['title_delete_post'] . '" /></a>');
                }
                if (($curUser['id'] == $posterid && !$locked) || user_can('postmanage') || $is_forummod) {
                    print('<a href="' . htmlspecialchars('?action=editpost&postid=' . $postid) . '"><img class="f_edit" src="pic/trans.gif" alt="Edit" title="' . $lang['title_edit_post'] . '" /></a>');
                }
                print('</td></tr></table>');
                unset($postId, $topicId, $addedStr, $byStr);
            }

            // mod options
            if (user_can('postmanage') || $is_forummod) {
                print('</td></tr><tr><td class="toolbox" align="center">' . "\n");
                print('<table border="0" cellspacing="0" cellpadding="0" align="left">' . "\n");
                print('<tr><td class="embedded"><form method="post" action="?action=setsticky">' . "\n");
                print('<input type="hidden" name="topicid" value="' . $topicid . '" />' . "\n");
                print('<input type="hidden" name="returnto" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '" />' . "\n");
                print('<input type="hidden" name="sticky" value="' . ($sticky ? 'no' : 'yes') . '" /><input type="submit" class="medium" value="' . ($sticky ? $lang['submit_unsticky'] : $lang['submit_sticky']) . '" /></form></td>' . "\n");
                print('<td class="embedded"><form method="post" action="?action=setlocked">' . "\n");
                print('<input type="hidden" name="topicid" value="' . $topicid . '" />' . "\n");
                print('<input type="hidden" name="returnto" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '" />' . "\n");
                print('<input type="hidden" name="locked" value="' . ($locked ? 'no' : 'yes') . '" /><input type="submit" class="medium" value="' . ($locked ? $lang['submit_unlock'] : $lang['submit_lock']) . '" /></form></td>' . "\n");
                print('<td class="embedded"><form method="get" action="?">' . "\n");
                print('<input type="hidden" name="action" value="deletetopic" />' . "\n");
                print('<input type="hidden" name="topicid" value="' . $topicid . '" />' . "\n");
                print('<input type="hidden" name="forumid" value="' . $forumid . '" />' . "\n");
                print('<input type="submit" class="medium" value="' . $lang['submit_delete_topic'] . '" /></form></td>' . "\n");
                print('<td class="embedded"><form method="post" action="' . htmlspecialchars('?action=movetopic&topicid=' . $topicid) . '">' . "\n" . '&nbsp;' . $lang['text_move_thread_to'] . '&nbsp;<select class="med" name="forumid">');
                $forums = $this->getForums();
                foreach ($forums as $forumRow) {
                    if ($forumRow['id'] != $forumid && get_user_class() >= $forumRow['minclasswrite']) {
                        print('<option value="' . $forumRow['id'] . '">' . htmlspecialchars($forumRow['name']) . '</option>' . "\n");
                    }
                }
                print('</select> <input type="submit" class="medium" value="' . $lang['submit_move'] . '" /></form></td>');
                print('<td class="embedded"><form method="post" action="' . htmlspecialchars('?action=hltopic&topicid=' . $topicid) . '">' . "\n" . '&nbsp;' . $lang['text_highlight_topic'] . '&nbsp;<select class="med" name="color">');
                print("<option value='0'>" . $lang['select_color'] . "</option>
<option style='background-color: black' value=\"1\">Black</option>
<option style='background-color: sienna' value=\"2\">Sienna</option>
<option style='background-color: darkolivegreen' value=\"3\">Dark Olive Green</option>
<option style='background-color: darkgreen' value=\"4\">Dark Green</option>
<option style='background-color: darkslateblue' value=\"5\">Dark Slate Blue</option>
<option style='background-color: navy' value=\"6\">Navy</option>
<option style='background-color: indigo' value=\"7\">Indigo</option>
<option style='background-color: darkslategray' value=\"8\">Dark Slate Gray</option>
<option style='background-color: darkred' value=\"9\">Dark Red</option>
<option style='background-color: darkorange' value=\"10\">Dark Orange</option>
<option style='background-color: olive' value=\"11\">Olive</option>
<option style='background-color: green' value=\"12\">Green</option>
<option style='background-color: teal' value=\"13\">Teal</option>
<option style='background-color: blue' value=\"14\">Blue</option>
<option style='background-color: slategray' value=\"15\">Slate Gray</option>
<option style='background-color: dimgray' value=\"16\">Dim Gray</option>
<option style='background-color: red' value=\"17\">Red</option>
<option style='background-color: sandybrown' value=\"18\">Sandy Brown</option>
<option style='background-color: yellowgreen' value=\"19\">Yellow Green</option>
<option style='background-color: seagreen' value=\"20\">Sea Green</option>
<option style='background-color: mediumturquoise' value=\"21\">Medium Turquoise</option>
<option style='background-color: royalblue' value=\"22\">Royal Blue</option>
<option style='background-color: purple' value=\"23\">Purple</option>
<option style='background-color: gray' value=\"24\">Gray</option>
<option style='background-color: magenta' value=\"25\">Magenta</option>
<option style='background-color: orange' value=\"26\">Orange</option>
<option style='background-color: yellow' value=\"27\">Yellow</option>
<option style='background-color: lime' value=\"28\">Lime</option>
<option style='background-color: cyan' value=\"29\">Cyan</option>
<option style='background-color: deepskyblue' value=\"30\">Deep Sky Blue</option>
<option style='background-color: darkorchid' value=\"31\">Dark Orchid</option>
<option style='background-color: silver' value=\"32\">Silver</option>
<option style='background-color: pink' value=\"33\">Pink</option>
<option style='background-color: wheat' value=\"34\">Wheat</option>
<option style='background-color: lemonchiffon' value=\"35\">Lemon Chiffon</option>
<option style='background-color: palegreen' value=\"36\">Pale Green</option>
<option style='background-color: paleturquoise' value=\"37\">Pale Turquoise</option>
<option style='background-color: lightblue' value=\"38\">Light Blue</option>
<option style='background-color: plum' value=\"39\">Plum</option>
<option style='background-color: white' value=\"40\">White</option>");
                print('</select>');
                print('<input type="hidden" name="returnto" value="' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') . '" />' . "\n");
                print('<input type="submit" class="medium" value="' . $lang['submit_change'] . '" /></form></td>');
                print("</tr>\n");
                print("</table>\n");
            }

            end_frame();
            end_main_frame();

            print($pagerbottom);
            if ($maypost) {
                print('<br /><table style=\'border:1px solid #000000;\'><tr>' .
                    '<td class="text" align="center"><b>' . $lang['text_quick_reply'] . '</b><br /><br />' .
                    '<form id="compose" name="compose" method="post" action="?action=post" onsubmit="return postvalid(this);">' .
                    '<input type="hidden" name="id" value="' . $topicid . '" /><input type="hidden" name="type" value="reply" /><br />');
                quickreply('compose', 'body', $lang['submit_add_reply']);
                print('</form></td></tr></table>');
                print('<p align="center"><a class="index" href="' . htmlspecialchars('?action=reply&topicid=' . $topicid) . '">' . $lang['text_add_reply'] . '</a></p>' . "\n");
            } elseif ($locked) {
                print($lang['text_topic_locked_new_denied']);
            } else {
                print($lang['text_unpermitted_posting_here']);
            }
            print(key_shortcut($page, $pages - 1));
        });

        $pageTitle = $lang['head_view_topic'] . ' "' . $orgsubject . '"';
        return view('forums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Move a topic to another forum (legacy action=movetopic, POST).
     */
    private function webMoveTopic(Request $request, array $curUser, array $lang)
    {
        $forumid = intval($request->input('forumid', 0));
        $topicid = intval($request->query('topicid', 0));
        $ismod = is_forum_moderator($topicid, 'topic');
        if (!is_valid_id($forumid) || !is_valid_id($topicid) || (!user_can('postmanage') && !$ismod)) {
            abort(403);
        }

        $forum = Forum::query()->whereKey($forumid)->first();
        if (!$forum) {
            abort(400, $lang['std_forum_not_found'] ?? 'Forum not found.');
        }
        if (get_user_class() < $forum->minclasswrite) {
            abort(403);
        }

        $topic = Topic::query()->whereKey($topicid)->first();
        if (!$topic) {
            abort(400, $lang['std_topic_not_found'] ?? 'Topic not found.');
        }
        $old_forumid = (int) $topic->forumid;
        $nb_posts = Post::query()->where('topicid', $topicid)->count();

        // move topic + update counts
        if ($old_forumid != $forumid) {
            $topic->update(['forumid' => $forumid]);
            Forum::query()->whereKey($old_forumid)->decrement('topiccount');
            Forum::query()->whereKey($old_forumid)->decrement('postcount', $nb_posts);
            $GLOBALS['Cache']->delete_value('forum_' . $old_forumid . '_post_' . $this->todayDate . '_count');
            $GLOBALS['Cache']->delete_value('forum_' . $old_forumid . '_last_replied_topic_content');
            Forum::query()->whereKey($forumid)->increment('topiccount');
            Forum::query()->whereKey($forumid)->increment('postcount', $nb_posts);
            $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_post_' . $this->todayDate . '_count');
            $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_last_replied_topic_content');
        }

        return redirect('forums.php?action=viewforum&forumid=' . $forumid);
    }

    /**
     * Delete a topic (legacy action=deletetopic).
     */
    private function webDeleteTopic(Request $request, array $curUser, array $lang)
    {
        $topicid = intval($request->query('topicid', 0));
        $topic = Topic::query()->whereKey($topicid)->first();
        if (!$topic) {
            abort(400, $lang['std_topic_not_found'] ?? 'Topic not found.');
        }
        $forumid = (int) $topic->forumid;
        $userid = (int) $topic->userid;
        $ismod = is_forum_moderator($topicid, 'topic');
        if (!is_valid_id($topicid) || (!user_can('postmanage') && !$ismod)) {
            abort(403);
        }

        $sure = intval($request->query('sure', 0));
        if (!$sure) {
            $content = $this->capture(function () use ($lang, $topicid) {
                stdmsg($lang['std_delete_topic'] ?? 'Delete topic', $lang['std_delete_topic_note'] .
                    '<a class=altlink href=?action=deletetopic&topicid=' . $topicid . '&sure=1>' . $lang['std_here_if_sure']);
            });
            return view('forums', compact('content') + ['pageTitle' => $lang['std_delete_topic'] ?? 'Delete topic', 'lang' => $lang]);
        }

        $postcount = Post::query()->where('topicid', $topicid)->count();
        $topic->delete();
        Post::query()->where('topicid', $topicid)->delete();
        DB::table('readposts')->where('topicid', $topicid)->delete();
        Forum::query()->whereKey($forumid)->decrement('topiccount');
        Forum::query()->whereKey($forumid)->decrement('postcount', $postcount);
        $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_post_' . $this->todayDate . '_count');
        $lastRepliedRow = $GLOBALS['Cache']->get_value('forum_' . $forumid . '_last_replied_topic_content');
        if (!empty($lastRepliedRow) && ($lastRepliedRow['id'] ?? null) == $topicid) {
            $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_last_replied_topic_content');
        }

        KPS('-', get_setting('bonus.starttopic', 2), $userid);

        return redirect('forums.php?action=viewforum&forumid=' . $forumid);
    }

    /**
     * Delete a post (legacy action=deletepost).
     */
    private function webDeletePost(Request $request, array $curUser, array $lang)
    {
        $postid = intval($request->query('postid', 0));
        $sure = intval($request->query('sure', 0));

        $ismod = is_forum_moderator($postid, 'post');
        if ((!user_can('postmanage') && !$ismod) || !is_valid_id($postid)) {
            abort(403);
        }

        $post = Post::query()->whereKey($postid)->first();
        if (!$post) {
            abort(400, $lang['std_post_not_found'] ?? 'Post not found.');
        }
        $topicid = (int) $post->topicid;
        $userid = (int) $post->userid;

        // id of the last post before the one being deleted
        $redirtopost = '';
        $prevPost = Post::query()->where('topicid', $topicid)->where('id', '<', $postid)->orderByDesc('id')->first();
        if (!$prevPost) {
            // this is the first post of the topic
            $content = $this->capture(function () use ($lang, $topicid) {
                stdmsg($lang['std_delete_post'] ?? 'Delete post', $lang['std_cannot_delete_post'] .
                    '<a class=altlink href=?action=deletetopic&topicid=' . $topicid . '&sure=1>' . $lang['std_delete_topic_instead']);
            });
            return view('forums', compact('content') + ['pageTitle' => $lang['std_delete_post'] ?? 'Delete post', 'lang' => $lang]);
        }
        $redirtopost = '&page=p' . $prevPost->id . '#pid' . $prevPost->id;

        if (!$sure) {
            $content = $this->capture(function () use ($lang, $postid) {
                stdmsg($lang['std_delete_post'] ?? 'Delete post', $lang['std_delete_post_note'] .
                    '<a class=altlink href=?action=deletepost&postid=' . $postid . '&sure=1>' . $lang['std_here_if_sure']);
            });
            return view('forums', compact('content') + ['pageTitle' => $lang['std_delete_post'] ?? 'Delete post', 'lang' => $lang]);
        }

        $post->delete();
        $GLOBALS['Cache']->delete_value('user_' . $userid . '_post_count');
        $GLOBALS['Cache']->delete_value('topic_' . $topicid . '_post_count');

        $forumid = (int) Topic::query()->whereKey($topicid)->value('forumid');
        if (!$forumid) {
            abort(400);
        }
        Forum::query()->whereKey($forumid)->decrement('postcount');
        $lastRepliedRow = $GLOBALS['Cache']->get_value('forum_' . $forumid . '_last_replied_topic_content');
        if (!empty($lastRepliedRow) && ($lastRepliedRow['lastpost'] ?? null) == $postid) {
            $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_last_replied_topic_content');
        }
        $this->updateTopicLastPost($topicid, $lang);

        KPS('-', get_setting('bonus.makepost', 1), $userid);

        return redirect('forums.php?action=viewtopic&topicid=' . $topicid . $redirtopost);
    }

    /**
     * Toggle lock on/off (legacy action=setlocked, POST).
     */
    private function webSetLocked(Request $request, array $curUser, array $lang)
    {
        $topicid = intval($request->input('topicid', 0));
        $ismod = is_forum_moderator($topicid, 'topic');
        if (!$topicid || (!user_can('postmanage') && !$ismod)) {
            abort(403);
        }
        Topic::query()->whereKey($topicid)->update(['locked' => (string) $request->input('locked', 'no')]);

        return redirect((string) $request->input('returnto', 'forums.php'));
    }

    /**
     * Highlight a topic (legacy action=hltopic, POST).
     */
    private function webHlTopic(Request $request, array $curUser, array $lang)
    {
        $topicid = intval($request->query('topicid', 0));
        $ismod = is_forum_moderator($topicid, 'topic');
        if (!$topicid || (!user_can('postmanage') && !$ismod)) {
            abort(403);
        }
        $color = (int) $request->input('color', 0);
        if ($color == 0 || get_hl_color($color)) {
            Topic::query()->whereKey($topicid)->update(['hlcolor' => $color]);
        }
        $forumid = (int) Topic::query()->whereKey($topicid)->value('forumid');
        $lastRepliedRow = $GLOBALS['Cache']->get_value('forum_' . $forumid . '_last_replied_topic_content');
        if (!empty($lastRepliedRow) && ($lastRepliedRow['id'] ?? null) == $topicid) {
            $GLOBALS['Cache']->delete_value('forum_' . $forumid . '_last_replied_topic_content');
        }
        return redirect((string) $request->input('returnto', 'forums.php'));
    }

    /**
     * Toggle sticky on/off (legacy action=setsticky, POST).
     */
    private function webSetSticky(Request $request, array $curUser, array $lang)
    {
        $topicid = intval($request->input('topicid', 0));
        $ismod = is_forum_moderator($topicid, 'topic');
        if (!$topicid || (!user_can('postmanage') && !$ismod)) {
            abort(403);
        }
        Topic::query()->whereKey($topicid)->update(['sticky' => (string) $request->input('sticky', 'no')]);

        return redirect((string) $request->input('returnto', 'forums.php'));
    }

    /**
     * Topic list of one forum (legacy action=viewforum).
     */
    private function webViewForum(Request $request, array $curUser, array $lang)
    {
        $forumid = intval($request->query('forumid', 0));
        if (!is_valid_id($forumid)) {
            $this->invalidIdAbort();
        }

        $row = $this->getForums()[$forumid] ?? null;
        if (!$row) {
            write_log('User ' . $curUser['username'] . ',' . $curUser['ip'] . ' is trying to visit forum that doesn\'t exist', 'mod');
            abort(400, $lang['std_forum_not_found'] ?? 'Forum not found.');
        }
        if (get_user_class() < $row['minclassread']) {
            abort(403);
        }

        $forumname = $row['name'];
        $forummoderators = get_forum_moderators($forumid, false);
        $search = trim((string) $request->query('search', ''));
        $addparam = '';
        $topicQuery = Topic::query()->where('forumid', $forumid);
        if ($search !== '') {
            $topicQuery->where('subject', 'like', '%' . $search . '%');
            $addparam .= '&search=' . rawurlencode($search);
        }
        $num = $topicQuery->count();

        $pagerData = pager($this->topicsPerPage, $num, '?action=viewforum&forumid=' . $forumid . $addparam . '&');
        $pagertop = $pagerData[0];
        $pagerbottom = $pagerData[1];
        $pagerStart = $pagerData[3];
        $pagerRpp = $pagerData[4];

        $sort = (string) $request->query('sort', '');
        $orderby = match ($sort) {
            'firstpostasc' => 'firstpost ASC',
            'firstpostdesc' => 'firstpost DESC',
            'lastpostasc' => 'lastpost ASC',
            'lastpostdesc' => 'lastpost DESC',
            default => 'lastpost DESC',
        };

        $topics = (clone $topicQuery)->orderByDesc('sticky')->orderByRaw(str_replace(' DESC', ' desc', str_replace(' ASC', ' asc', $orderby)))->orderBy('id', 'desc')->offset($pagerStart)->limit($pagerRpp)->get();
        $numtopics = $topics->count();

        $siteName = $GLOBALS['SITENAME'];
        $enabletooltip = get_setting('tweak.enabletooltip', 'yes') == 'yes' && ($curUser['showlastpost'] ?? 'no') != 'no';
        $content = $this->capture(function () use (
            $siteName, $lang, $forumid, $forumname, $forummoderators, $row, $curUser,
            $search, $addparam, $pagertop, $pagerbottom, $numtopics, $topics, $enabletooltip, $sort
        ) {
            print('<h1 align="center"><a class="faqlink" href="forums.php">' . $siteName . '&nbsp;' . $lang['text_forums'] . '</a>--><a class="faqlink" href="' . htmlspecialchars('forums.php?action=viewforum&forumid=' . $forumid) . '">' . $forumname . '</a></h1>' . "\n");
            print('<br />');
            $maypost = get_user_class() >= $row['minclasswrite'] && get_user_class() >= $row['minclasscreate'] && ($curUser['forumpost'] ?? 'yes') == 'yes';
            if (!$maypost) {
                print('<p><i>' . $lang['text_unpermitted_starting_new_topics'] . '</i></p>' . "\n");
            }
            print('<table border="0" class="main" cellspacing="0" cellpadding="5" width="97%"><tr>' . "\n");
            print('<td class="embedded" width="90%">');
            print($forummoderators ? '&nbsp;&nbsp;<img class="forum_mod" src="pic/trans.gif" alt="Moderator" title="' . $lang['col_moderator'] . '">&nbsp;' . $forummoderators : '');
            print('</td><td class="embedded nowrap" width="1%">');
            if ($maypost) {
                print('<a href="' . htmlspecialchars('?action=newtopic&forumid=' . $forumid) . '"><img class="f_new" src="pic/trans.gif" alt="New Topic" title="' . $lang['title_new_topic'] . '" /></a>&nbsp;&nbsp;');
            }
            print('</td>');
            print("</tr></table>\n");
            if ($numtopics > 0) {
                print('<table border="1" cellspacing="0" cellpadding="5" width="97%">');
                print('<tr><td class="colhead" align="center" width="99%">' . $lang['col_topic'] . '</td><td class="colhead" align="center"><a href="' . htmlspecialchars('?action=viewforum&forumid=' . $forumid . $addparam . '&sort=' . ($sort == 'firstpostdesc' ? 'firstpostasc' : 'firstpostdesc')) . '" title="' . ($sort == 'firstpostdesc' ? $lang['title_order_topic_asc'] : $lang['title_order_topic_desc']) . '">' . $lang['col_author'] . '</a></td><td class="colhead" align="center">' . $lang['col_replies'] . '/' . $lang['col_views'] . '</td><td class="colhead" align="center"><a href="' . htmlspecialchars('?action=viewforum&forumid=' . $forumid . $addparam . '&sort=' . ($sort == 'lastpostasc' ? 'lastpostdesc' : 'lastpostasc')) . '" title="' . ($sort == 'lastpostasc' ? $lang['title_order_post_desc'] : $lang['title_order_post_asc']) . '">' . $lang['col_last_post'] . '</a></td>' . "\n");
                print("</tr>\n");

                $counter = 0;
                $lastpost_tooltip = [];
                foreach ($topics as $topic) {
                    $topicarr = $topic->toArray();
                    $topicid = $topicarr['id'];
                    $topic_userid = $topicarr['userid'];
                    $topic_views = $topicarr['views'];
                    $views = number_format($topic_views);
                    $locked = $topicarr['locked'] == 'yes';
                    $sticky = $topicarr['sticky'] == 'yes';
                    $hlcolor = $topicarr['hlcolor'];

                    if (!$posts = $GLOBALS['Cache']->get_value('topic_' . $topicid . '_post_count')) {
                        $posts = Post::query()->where('topicid', $topicid)->count();
                        $GLOBALS['Cache']->cache_value('topic_' . $topicid . '_post_count', $posts, 3600);
                    }
                    $replies = max(0, $posts - 1);
                    $tpages = (int) floor($posts / $this->postsPerPage);
                    if ($tpages * $this->postsPerPage != $posts) {
                        ++$tpages;
                    }
                    if ($tpages > 1) {
                        $topicpages = ' [<img class="multipage" src="pic/trans.gif" alt="multi-page" /> ';
                        $dotted = 0;
                        $dotspace = 4;
                        $dotend = $tpages - $dotspace;
                        for ($i = 1; $i <= $tpages; ++$i) {
                            if ($i > $dotspace && $i <= $dotend) {
                                if (!$dotted) {
                                    $topicpages .= ' ... ';
                                }
                                $dotted = 1;
                                continue;
                            }
                            $topicpages .= ' <a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $topicid . '&page=' . ($i - 1)) . '">' . $i . '</a>';
                        }
                        $topicpages .= ' ]';
                    } else {
                        $topicpages = '';
                    }

                    $arr = get_post_row($topicarr['lastpost']) ?: [];
                    $lppostid = intval($arr['id'] ?? 0);
                    $lpuserid = intval($arr['userid'] ?? 0);
                    $lpusername = get_username($lpuserid);
                    $lpadded = gettime($arr['added'] ?? '', true, false);
                    $onmouseover = '';
                    if ($enabletooltip) {
                        if (($curUser['timetype'] ?? 'timealive') != 'timealive') {
                            $lastposttime = $lang['text_at_time'] . ($arr['added'] ?? '');
                        } else {
                            $lastposttime = $lang['text_blank'] . gettime($arr['added'] ?? '', true, false, true);
                        }
                        $lptext = format_comment(mb_substr((string) $arr['body'], 0, 100, 'UTF-8') . (mb_strlen((string) $arr['body'], 'UTF-8') > 100 ? ' ......' : ''), true, false, false, true, 600, false, false);
                        $lastpost_tooltip[$counter]['id'] = 'lastpost_' . $counter;
                        $lastpost_tooltip[$counter]['content'] = $lang['text_last_posted_by'] . $lpusername . $lastposttime . '<br />' . $lptext;
                        $onmouseover = "onmouseover=\"domTT_activate(this, event, 'content', document.getElementById('" . $lastpost_tooltip[$counter]['id'] . "'), 'trail', false,'lifetime', 5000,'styleClass','niceTitle','fadeMax', 87,'maxWidth', 400);\"";
                    }

                    $arr = get_post_row($topicarr['firstpost']) ?: [];
                    $fpuserid = intval($arr['userid'] ?? 0);
                    $fpauthor = get_username($fpuserid);

                    $subject = ($sticky ? '<img class="sticky" src="pic/trans.gif" alt="Sticky" title="' . $lang['title_sticky'] . '" />&nbsp;&nbsp;' : '') . '<a href="' . htmlspecialchars('?action=viewtopic&forumid=' . $forumid . '&topicid=' . $topicid) . '" ' . $onmouseover . '>' . $this->highlightTopic(highlight($search, htmlspecialchars($topicarr['subject'])), $hlcolor) . '</a>' . $topicpages;
                    $lastpostread = $this->getLastReadPostId($curUser, $topicid);

                    if ($lastpostread >= $lppostid) {
                        $img = $this->getTopicImage($locked ? 'locked' : 'read', $lang);
                    } else {
                        $img = $this->getTopicImage($locked ? 'lockednew' : 'unread', $lang);
                        if ($lastpostread != ($curUser['last_catchup'] ?? 0)) {
                            $subject .= '&nbsp;&nbsp;<a href="' . htmlspecialchars('?action=viewtopic&forumid=' . $forumid . '&topicid=' . $topicid . '&page=p' . $lastpostread . '#pid' . $lastpostread) . '" title="' . $lang['title_jump_to_unread'] . '"><font class="small new"><b>' . $lang['text_new'] . '</b></font></a>';
                        }
                    }

                    $topictime = substr((string) ($arr['added'] ?? ''), 0, 10);
                    if (strtotime((string) ($arr['added'] ?? '')) + 86400 > TIMENOW) {
                        $topictime = '<font class="new small">' . $topictime . '</font>';
                    } else {
                        $topictime = '<font color="gray" class="small">' . $topictime . '</font>';
                    }

                    print('<tr><td class="rowfollow" align="left"><table border="0" cellspacing="0" cellpadding="0"><tr>' .
                        '<td class="embedded" style=\'padding-right: 10px\'>' . $img .
                        '</td><td class="embedded" align="left">' . "\n" .
                        $subject . '</td></tr></table></td><td class="rowfollow" align="center">' . get_username($fpuserid) . '<br />' . $topictime . '</td><td class="rowfollow" align="center">' . $replies . ' / <font color="gray">' . $views . '</font></td>' . "\n" .
                        '<td class="rowfollow nowrap" align="center">' . $lpadded . '<br />' . $lpusername . '</td>' . "\n");
                    print("</tr>\n");
                    ++$counter;
                }

                print('<tr><td align="left">' . "\n");
                print('<form method="get" action="forums.php"><b>' . $lang['text_fast_search'] . '</b><input type="hidden" name="action" value="viewforum" /><input type="hidden" name="forumid" value="' . $forumid . '" /><input type="text" style="width: 180px" name="search" />&nbsp;<input type="submit" value="' . $lang['text_go'] . '" /></form>');
                print('</td>');
                print('<td align="left" colspan="3">');
                print('<span id="order" onclick="dropmenu(this);"><span style="cursor: pointer;"><b>' . $lang['text_order'] . '</b></span>');
                print('<span id="orderlist" class="dropmenu" style="display: none"><ul>');
                print('<li><a href="?action=viewforum&amp;forumid=' . $forumid . $addparam . '&amp;sort=firstpostdesc">' . $lang['text_topic_desc'] . '</a></li>');
                print('<li><a href="?action=viewforum&amp;forumid=' . $forumid . $addparam . '&amp;sort=firstpostasc">' . $lang['text_topic_asc'] . '</a></li>');
                print('<li><a href="?action=viewforum&amp;forumid=' . $forumid . $addparam . '&amp;sort=lastpostdesc">' . $lang['text_post_desc'] . '</a></li>');
                print('<li><a href="?action=viewforum&amp;forumid=' . $forumid . $addparam . '&amp;sort=lastpostasc">' . $lang['text_post_asc'] . '</a></li>');
                print('</ul>');
                print('</span>');
                print('</span>');
                print('</td>');
                print("</tr></table>");
                print($pagerbottom);
                if ($enabletooltip) {
                    create_tooltip_container($lastpost_tooltip, 400);
                }
            } else {
                print('<p>' . $lang['text_no_topics_found'] . '</p>');
            }
        });

        $pageTitle = ($lang['head_forum'] ?? 'Forum') . ' ' . $forumname;
        return view('forums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Topics with unread posts (legacy action=viewunread).
     */
    private function webViewUnread(Request $request, array $curUser, array $lang)
    {
        $beforepostid = intval($request->query('beforepostid', 0));

        $maxresults = 25;
        $topicQuery = Topic::query()->where('lastpost', '>', $curUser['last_catchup'] ?? 0);
        if ($beforepostid) {
            $topicQuery->where('lastpost', '<', $beforepostid);
        }
        $topicRows = $topicQuery->orderByDesc('lastpost')->limit(100)->get(['id', 'forumid', 'subject', 'lastpost', 'hlcolor']);

        $siteName = $GLOBALS['SITENAME'];
        $content = $this->capture(function () use ($siteName, $lang, $curUser, $topicRows, $maxresults) {
            print('<h1 align="center"><a class="faqlink" href="forums.php">' . $siteName . '&nbsp;' . $lang['text_forums'] . '</a>-->' . $lang['text_topics_with_unread_posts'] . '</h1>');

            $n = 0;
            $uc = get_user_class();
            $lastBeforePostId = 0;
            foreach ($topicRows as $topicRow) {
                $arr = $topicRow->toArray();
                $topiclastpost = $arr['lastpost'];
                $topicid = $arr['id'];

                $lastpostread = $this->getLastReadPostId($curUser, $topicid);
                if ($lastpostread >= $topiclastpost) {
                    continue;
                }
                $forumid = $arr['forumid'];
                $a = $this->getForums()[$forumid] ?? null;
                if (!$a || $uc < $a['minclassread']) {
                    continue;
                }
                ++$n;
                if ($n > $maxresults) {
                    break;
                }
                $lastBeforePostId = $topiclastpost;
                $forumname = $a['name'];
                if ($n == 1) {
                    print('<table border="1" cellspacing="0" cellpadding="5">' . "\n");
                    print('<tr><td class="colhead" align="left">' . $lang['col_topic'] . '</td><td class="colhead" align="left">' . $lang['col_forum'] . '</td></tr>' . "\n");
                }
                print('<tr><td class="rowfollow" align="left"><table border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded" style=\'padding-right: 10px\'>' .
                    $this->getTopicImage('unread', $lang) . '</td><td class="embedded">' .
                    '<a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $topicid . ($lastpostread > 0 && $lastpostread != ($curUser['last_catchup'] ?? 0) ? '&page=p' . $lastpostread . '#pid' . $lastpostread : '')) . '">' . $this->highlightTopic(htmlspecialchars($arr['subject']), $arr['hlcolor']) .
                    '</a></td></tr></table></td><td class="rowfollow" align="left"><a href="' . htmlspecialchars('?action=viewforum&forumid=' . $forumid) . '"><b>' . $forumname . '</b></a></td></tr>' . "\n");
            }
            if ($n > 0) {
                print("</table>\n");
                print('<table border="0" class="main" cellspacing="0" cellpadding="5" width="1%"><tr><td class="embedded"><form method="get" action="?"><input type="hidden" name="catchup" value="1" /><input type="submit" value="' . $lang['text_catch_up'] . '" class="btn" /></form></td>');
                if ($n > $maxresults) {
                    print('<td class="embedded"><form method="get" action="?"><input type="hidden" name="action" value="viewunread" /><input type="hidden" name="beforepostid" value="' . $lastBeforePostId . '" /><input type="submit" value="' . $lang['submit_show_more'] . '" class="btn" /></form></td>');
                }
                print("</tr></table>");
            } else {
                print('<p>' . $lang['text_nothing_found'] . '</p>');
            }
        });

        $pageTitle = $lang['head_view_unread'] ?? 'View new replies';
        return view('forums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Forum search page (legacy action=search).
     */
    private function webSearch(Request $request, array $curUser, array $lang)
    {
        $error = true;
        $keyword = trim((string) $request->query('keywords', ''));
        $keywords = htmlspecialchars($keyword);

        $baseQuery = Post::query()
            ->select('posts.id')
            ->leftJoin('topics', 'posts.topicid', '=', 'topics.id')
            ->leftJoin('forums', 'topics.forumid', '=', 'forums.id')
            ->where('forums.minclassread', '<=', get_user_class());

        $hits = 0;
        if ($keyword !== '') {
            $this->applySearchKeywords($baseQuery, $keyword);
            $hits = (clone $baseQuery)->count('posts.id');
        }

        $siteName = $GLOBALS['SITENAME'];
        $content = $this->capture(function () use ($lang, $error, $keywords, $hits, $siteName, $baseQuery) {
            $found = '';
            if ($hits) {
                $error = false;
                $found = '[<b><font class="striking"> ' . $lang['text_found'] . $hits . $lang['text_num_posts'] . ' </font></b>]';
            }
            print('<style type="text/css">
.search{
	background-image:url(pic/search.gif);
	background-repeat:no-repeat;
	width:579px;
	height:95px;
	margin:5px 0 5px 0;
	text-align:left;
}
.search_title{
	color:#0062AE;
	background-color:#DAF3FB;
	font-size:12px;
	font-weight:bold;
	text-align:left;
	padding:7px 0 0 15px;
}

.search_table {
	border-collapse: collapse;
	border: none;
	background-color: #ffffff;
}

</style>');
            print('<div class="search">');
            print('<div class="search_title">' . $lang['text_search_on_forum'] . ' ' . ($error && $keywords != '' ? '[<b><font color=striking> ' . $lang['text_nothing_found'] . '</font></b> ]' : $found) . '</div>');
            print('<div style="margin-left: 53px; margin-top: 13px;">');
            print('<form method="get" action="forums.php" id="search_form" style="margin: 0pt; padding: 0pt; font-family: Tahoma,Arial,Helvetica,sans-serif; font-size: 11px;">');
            print('<input type="hidden" name="action" value="search" />');
            print('<table border="0" cellpadding="0" cellspacing="0" width="512" class="search_table">');
            print('<tbody>');
            print('<tr>');
            print('<td style="padding-bottom: 3px; border: 0;" valign="top">' . $lang['text_by_keyword'] . '</td>');
            print('</tr>');
            print('<tr>');
            print('<td style="padding-bottom: 3px; border: 0;" valign="top">');
            print('<input name="keywords" type="text" value="' . $keywords . '" style="width: 400px;" /></td>');
            print('<td style="padding-bottom: 3px; border: 0;" valign="top"><input name="image" type="image" style="vertical-align: middle; padding-bottom: 0px; margin-left: 0px;" src="' . get_forum_pic_folder() . '/search_button.gif" alt="Search" /></td>');
            print('</tr>');
            print('</tbody>');
            print('</table>');
            print('</form>');
            print('</div>');
            print('</div>');

            if (!$error) {
                $perpage = $this->topicsPerPage;
                $pagerData = pager($perpage, $hits, 'forums.php?action=search&keywords=' . rawurlencode($keywords) . '&');
                $pagertop = $pagerData[0];
                $pagerbottom = $pagerData[1];
                $pagerStart = $pagerData[3];
                $pagerRpp = $pagerData[4];

                $results = (clone $baseQuery)
                    ->select('posts.id', 'posts.topicid', 'posts.userid', 'posts.added', 'topics.subject', 'topics.hlcolor', 'forums.id AS forumid', 'forums.name AS forumname')
                    ->orderByDesc('posts.id')
                    ->offset($pagerStart)
                    ->limit($pagerRpp)
                    ->get();

                print($pagertop);
                print('<table border="1" cellspacing="0" cellpadding="5" width="97%">' . "\n");
                print('<tr><td class="colhead" align="center">' . $lang['col_post'] . '</td><td class="colhead" align="center" width="70%">' . $lang['col_topic'] . '</td><td class="colhead" align="left">' . $lang['col_forum'] . '</td><td class="colhead" align="left">' . $lang['col_posted_by'] . '</td></tr>' . "\n");

                foreach ($results as $post) {
                    $postArr = $post->toArray();
                    print('<tr><td class="rowfollow" align="center" width="1%">' . $postArr['id'] . '</td><td class="rowfollow" align="left"><a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $postArr['topicid'] . '&highlight=' . rawurlencode($keywords) . '&page=p' . $postArr['id'] . '#pid' . $postArr['id']) . '">' . $this->highlightTopic(highlight($keywords, htmlspecialchars($postArr['subject'])), $postArr['hlcolor']) . '</a></td><td class="rowfollow nowrap" align="left"><a href="' . htmlspecialchars('?action=viewforum&forumid=' . $postArr['forumid']) . '"><b>' . htmlspecialchars($postArr['forumname']) . '</b></a></td><td class="rowfollow nowrap" align="left">' . gettime($postArr['added'], true, false) . '&nbsp;|&nbsp;' . get_username($postArr['userid']) . '</td></tr>' . "\n");
                }

                print("</table>\n");
                print($pagerbottom);
            }
        });

        $pageTitle = $lang['head_forum_search'] ?? 'Forum search';
        return view('forums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    /**
     * Forum index (default action).
     */
    private function webForumList(Request $request, array $curUser, array $lang)
    {
        // mark the user as active on the forum
        $GLOBALS['CURUSER'] = $curUser;
        User::query()->whereKey($curUser['id'])->update(['forum_access' => date('Y-m-d H:i:s')]);

        $siteName = $GLOBALS['SITENAME'];
        $content = $this->capture(function () use ($siteName, $lang, $curUser) {
            begin_main_frame();
            print('<h1 align="center">' . $siteName . '&nbsp;' . $lang['text_forums'] . '</h1>');
            print('<p align="center"><a href="?action=search"><b>' . $lang['text_search'] . '</b></a> | <a href="?action=viewunread"><b>' . $lang['text_view_unread'] . '</b></a> | <a href="?catchup=1"><b>' . $lang['text_catch_up'] . '</b></a> ' . (user_can('forummanage') ? '| <a href="forummanage.php"><b>' . $lang['text_forum_manager'] . '</b></a>' : '') . '</p>');
            print('<table border="1" cellspacing="0" cellpadding="5" width="100%">' . "\n");

            if (!$overforums = $GLOBALS['Cache']->get_value('overforums_list')) {
                $overforums = [];
                foreach (OverForum::query()->orderBy('sort')->get() as $row) {
                    $overforums[] = $row->toArray();
                }
                $GLOBALS['Cache']->cache_value('overforums_list', $overforums, 86400);
            }

            $count = 0;
            $todayDate = $this->todayDate;
            foreach ($overforums as $a) {
                if (get_user_class() < $a['minclassview']) {
                    continue;
                }
                $forid = $a['id'];
                $overforumname = $a['name'];

                print('<tr><td align="left" class="colhead" width="99%">' . htmlspecialchars($overforumname) . '</td><td align="center" class="colhead">' . $lang['col_topics'] . '</td>' .
                    '<td align="center" class="colhead">' . $lang['col_posts'] . '</td>' .
                    '<td align="left" class="colhead">' . $lang['col_last_post'] . '</td><td class="colhead" align="left">' . $lang['col_moderator'] . '</td></tr>' . "\n");

                foreach ($this->getForums() as $forums_arr) {
                    if ($forums_arr['forid'] != $forid) {
                        continue;
                    }
                    if (get_user_class() < $forums_arr['minclassread']) {
                        continue;
                    }

                    $forumid = $forums_arr['id'];
                    $forumname = htmlspecialchars($forums_arr['name']);
                    $forumdescription = htmlspecialchars($forums_arr['description']);

                    $forummoderators = get_forum_moderators($forums_arr['id'], false);
                    if (!$forummoderators) {
                        $forummoderators = '<a href="contactstaff.php"><i>' . $lang['text_apply_now'] . '</i></a>';
                    }

                    $topiccount = number_format($forums_arr['topiccount']);
                    $postcount = number_format($forums_arr['postcount']);

                    // find last post ID
                    if (!$arr = $GLOBALS['Cache']->get_value('forum_' . $forumid . '_last_replied_topic_content')) {
                        $arr = Topic::query()->where('forumid', $forumid)->orderByDesc('lastpost')->first();
                        $arr = $arr ? $arr->toArray() : null;
                        $GLOBALS['Cache']->cache_value('forum_' . $forumid . '_last_replied_topic_content', $arr, 900);
                    }

                    if ($arr) {
                        $lastpostid = $arr['lastpost'];
                        $post_arr = get_post_row($lastpostid) ?: [];
                        $lastposterid = $post_arr['userid'] ?? 0;
                        $lastpostdate = gettime($post_arr['added'] ?? '', true, false);
                        $lasttopicid = $arr['id'];
                        $hlcolor = $arr['hlcolor'];
                        $lasttopicdissubject = $lasttopicsubject = $arr['subject'];
                        $max_length_of_topic_subject = 35;
                        if (mb_strlen($lasttopicdissubject, 'UTF-8') > $max_length_of_topic_subject) {
                            $lasttopicdissubject = mb_substr($lasttopicdissubject, 0, $max_length_of_topic_subject - 2, 'UTF-8') . '..';
                        }
                        $lasttopic = $this->highlightTopic(htmlspecialchars($lasttopicdissubject), $hlcolor);

                        $lastpost = '<a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $lasttopicid . '&page=last#last') . '" title="' . htmlspecialchars($lasttopicsubject) . '">' . $lasttopic . '</a><br />' . $lastpostdate . '&nbsp;|&nbsp;' . get_username($lastposterid);
                        $lastreadpost = $this->getLastReadPostId($curUser, $lasttopicid);
                        if ($lastreadpost >= $lastpostid) {
                            $img = $this->getTopicImage('read', $lang);
                        } else {
                            $img = $this->getTopicImage('unread', $lang);
                        }
                    } else {
                        $lastpost = 'N/A';
                        $img = $this->getTopicImage('read', $lang);
                    }

                    $posttodaycount = $GLOBALS['Cache']->get_value('forum_' . $forumid . '_post_' . $todayDate . '_count');
                    if ($posttodaycount === '' || $posttodaycount === null) {
                        $posttodaycount = DB::table('posts')->join('topics', 'posts.topicid', '=', 'topics.id')
                            ->where('posts.added', '>', date('Y-m-d'))
                            ->where('topics.forumid', $forumid)
                            ->count('posts.id');
                        $GLOBALS['Cache']->cache_value('forum_' . $forumid . '_post_' . $todayDate . '_count', $posttodaycount, 1800);
                    }
                    if ($posttodaycount > 0) {
                        $posttoday = '&nbsp;&nbsp;(' . $lang['text_today'] . '<b><font class="new">' . $posttodaycount . '</font></b>)';
                    } else {
                        $posttoday = '';
                    }
                    print('<tr><td class="rowfollow" align="left"><table border="0" cellspacing="0" cellpadding="0"><tr><td class="embedded" style=\'padding-right: 10px\'>' . $img . '</td><td class="embedded"><a href="' . htmlspecialchars('?action=viewforum&forumid=' . $forumid) . '"><font class="big"><b>' . $forumname . '</b></font></a>' . $posttoday .
                        '<br />' . $forumdescription . '</td></tr></table></td><td class="rowfollow" align="center" width="1%">' . $topiccount . '</td><td class="rowfollow" align="center" width="1%">' . $postcount . '</td>' .
                        '<td class="rowfollow nowrap" align="left">' . $lastpost . '</td><td class="rowfollow" align="left">' . $forummoderators . '</td></tr>' . "\n");
                }
                ++$count;
            }
            print("</table>");
            if (get_setting('main.showforumstats', 'yes') == 'yes') {
                $this->forumStats($lang);
            }
            end_main_frame();
        });

        $pageTitle = $lang['head_forums'] ?? 'Forums';
        return view('forums', compact('content') + ['pageTitle' => $pageTitle, 'lang' => $lang]);
    }

    // ---------------------------------------------------------------------
    // Ported helpers (from legacy public/forums.php)
    // ---------------------------------------------------------------------

    /**
     * Forum stats line, mirrors legacy `forum_stats()`.
     */
    private function forumStats(array $lang)
    {
        $cache = $GLOBALS['Cache'];
        if (!$activeforumuser_num = $cache->get_value('active_forum_user_count')) {
            $secs = 900;
            $dt = date('Y-m-d H:i:s', (TIMENOW - $secs));
            $activeforumuser_num = DB::table('users')->where('forum_access', '>=', $dt)->count();
            $cache->cache_value('active_forum_user_count', $activeforumuser_num, 300);
        }
        if ($activeforumuser_num) {
            $forumusers = $lang['text_there'] . is_or_are($activeforumuser_num) . '<b>' . $activeforumuser_num . '</b>' . $lang['text_online_user'] . add_s($activeforumuser_num) . $lang['text_in_forum_now'];
        } else {
            $forumusers = $lang['text_no_active_users'];
        }
        print('<h2 align="left">' . $lang['text_stats'] . '</h2>');
        print('<table width="100%"><tr><td class="text">');
        if (!$postcount = $cache->get_value('total_posts_count')) {
            $postcount = DB::table('posts')->count();
            $cache->cache_value('total_posts_count', $postcount, 96400);
        }
        if (!$topiccount = $cache->get_value('total_topics_count')) {
            $topiccount = DB::table('topics')->count();
            $cache->cache_value('total_topics_count', $topiccount, 96500);
        }
        if (!$todaypostcount = $cache->get_value('today_' . $this->todayDate . '_posts_count')) {
            $todaypostcount = DB::table('posts')->where('added', '>', date('Y-m-d'))->count();
            $cache->cache_value('today_' . $this->todayDate . '_posts_count', $todaypostcount, 700);
        }
        print($lang['text_our_members_have'] . '<b>' . $postcount . '</b>' . $lang['text_posts_in_topics'] . '<b>' . $topiccount . '</b>' . $lang['text_in_topics'] . '<b><font class="new">' . $todaypostcount . '</font></b>' . $lang['text_new_post'] . add_s($todaypostcount) . $lang['text_posts_today'] . '<br /><br />');
        print($forumusers);
        print('</td></tr></table>');
    }

    /**
     * Mark all topics as read, mirrors legacy `catch_up()`.
     */
    private function catchUp(array $curUser)
    {
        DB::table('readposts')->where('userid', $curUser['id'])->delete();
        $GLOBALS['Cache']->delete_value('user_' . $curUser['id'] . '_last_read_post_list');
        $lastpostid = (int) DB::table('posts')->orderByDesc('id')->value('id');
        if ($lastpostid) {
            User::query()->whereKey($curUser['id'])->update(['last_catchup' => $lastpostid]);
        }
    }

    /**
     * Topic status image, mirrors legacy `get_topic_image()`.
     */
    private function getTopicImage($status, array $lang)
    {
        switch ($status) {
            case 'read':
                return '<img class="unlocked" src="pic/trans.gif" alt="read" title="' . $lang['title_read'] . '" />';
            case 'unread':
                return '<img class="unlockednew" src="pic/trans.gif" alt="unread" title="' . $lang['title_unread'] . '" />';
            case 'locked':
                return '<img class="locked" src="pic/trans.gif" alt="locked" title="' . $lang['title_locked'] . '" />';
            case 'lockednew':
                return '<img class="lockednew" src="pic/trans.gif" alt="lockednew" title="' . $lang['title_locked_new'] . '" />';
            default:
                return '';
        }
    }

    /**
     * Wrap a subject in the highlight colour, mirrors legacy `highlight_topic()`.
     */
    private function highlightTopic($subject, $hlcolor = 0)
    {
        $colorname = get_hl_color($hlcolor);
        if ($colorname) {
            $subject = '<b><font color="' . $colorname . '">' . $subject . '</font></b>';
        }
        return $subject;
    }

    /**
     * Verify an id exists in the requested table, mirrors legacy `check_whether_exist()`.
     */
    private function checkWhetherExist($id, $place = 'forum', array $lang)
    {
        switch ($place) {
            case 'forum':
                if (!Forum::query()->whereKey($id)->exists()) {
                    abort(400, $lang['std_no_forum_id'] ?? 'No forum with that ID.');
                }
                break;
            case 'topic':
                $topic = Topic::query()->find($id);
                if (!$topic) {
                    abort(400, $lang['std_bad_topic_id'] ?? 'Bad topic ID.');
                }
                $this->checkWhetherExist($topic->forumid, 'forum', $lang);
                break;
            case 'post':
                $post = Post::query()->find($id);
                if (!$post) {
                    abort(400, $lang['std_no_post_id'] ?? 'No post with that ID.');
                }
                $this->checkWhetherExist($post->topicid, 'topic', $lang);
                break;
        }
    }

    /**
     * Update the `lastpost` of a topic to its latest post, mirrors legacy `update_topic_last_post()`.
     */
    private function updateTopicLastPost($topicid, array $lang)
    {
        $lastpostid = (int) Post::query()->where('topicid', $topicid)->orderByDesc('id')->value('id');
        if (!$lastpostid) {
            abort(400, $lang['std_no_post_found'] ?? 'No post found.');
        }
        Topic::query()->whereKey($topicid)->update(['lastpost' => $lastpostid]);
    }

    /**
     * All forums keyed by id, mirrors legacy `get_forum_row()`.
     */
    private function getForums(): array
    {
        $cache = $GLOBALS['Cache'];
        if (!$forums = $cache->get_value('forums_list')) {
            $forums = [];
            foreach (Forum::query()->orderBy('forid')->orderBy('sort')->get() as $row) {
                $forums[$row->id] = $row->toArray();
            }
            $cache->cache_value('forums_list', $forums, 86400);
        }
        return is_array($forums) ? $forums : [];
    }

    /**
     * Last read post id for the current user & topic, mirrors legacy `get_last_read_post_id()`.
     */
    private function getLastReadPostId(array $curUser, $topicid)
    {
        $cache = $GLOBALS['Cache'];
        static $ret = null;
        if ($ret === null) {
            $ret = $cache->get_value('user_' . $curUser['id'] . '_last_read_post_list');
        }
        if (!$ret) {
            $rows = DB::table('readposts')->where('userid', $curUser['id'])->get();
            $ret = [];
            if (count($rows)) {
                foreach ($rows as $row) {
                    $ret[$row->topicid] = $row->lastpostread;
                }
                $cache->cache_value('user_' . $curUser['id'] . '_last_read_post_list', $ret, 900);
            } else {
                $cache->cache_value('user_' . $curUser['id'] . '_last_read_post_list', 'no record', 900);
            }
        }
        if ($ret != 'no record' && isset($ret[$topicid]) && ($curUser['last_catchup'] ?? 0) < $ret[$topicid]) {
            return $ret[$topicid];
        } elseif ($curUser['last_catchup'] ?? 0) {
            return $curUser['last_catchup'];
        }
        return 0;
    }

    /**
     * Compose frame, mirrors legacy `insert_compose_frame()`.
     */
    private function insertComposeFrame($id, $type = 'new', array $curUser, array $lang)
    {
        $hassubject = false;
        $subject = '';
        $body = '';
        print('<form id="compose" method="post" name="compose" action="?action=post">' . "\n");
        switch ($type) {
            case 'new':
                $forumname = (string) Forum::query()->whereKey($id)->value('name');
                $title = $lang['text_new_topic_in'] . ' <a href="' . htmlspecialchars('?action=viewforum&forumid=' . $id) . '">' . htmlspecialchars($forumname) . '</a> ' . $lang['text_forum'];
                $hassubject = true;
                break;
            case 'reply':
                $topicname = (string) Topic::query()->whereKey($id)->value('subject');
                $title = $lang['text_reply_to_topic'] . ' <a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $id) . '">' . htmlspecialchars($topicname) . '</a> ';
                break;
            case 'quote':
                $post = Post::query()->find($id);
                $topicid = (int) $post->topicid;
                $topicname = (string) Topic::query()->whereKey($topicid)->value('subject');
                $title = $lang['text_reply_to_topic'] . ' <a href="' . htmlspecialchars('?action=viewtopic&topicid=' . $topicid) . '">' . htmlspecialchars($topicname) . '</a> ';
                $body = '[quote=' . htmlspecialchars((string) $post->user?->username) . ']' . htmlspecialchars(unesc((string) $post->body)) . '[/quote]';
                $postid = $id;
                $id = $topicid;
                $type = 'reply';
                print('<input type="hidden" name="postid" value="' . $postid . '" />');
                break;
            case 'edit':
                $post = Post::query()->find($id);
                $topicid = (int) $post->topicid;
                $firstpost = Post::query()->where('topicid', $topicid)->min('id');
                if ($firstpost == $id) {
                    $subject = (string) Topic::query()->whereKey($topicid)->value('subject');
                    $hassubject = true;
                }
                $body = htmlspecialchars(unesc((string) $post->body));
                $title = $lang['text_edit_post'];
                break;
            default:
                abort(400);
        }
        print('<input type="hidden" name="id" value="' . $id . '" />');
        print('<input type="hidden" name="type" value="' . $type . '" />');
        begin_compose($title, $type, $body, $hassubject, $subject);
        end_compose();
        print('</form>');
    }

    /**
     * Restrict a search query to posts matching the keyword in the topic
     * subject (first post only) or in the post body, mirrors legacy SQL:
     * ((topics.subject LIKE '%kw%' AND posts.id=topics.firstpost) OR posts.body LIKE '%kw%')
     */
    private function applySearchKeywords($query, string $keyword)
    {
        if ($keyword === '') {
            return $query;
        }
        $pattern = '%' . $keyword . '%';
        $query->where(function ($q) use ($pattern) {
            $q->where(function ($q2) use ($pattern) {
                $q2->where('topics.subject', 'like', $pattern)
                    ->whereColumn('posts.id', 'topics.firstpost');
            })->orWhere('posts.body', 'like', $pattern);
        });

        return $query;
    }

    private function invalidIdAbort()
    {
        abort(400);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------------
    // Existing resource API methods
    // ---------------------------------------------------------------------

    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        $forId = $request->forid;
        $query = Forum::query()->orderBy('sort', 'asc')->with('moderators');
        if ($forId) {
            $query->where('forid', $forId);
        }
        $list = $query->get();
        $resource = ForumResource::collection($list);
        return $this->success($resource);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function show(OverForum $overForum)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function edit(OverForum $overForum)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, OverForum $overForum)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\OverForum  $overForum
     * @return \Illuminate\Http\Response
     */
    public function destroy(OverForum $overForum)
    {
        //
    }
}