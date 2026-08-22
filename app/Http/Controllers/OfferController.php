<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Message;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\StaffMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Offer management page. Mirrors legacy public/offers.php so the whole
 * offers.php/?action=* surface keeps working under the Laravel router.
 *
 * Dispatched by the query string, exactly like the legacy script:
 *   add_offer / new_offer / off_details / allow_offer / finish_offer /
 *   edit_offer / take_off_edit / offer_vote / vote / del_offer.
 */
class OfferController extends Controller
{
    /**
     * Legacy user-class constants live in include/core.php which is not loaded
     * by the Laravel bootstrap; a few legacy helpers compare against them.
     */
    private const USER_CLASS_CONSTANTS = [
        'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
        'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
        'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
        'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
        'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
    ];

    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }

        $lang = get_legacy_lang_file('offers');
        $this->bootstrap($curUser, $lang);

        if ($GLOBALS['enableoffer'] == 'no') {
            abort(403, $lang['std_access_denied']);
        }

        // Legacy helpers (pager, genrelist, ...) read the request globals.
        $_GET = $request->query();
        $_REQUEST = $request->all();

        // === add offer (form)
        if ($request->query('add_offer')) {
            if (! user_can('addoffer', false, $currentUser->id)) {
                abort(403, $lang['std_access_denied']);
            }

            return $this->showAddForm($curUser, $lang);
        }

        // === take new offer
        if ($request->query('new_offer')) {
            if (! user_can('addoffer', false, $currentUser->id)) {
                abort(403, $lang['std_access_denied']);
            }

            return $this->createOffer($request, $curUser, $lang);
        }

        // === offer details
        if ($request->query('off_details')) {
            return $this->showDetails($request, $curUser, $lang);
        }

        // === allow offer by staff
        if ($request->query('allow_offer')) {
            if (! user_can('offermanage', false, $currentUser->id)) {
                return $this->messagePage($lang['std_access_denied'], $lang['std_mans_job'], $lang['std_error']);
            }

            return $this->allowOffer($request, $curUser, $lang);
        }

        // === allow offer by vote (close the poll)
        if ($request->query('finish_offer')) {
            if (! user_can('offermanage', false, $currentUser->id)) {
                return $this->messagePage($lang['std_access_denied'], $lang['std_have_no_permission'], $lang['std_error']);
            }

            return $this->finishOffer($request, $curUser, $lang);
        }

        // === edit offer (form)
        if ($request->query('edit_offer')) {
            return $this->showEditForm($request, $curUser, $lang);
        }

        // === take offer edit
        if ($request->query('take_off_edit')) {
            return $this->updateOffer($request, $curUser, $lang);
        }

        // === offer votes list
        if ($request->query('offer_vote')) {
            return $this->showVotes($request, $curUser, $lang);
        }

        // === cast a vote
        if ($request->query('vote')) {
            return $this->vote($request, $curUser, $lang);
        }

        // === delete offer
        if ($request->query('del_offer')) {
            return $this->deleteOffer($request, $curUser, $lang);
        }

        // === main offer list
        return $this->index($request, $curUser, $lang);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Globals the shared legacy helpers expect (mirrors public/offers.php bootstrap).
     */
    private function bootstrap(array $curUser, array $lang): void
    {
        foreach (self::USER_CLASS_CONSTANTS as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['lang_offers'] = $lang;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['enableoffer'] = get_setting('main.showoffer', 'no');
        $GLOBALS['browsecatmode'] = (int) get_setting('main.browsecat', 0);
        $GLOBALS['minoffervotes'] = (int) get_setting('main.minoffervotes', 1);
        $GLOBALS['offervotetimeout_main'] = (int) get_setting('main.offervotetimeout', 0);
        $GLOBALS['offeruptimeout_main'] = (int) get_setting('main.offeruptimeout', 0);
        $GLOBALS['offervote_bonus'] = (float) get_setting('bonus.offervote', 0);
        $GLOBALS['addoffer_class'] = (int) get_setting('authority.addoffer', 0);
        $GLOBALS['offermanage_class'] = (int) get_setting('authority.offermanage', 0);
        $GLOBALS['upload_class'] = (int) get_setting('authority.upload', 0);
        $GLOBALS['againstoffer_class'] = (int) get_setting('authority.againstoffer', 0);
        $GLOBALS['commanage_class'] = (int) get_setting('authority.commanage', 0);
        $GLOBALS['bonus_tweak'] = get_setting('tweak.bonus', '');
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }

    /**
     * Full-page message (mirrors the legacy stdmsg()/stderr() box) rendered
     * through Blade instead of stderr()/stdhead()/stdfoot().
     */
    private function messagePage(string $heading, string $message, string $pageTitle = 'Error', bool $htmlstrip = true)
    {
        if ($htmlstrip) {
            $heading = htmlspecialchars(trim($heading));
            $message = htmlspecialchars(trim($message));
        }

        return view('offer.message', compact('heading', 'message', 'pageTitle'));
    }

    // ------------------------------------------------------------- add offer

    private function showAddForm(array $curUser, array $lang)
    {
        $content = '<p>' . $lang['text_red_star_required'] . "</p>\n";
        $content .= '<div align="center"><form id="compose" action="?new_offer=1" name="compose" method="post">';
        $content .= '<table width=100% border=0 cellspacing=0 cellpadding=5><tr><td class=colhead align=center colspan=2>' . $lang['text_offers_open_to_all'] . "</td></tr>\n";

        $s = "<select name=type>\n<option value=0>" . $lang['select_type_select'] . "</option>\n";
        foreach (genrelist($GLOBALS['browsecatmode']) as $row) {
            $s .= '<option value=' . $row['id'] . '>' . htmlspecialchars($row['name']) . "</option>\n";
        }
        $s .= "</select>\n";

        $content .= '<tr><td class=rowhead align=right><b>' . $lang['row_type'] . '<font color=red>*</font></b></td><td class=rowfollow align=left> ' . $s . '</td></tr>';
        $content .= '<tr><td class=rowhead align=right><b>' . $lang['row_title'] . '<font color=red>*</font></b></td><td class=rowfollow align=left><input type=text name=name style="width: 99%;" />';
        $content .= '</td></tr><tr><td class=rowhead align=right><b>' . $lang['row_post_or_photo'] . '</b></td><td class=rowfollow align=left>';
        $content .= '<input type=text name=picture style="width: 99%;"><br />' . $lang['text_link_to_picture'] . '</td></tr>';
        $content .= '<tr><td class=rowhead align=right valign=top><b>' . $lang['row_description'] . '<b><font color=red>*</font></td><td class=rowfollow align=left>' . "\n";
        $content .= $this->capture(function () {
            textbbcode('compose', 'body', '', false, 130, true);
        });
        $content .= '</td></tr><tr><td class=toolbox align=center colspan=2><input id=qr type=submit class=btn value=' . $lang['submit_add_offer'] . ' ></td></tr></table></form><br />' . "\n";

        return view('offer.index', [
            'content' => $content,
            'pageTitle' => $lang['head_offer'],
        ]);
    }

    // --------------------------------------------------------- take new offer

    private function createOffer(Request $request, array $curUser, array $lang)
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->messagePage($lang['std_error'], $lang['std_must_enter_name'], $lang['head_offer_error']);
        }

        $cat = (int) $request->input('type', 0);
        if (! is_valid_id($cat)) {
            return $this->messagePage($lang['std_error'], $lang['std_must_select_category'], $lang['head_offer_error']);
        }

        $descrmain = unesc((string) $request->input('body', ''));
        if (! $descrmain) {
            return $this->messagePage($lang['std_error'], $lang['std_must_enter_description'], $lang['head_offer_error']);
        }

        $pic = '';
        if (! empty($request->input('picture'))) {
            $picture = unesc((string) $request->input('picture'));
            if (! preg_match("/^https?:\/\/[^\s'\"<>]+\.(jpg|gif|png)$/i", $picture)) {
                return $this->messagePage($lang['std_error'], $lang['std_wrong_image_format'], $lang['head_offer_error']);
            }
            $pic = '[img]' . $picture . "[/img]\n";
        }

        $descr = $pic . $descrmain;

        $exists = Offer::query()->where('name', $name)->exists();
        if ($exists) {
            return $this->messagePage(
                $lang['std_error'],
                $lang['std_offer_exists'] . '<a class=altlink href=offers.php>' . $lang['text_view_all_offers'] . '</a>',
                $lang['head_error'],
                false
            );
        }

        $offer = Offer::query()->create([
            'userid' => $curUser['id'],
            'name' => $name,
            'descr' => $descr,
            'category' => $cat,
            'added' => date('Y-m-d H:i:s'),
        ]);

        // add new offer message to staffmessage
        StaffMessage::query()->insert([
            'sender' => $curUser['id'],
            'subject' => nexus_trans('offer.msg_new_offer_subject'),
            'msg' => nexus_trans('offer.msg_new_offer_msg', [
                'username' => "[url=userdetails.php?id={$curUser['id']}]{$curUser['username']}[/url]",
                'offername' => "[url=offers.php?id={$offer->id}&off_details=1]{$name}[/url]",
            ]),
            'added' => now(),
        ]);
        clear_staff_message_cache();

        write_log("offer $name was added by " . $curUser['username'], 'normal');

        return redirect('offers.php?id=' . $offer->id . '&off_details=1');
    }

    // ---------------------------------------------------------- offer details

    private function showDetails(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);
        if (! $id) {
            return response('', 200);
        }

        $offer = Offer::query()->where('id', $id)->first();
        if (! $offer) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }

        $s = $offer->name;
        $content = '<h1 align="center" id="top">' . htmlspecialchars($s) . "</h1>\n";
        $content .= '<table width="97%" cellspacing="0" cellpadding="5">';

        $offertime = gettime($offer->added, true, false);
        if (($curUser['timetype'] ?? '') != 'timealive') {
            $offertime = $lang['text_at'] . $offertime;
        } else {
            $offertime = $lang['text_blank'] . $offertime;
        }
        $content .= tr($lang['row_info'], $lang['text_offered_by'] . get_username($offer->userid) . $offertime, 1, '', true);

        if ($offer->allowed == 'pending') {
            $status = '<font color="red">' . $lang['text_pending'] . '</font>';
        } elseif ($offer->allowed == 'allowed') {
            $status = '<font color="green">' . $lang['text_allowed'] . '</font>';
        } else {
            $status = '<font color="red">' . $lang['text_denied'] . '</font>';
        }
        $content .= tr($lang['row_status'], $status, 1, '', true);

        if (user_can('offermanage') && $offer->allowed == 'pending') {
            $allowForm = '<table><tr><td class="embedded"><form method="post" action="?allow_offer=1">'
                . '<input type="hidden" value="' . $id . '" name="offerid" />'
                . '<input class="btn" type="submit" value="' . $lang['submit_allow'] . '" />&nbsp;&nbsp;</form></td>'
                . '<td class="embedded"><form method="post" action="?id=' . $id . '&amp;finish_offer=1">'
                . '<input type="hidden" value="' . $id . '" name="finish" />'
                . '<input class="btn" type="submit" value="' . $lang['submit_let_votes_decide'] . '" /></form></td></tr></table>';
            $content .= tr($lang['row_allow'], $allowForm, 1, '', true);
        }

        $za = DB::table('offervotes')->where('offerid', $id)->where('vote', 'yeah')->count();
        $protiv = DB::table('offervotes')->where('offerid', $id)->where('vote', 'against')->count();

        if ($offer->allowed == 'pending') {
            $againstLink = user_can('againstoffer')
                ? ' - <b><a href="?id=' . $id . '&amp;vote=against"><font color="red">' . $lang['text_against'] . '</font></a></b>'
                : '';
            $content .= tr(
                $lang['row_vote'],
                '<b><a href="?id=' . $id . '&amp;vote=yeah"><font color="green">' . $lang['text_for'] . '</font></a></b>' . $againstLink,
                1, '', true
            );
            $content .= tr(
                $lang['row_vote_results'],
                '<b>' . $lang['text_for'] . ':</b> ' . $za . '  <b>' . $lang['text_against'] . '</b> ' . $protiv
                . ' &nbsp; &nbsp; <a href="?id=' . $id . '&amp;offer_vote=1"><i>' . $lang['text_see_vote_detail'] . '</i></a>',
                1, '', true
            );
        }

        if ($offer->allowed == 'allowed' && $curUser['id'] != $offer->userid) {
            $content .= tr($lang['row_offer_allowed'], $lang['text_voter_receives_pm_note'], 1, '', true);
        }
        if ($offer->allowed == 'allowed' && $curUser['id'] == $offer->userid) {
            $content .= tr($lang['row_offer_allowed'], $lang['text_urge_upload_offer_note'], 1, '', true);
        }

        $edit = $delete = '';
        if ($curUser['id'] == $offer->userid || user_can('offermanage')) {
            $edit = '<a href="?id=' . $id . '&amp;edit_offer=1"><img class="dt_edit" src="pic/trans.gif" alt="edit" />&nbsp;<b><font class="small">' . $lang['text_edit_offer'] . '</font></b></a>&nbsp;|&nbsp;';
            $delete = '<a href="?id=' . $id . '&amp;del_offer=1&amp;sure=0"><img class="dt_delete" src="pic/trans.gif" alt="delete" />&nbsp;<b><font class="small">' . $lang['text_delete_offer'] . '</font></b></a>&nbsp;|&nbsp;';
        }
        $report = '<a href="report.php?reportofferid=' . $id . '"><img class="dt_report" src="pic/trans.gif" alt="report" />&nbsp;<b><font class="small">' . $lang['report_offer'] . '</font></b></a>';
        $content .= tr($lang['row_action'], $edit . $delete . $report, 1, '', true);

        if ($offer->descr) {
            $content .= tr($lang['row_description'], format_comment($offer->descr), 1, '', true);
        }
        $content .= '</table>';

        // -----------------COMMENT SECTION ---------------------//
        $commentbar = '<p align="center"><a class="index" href="comment.php?action=add&amp;pid=' . $id . '&amp;type=offer">' . $lang['text_add_comment'] . "</a></p>\n";

        $count = Comment::query()->where('offer', $id)->count();
        if (! $count) {
            $content .= '<h1 id="startcomments" align="center">' . $lang['text_no_comments'] . "</h1>\n";
        } else {
            [$pagertop, $pagerbottom, $limit] = pager(10, $count, 'offers.php?id=' . $id . '&off_details=1&', ['lastpagedefault' => 1]);

            preg_match('/limit\s+(\d+)\s+offset\s+(\d+)/', $limit, $limitMatch);
            $commentRows = Comment::query()
                ->select('id', 'text', 'user', 'added', 'editedby', 'editdate')
                ->where('offer', $id)
                ->orderBy('id')
                ->limit((int) ($limitMatch[1] ?? 10))
                ->offset((int) ($limitMatch[2] ?? 0))
                ->get()
                ->map(fn ($row) => $row->toArray())
                ->all();

            $content .= $pagertop;
            if (empty($GLOBALS['Advertisement'])) {
                require_once ROOT_PATH . 'classes/class_advertisement.php';
                $GLOBALS['Advertisement'] = new \ADVERTISEMENT($curUser['id']);
            }
            $content .= $this->capture(function () use ($commentRows, $id) {
                commenttable($commentRows, 'offer', $id);
            });
            $content .= $pagerbottom;
        }

        $content .= '<table style="border:1px solid #000000;"><tr>'
            . '<td class="text" align="center"><b>' . $lang['text_quick_comment'] . '</b><br /><br />'
            . '<form id="compose" name="comment" method="post" action="comment.php?action=add&amp;type=offer" onsubmit="return postvalid(this);">'
            . '<input type="hidden" name="pid" value="' . $id . '" /><br />';
        $content .= $this->capture(function () use ($lang) {
            quickreply('comment', 'body', $lang['submit_add_comment']);
        });
        $content .= '</form></td></tr></table>';
        $content .= $commentbar;

        return view('offer.details', [
            'content' => $content,
            'pageTitle' => $lang['head_offer_detail_for'] . ' "' . $s . '"',
        ]);
    }

    // -------------------------------------------------------- allow by staff

    private function allowOffer(Request $request, array $curUser, array $lang)
    {
        $offid = (int) $request->input('offerid', 0);
        if (! is_valid_id($offid)) {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }

        $arr = Offer::query()
            ->join('users', 'offers.userid', '=', 'users.id')
            ->where('offers.id', $offid)
            ->first(['users.username', 'offers.userid', 'offers.name']);
        if (! $arr) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }

        $locale = get_user_locale($arr['userid']);
        if ($GLOBALS['offeruptimeout_main']) {
            $timeouthour = floor($GLOBALS['offeruptimeout_main'] / 3600);
            $timeoutnote = nexus_trans('offer.msg_you_must_upload_in', [], $locale) . $timeouthour
                . nexus_trans('offer.msg_hours_otherwise', [], $locale);
        } else {
            $timeoutnote = '';
        }

        $msg = $curUser['username'] . nexus_trans('offer.msg_has_allowed', [], $locale)
            . '[b][url=' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offid . '&off_details=1]'
            . $arr['name'] . '[/url][/b]. ' . nexus_trans('offer.msg_find_offer_option', [], $locale) . $timeoutnote;
        $subject = nexus_trans('offer.msg_your_offer_allowed', [], $locale);
        $allowedtime = date('Y-m-d H:i:s');

        Message::add([
            'sender' => 0,
            'receiver' => $arr['userid'],
            'msg' => $msg,
            'subject' => $subject,
            'added' => $allowedtime,
        ]);

        Offer::query()->where('id', $offid)->update([
            'allowed' => 'allowed',
            'allowedtime' => $allowedtime,
        ]);

        write_log($curUser['username'] . ' allowed offer ' . $arr['name'], 'normal');

        return redirect(get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offid . '&off_details=1');
    }

    // ------------------------------------------------------- allow offer by vote

    private function finishOffer(Request $request, array $curUser, array $lang)
    {
        $offid = (int) $request->input('finish', 0);
        if (! is_valid_id($offid)) {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }

        $arr = Offer::query()
            ->join('users', 'offers.userid', '=', 'users.id')
            ->where('offers.id', $offid)
            ->first(['users.username', 'offers.userid', 'offers.name']);
        if (! $arr) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }

        $locale = get_user_locale($arr['userid']);

        $yes = DB::table('offervotes')->where('offerid', $offid)->where('vote', 'yeah')->count();
        $no = DB::table('offervotes')->where('offerid', $offid)->where('vote', 'against')->count();

        if ($yes == 0 && $no == 0) {
            return $this->messagePage(
                $lang['std_sorry'],
                $lang['std_no_votes_yet'] . '<a href=offers.php?id=' . $offid . '&off_details=1>' . $lang['std_back_to_offer_detail'] . '</a>',
                $lang['std_sorry'],
                false
            );
        }

        $finishvotetime = date('Y-m-d H:i:s');
        $msg = '';
        if (($yes - $no) >= $GLOBALS['minoffervotes']) {
            if ($GLOBALS['offeruptimeout_main']) {
                $timeouthour = floor($GLOBALS['offeruptimeout_main'] / 3600);
                $timeoutnote = nexus_trans('offer.msg_you_must_upload_in', [], $locale) . $timeouthour
                    . nexus_trans('offer.msg_hours_otherwise', [], $locale);
            } else {
                $timeoutnote = '';
            }
            $msg = nexus_trans('offer.msg_offer_voted_on', [], $locale)
                . '[b][url=' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offid . '&off_details=1]'
                . $arr['name'] . '[/url][/b]. ' . nexus_trans('offer.msg_find_offer_option', [], $locale) . $timeoutnote;
            Offer::query()->where('id', $offid)->update([
                'allowed' => 'allowed',
                'allowedtime' => $finishvotetime,
            ]);
        } elseif (($no - $yes) >= $GLOBALS['minoffervotes']) {
            $msg = nexus_trans('offer.msg_offer_voted_off', [], $locale)
                . '[b][url=' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offid . '&off_details=1]'
                . $arr['name'] . '[/url][/b].' . nexus_trans('offer.msg_offer_deleted', [], $locale);
            Offer::query()->where('id', $offid)->update(['allowed' => 'denied']);
        }

        if ($msg !== '') {
            $subject = nexus_trans('offer.msg_your_offer', [], $locale) . $arr['name']
                . nexus_trans('offer.msg_voted_on', [], $locale);

            Message::add([
                'sender' => 0,
                'subject' => $subject,
                'receiver' => $arr['userid'],
                'added' => $finishvotetime,
                'msg' => $msg,
            ]);
        }

        write_log($curUser['username'] . ' closed poll ' . $arr['name'], 'normal');

        return redirect(get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offid . '&off_details=1');
    }

    // ------------------------------------------------------------ edit offer

    private function showEditForm(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);

        $num = Offer::query()->where('id', $id)->first();
        if (! $num) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }

        if ($curUser['id'] != $num->userid && ! user_can('offermanage')) {
            return $this->messagePage($lang['std_error'], $lang['std_cannot_edit_others_offer'], $lang['std_error']);
        }

        $body = htmlspecialchars(unesc((string) $num->descr));
        $s2 = "<select name=\"category\">\n";
        foreach (genrelist($GLOBALS['browsecatmode']) as $row) {
            $s2 .= '<option value="' . $row['id'] . '" ' . ($row['id'] == $num->category ? ' selected="selected"' : '') . '>'
                . htmlspecialchars($row['name']) . "</option>\n";
        }
        $s2 .= "</select>\n";

        $title = htmlspecialchars(trim((string) $num->name));

        $content = '<form id="compose" method="post" name="compose" action="?id=' . $id . '&amp;take_off_edit=1">';
        $content .= '<table width="97%" cellspacing="0" cellpadding="3"><tr><td class="colhead" align="center" colspan="2">'
            . $lang['text_edit_offer'] . '</td></tr>';
        $content .= tr($lang['row_type'] . '<font color="red">*</font>', $s2, 1, '', true);
        $content .= tr($lang['row_title'] . '<font color="red">*</font>', '<input type="text" style="width: 99%" name="name" value="' . $title . '" />', 1, '', true);
        $content .= tr($lang['row_post_or_photo'], "<input type=\"text\" name=\"picture\" style=\"width: 99%\" value='' /><br />" . $lang['text_link_to_picture'], 1, '', true);
        $content .= '<tr><td class="rowhead" align="right" valign="top"><b>' . $lang['row_description'] . '<font color="red">*</font></b></td><td class="rowfollow" align="left">';
        $content .= $this->capture(function () use ($body) {
            textbbcode('compose', 'body', $body, false, 130, true);
        });
        $content .= '</td></tr>';
        $content .= '<tr><td class="toolbox" style="vertical-align: middle; padding-top: 10px; padding-bottom: 10px;" align="center" colspan="2">'
            . '<input id="qr" type="submit" value="' . $lang['submit_edit_offer'] . '" class="btn" /></td></tr></table></form><br />' . "\n";

        return view('offer.index', [
            'content' => $content,
            'pageTitle' => $lang['head_edit_offer'] . ': ' . $num->name,
        ]);
    }

    // ------------------------------------------------------- take offer edit

    private function updateOffer(Request $request, array $curUser, array $lang)
    {
        $id = (int) $request->query('id', 0);

        $num = Offer::query()->where('id', $id)->first(['userid']);
        if (! $num) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }

        if ($curUser['id'] != $num->userid && ! user_can('offermanage')) {
            return $this->messagePage($lang['std_error'], $lang['std_access_denied'], $lang['std_error']);
        }

        $name = trim((string) $request->input('name', ''));
        $pic = '';
        if (! empty($request->input('picture'))) {
            $picture = unesc((string) $request->input('picture'));
            if (! preg_match("/^https?:\/\/[^\s'\"<>]+\.(jpg|gif|png)$/i", $picture)) {
                return $this->messagePage($lang['std_error'], $lang['std_wrong_image_format'], $lang['std_error']);
            }
            $pic = '[img]' . $picture . "[/img]\n";
        }
        $descr = $pic . unesc((string) $request->input('body', ''));

        if (! $name) {
            return $this->messagePage($lang['std_error'], $lang['std_must_enter_name'], $lang['head_offer_error']);
        }
        if (! $descr) {
            return $this->messagePage($lang['std_error'], $lang['std_must_enter_description'], $lang['head_offer_error']);
        }
        $cat = (int) $request->input('category', 0);
        if (! is_valid_id($cat)) {
            return $this->messagePage($lang['std_error'], $lang['std_must_select_category'], $lang['head_offer_error']);
        }

        Offer::query()->where('id', $id)->update([
            'category' => $cat,
            'name' => $name,
            'descr' => $descr,
        ]);

        return redirect('offers.php?id=' . $id . '&off_details=1');
    }

    // -------------------------------------------------------- offer votes list

    private function showVotes(Request $request, array $curUser, array $lang)
    {
        $offerid = (int) $request->query('id', 0);
        if (! is_valid_id($offerid)) {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }

        $count = DB::table('offervotes')->where('offerid', $offerid)->count();
        $offername = (string) Offer::query()->where('id', $offerid)->value('name');

        $content = '<h1 align=center>' . $lang['text_vote_results_for']
            . ' <a href=offers.php?id=' . $offerid . '&off_details=1><b>' . htmlspecialchars($offername) . '</b></a></h1>';

        $perpage = 25;
        [$pagertop, $pagerbottom, $limit] = pager($perpage, $count, 'offers.php?id=' . $offerid . '&offer_vote=1&');

        preg_match('/limit\s+(\d+)\s+offset\s+(\d+)/', $limit, $limitMatch);
        $votes = DB::table('offervotes')
            ->where('offerid', $offerid)
            ->orderBy('id')
            ->limit((int) ($limitMatch[1] ?? 25))
            ->offset((int) ($limitMatch[2] ?? 0))
            ->get();

        if ($votes->isEmpty()) {
            $content .= '<p align=center><b>' . $lang['std_no_votes_yet'] . "</b></p>\n";
        } else {
            $content .= $pagertop;
            $content .= '<table border=1 cellspacing=0 cellpadding=5><tr><td class=colhead>' . $lang['col_user']
                . '</td><td class=colhead align=left>' . $lang['col_vote'] . "</td>\n";
            foreach ($votes as $arr) {
                if ($arr->vote == 'yeah') {
                    $vote = '<b><font color=green>' . $lang['text_for'] . '</font></b>';
                } elseif ($arr->vote == 'against') {
                    $vote = '<b><font color=red>' . $lang['text_against'] . '</font></b>';
                } else {
                    $vote = 'unknown';
                }
                $content .= '<tr><td class=rowfollow>' . get_username($arr->userid) . '</td><td class=rowfollow align=left >' . $vote . "</td></tr>\n";
            }
            $content .= "</table>\n";
            $content .= $pagerbottom;
        }

        return view('offer.details', [
            'content' => $content,
            'pageTitle' => $lang['head_offer_voters'] . ' - "' . $offername . '"',
        ]);
    }

    // ------------------------------------------------------------- cast vote

    private function vote(Request $request, array $curUser, array $lang)
    {
        $offerid = (int) $request->query('id', 0);
        $vote = (string) $request->query('vote', '');

        if ($vote == 'against' && ! user_can('againstoffer')) {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }

        if ($vote !== 'yeah' && $vote !== 'against') {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }

        $userid = (int) $curUser['id'];

        $voted = DB::table('offervotes')
            ->where('offerid', $offerid)
            ->where('userid', $userid)
            ->exists();
        $offerUserid = (int) Offer::query()->where('id', $offerid)->value('userid');

        if ($offerUserid == $curUser['id']) {
            return $this->messagePage($lang['std_error'], $lang['std_cannot_vote_youself'], $lang['std_error']);
        }
        if ($voted) {
            return $this->messagePage(
                $lang['std_already_voted'],
                $lang['std_already_voted_note'] . '<a href=offers.php?id=' . $offerid . '&off_details=1>' . $lang['std_back_to_offer_detail'],
                $lang['std_already_voted'],
                false
            );
        }

        $arr = Offer::query()
            ->leftJoin('users', 'offers.userid', '=', 'users.id')
            ->where('offers.id', $offerid)
            ->first(['users.username', 'offers.userid', 'offers.name']);
        if (! $arr) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }

        Offer::query()->where('id', $offerid)->increment($vote);
        $locale = get_user_locale($arr['userid']);

        $yaArr = Offer::query()->where('id', $offerid)->first(['yeah', 'against', 'allowed']);
        $yeah = (int) $yaArr->yeah;
        $against = (int) $yaArr->against;
        $finishtime = date('Y-m-d H:i:s');

        // allowed and send offer voted on message
        if (($yeah - $against) >= $GLOBALS['minoffervotes'] && $yaArr->allowed != 'allowed') {
            if ($GLOBALS['offeruptimeout_main']) {
                $timeouthour = floor($GLOBALS['offeruptimeout_main'] / 3600);
                $timeoutnote = nexus_trans('offer.msg_you_must_upload_in', [], $locale) . $timeouthour
                    . nexus_trans('offer.msg_hours_otherwise', [], $locale);
            } else {
                $timeoutnote = '';
            }
            Offer::query()->where('id', $offerid)->update([
                'allowed' => 'allowed',
                'allowedtime' => $finishtime,
            ]);
            $msg = nexus_trans('offer.msg_offer_voted_on', [], $locale)
                . '[b][url=' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offerid . '&off_details=1]'
                . $arr['name'] . '[/url][/b]. ' . nexus_trans('offer.msg_find_offer_option', [], $locale) . $timeoutnote;
            $subject = nexus_trans('offer.msg_your_offer_allowed', [], $locale);

            Message::add([
                'sender' => 0,
                'receiver' => $arr['userid'],
                'msg' => $msg,
                'subject' => $subject,
                'added' => now(),
            ]);

            write_log('System allowed offer ' . $arr['name'], 'normal');
        }

        // denied and send offer voted off message
        if (($against - $yeah) >= $GLOBALS['minoffervotes'] && $yaArr->allowed != 'denied') {
            Offer::query()->where('id', $offerid)->update(['allowed' => 'denied']);
            $msg = nexus_trans('offer.msg_offer_voted_off', [], $locale)
                . '[b][url=' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/offers.php?id=' . $offerid . '&off_details=1]'
                . $arr['name'] . '[/url][/b].' . nexus_trans('offer.msg_offer_deleted', [], $locale);
            $subject = nexus_trans('offer.msg_offer_deleted', [], $locale);

            Message::add([
                'sender' => 0,
                'receiver' => $arr['userid'],
                'msg' => $msg,
                'subject' => $subject,
                'added' => now(),
            ]);

            write_log('System denied offer ' . $arr['name'], 'normal');
        }

        DB::table('offervotes')->insert([
            'offerid' => $offerid,
            'userid' => $userid,
            'vote' => $vote,
        ]);
        KPS('+', $GLOBALS['offervote_bonus'], $curUser['id']);

        $content = '<h1 align=center>' . $lang['std_vote_accepted'] . '</h1>'
            . $lang['std_vote_accepted_note'] . '<a href=offers.php?id=' . $offerid . '&off_details=1>'
            . $lang['std_back_to_offer_detail'];

        return view('offer.details', [
            'content' => $content,
            'pageTitle' => $lang['head_vote_for_offer'],
        ]);
    }

    // ------------------------------------------------------------ delete offer

    private function deleteOffer(Request $request, array $curUser, array $lang)
    {
        $offer = (int) $request->query('id', 0);
        $userid = (int) $curUser['id'];
        if (! is_valid_id($userid)) {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }

        $num = Offer::query()->where('id', $offer)->first();
        if (! $num) {
            return $this->messagePage($lang['std_error'], $lang['text_nothing_found'], $lang['head_offer_error']);
        }
        $name = $num->name;

        if ($userid != $num->userid && ! user_can('offermanage')) {
            return $this->messagePage($lang['std_error'], $lang['std_cannot_delete_others_offer'], $lang['std_error']);
        }

        $sure = $request->query('sure', '');
        if ($sure !== '0' && $sure !== '1') {
            return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
        }
        $sure = (int) $sure;

        if ($sure == 0) {
            return $this->messagePage(
                $lang['std_delete_offer'],
                $lang['std_delete_offer_note'] . '<br /><form method=post action=offers.php?id=' . $offer . '&del_offer=1&sure=1>'
                    . $lang['text_reason_is'] . '<input type=text style="width: 200px" name=reason>'
                    . '<input type=submit value="' . $lang['submit_confirm'] . '"></form>',
                $lang['std_delete_offer'],
                false
            );
        }

        // sure == 1
        $reason = (string) $request->input('reason', '');

        Offer::query()->where('id', $offer)->delete();
        DB::table('offervotes')->where('offerid', $offer)->delete();
        Comment::query()->where('offer', $offer)->delete();

        if ($curUser['id'] != $num->userid) {
            $locale = get_user_locale($num->userid);
            $subject = nexus_trans('offer.msg_offer_deleted', [], $locale);
            $msg = nexus_trans('offer.msg_your_offer', [], $locale) . $num->name
                . nexus_trans('offer.msg_was_deleted_by', [], $locale)
                . '[url=userdetails.php?id=' . $curUser['id'] . ']' . $curUser['username'] . '[/url]'
                . nexus_trans('offer.msg_blank', [], $locale)
                . ($reason != '' ? nexus_trans('offer.msg_reason_is', [], $locale) . $reason : '');

            Message::add([
                'sender' => 0,
                'receiver' => $num->userid,
                'msg' => $msg,
                'subject' => $subject,
                'added' => now(),
            ]);
        }

        write_log(
            'Offer: ' . $offer . ' (' . $num->name . ') was deleted by ' . $curUser['username']
            . ($reason != '' ? ' (' . $reason . ')' : ''),
            'normal'
        );

        return redirect('offers.php');
    }

    // ----------------------------------------------------------- main listing

    private function index(Request $request, array $curUser, array $lang)
    {
        $sort = '';
        if ($request->query('sort')) {
            $sort = (string) $request->query('sort');
            if (! in_array($sort, ['cat', 'name', 'added', 'comments', 'yeah', 'against', 'v_res'])) {
                return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
            }
        }

        $categ = (int) $request->query('category', 0);
        if ($request->query('category')) {
            $categ = (int) $request->query('category');
            if (! is_valid_id($categ)) {
                return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
            }
        }

        $offerorid = 0;
        if ($request->query('offerorid')) {
            $offerorid = (int) $request->query('offerorid');
            if (! preg_match('/^[0-9]+$/', (string) $offerorid)) {
                return $this->messagePage($lang['std_error'], $lang['std_smell_rat'], $lang['std_error']);
            }
        }

        $search = (string) $request->query('search', '');

        $catOrderType = $nameOrderType = $addedOrderType = $commentsOrderType = $vResOrderType = 'desc';
        if ($request->query('type') == 'desc') {
            if ($sort == 'cat') {
                $catOrderType = 'asc';
            } elseif ($sort == 'name') {
                $nameOrderType = 'asc';
            } elseif ($sort == 'added') {
                $addedOrderType = 'asc';
            } elseif ($sort == 'comments') {
                $commentsOrderType = 'asc';
            } elseif ($sort == 'v_res') {
                $vResOrderType = 'asc';
            }
        }

        $query = Offer::query()
            ->join('categories', 'offers.category', '=', 'categories.id')
            ->join('users', 'offers.userid', '=', 'users.id');

        if ($offerorid > 0) {
            $query->where('offers.userid', $offerorid);
        }
        if ($categ > 0) {
            $query->where('offers.category', $categ);
        }
        if ($search !== '') {
            $query->where('offers.name', 'like', '%' . $search . '%');
        }

        $orderBy = ['offers.added', 'desc'];
        if ($sort == 'cat') {
            $orderBy = ['offers.category', $catOrderType];
        } elseif ($sort == 'name') {
            $orderBy = ['offers.name', $nameOrderType];
        } elseif ($sort == 'added') {
            $orderBy = ['offers.added', $addedOrderType];
        } elseif ($sort == 'comments') {
            $orderBy = ['offers.comments', $commentsOrderType];
        } elseif ($sort == 'v_res') {
            $query->orderByRaw('(offers.yeah - offers.against) ' . $vResOrderType);
        }

        $rows = $query
            ->select([
                'offers.id', 'offers.userid', 'offers.name', 'offers.added', 'offers.allowedtime',
                'offers.comments', 'offers.yeah', 'offers.against', 'offers.category as cat_id',
                'offers.allowed', 'categories.image', 'categories.name as cat',
            ])
            ->when($sort != 'v_res', function ($q) use ($orderBy) {
                return $q->orderBy($orderBy[0], $orderBy[1]);
            })
            ->paginate(25, ['*'], 'page');

        // ---- render
        $content = '';
        $content .= $this->capture(function () use ($lang) {
            begin_main_frame();
            begin_frame($lang['text_offers_section'], true, 10, '100%', 'center');
        });

        $content .= '<p align="left"><b><font size="5">' . $lang['text_rules'] . '</font></b></p>' . "\n";
        $content .= '<div align="left"><ul>';
        $content .= '<li>' . $lang['text_rule_one_one']
            . get_user_class_name($GLOBALS['upload_class'], false, true, true)
            . $lang['text_rule_one_two']
            . get_user_class_name($GLOBALS['addoffer_class'], false, true, true)
            . $lang['text_rule_one_three'] . "</li>\n";
        $offerSkipApprovedCount = get_setting('main.offer_skip_approved_count');
        if (is_numeric($offerSkipApprovedCount) && $offerSkipApprovedCount > 0) {
            $content .= '<li>' . sprintf($lang['text_rule_skip_offer'], $offerSkipApprovedCount) . "</li>\n";
        }
        $content .= '<li>' . $lang['text_rule_two_one'] . '<b>' . $GLOBALS['minoffervotes'] . '</b>' . $lang['text_rule_two_two'] . "</li>\n";
        if ($GLOBALS['offervotetimeout_main']) {
            $content .= '<li>' . $lang['text_rule_three_one'] . '<b>' . ($GLOBALS['offervotetimeout_main'] / 3600) . '</b>' . $lang['text_rule_three_two'] . "</li>\n";
        }
        if ($GLOBALS['offeruptimeout_main']) {
            $content .= '<li>' . $lang['text_rule_four_one'] . '<b>' . ($GLOBALS['offeruptimeout_main'] / 3600) . '</b>' . $lang['text_rule_four_two'] . "</li>\n";
        }
        $content .= '</ul></div>';

        if (user_can('addoffer')) {
            $content .= '<div align="center" style="margin-bottom: 8px;"><a href="?add_offer=1"><b>' . $lang['text_add_offer'] . '</b></a></div>';
        }

        $catdropdown = '';
        foreach (genrelist($GLOBALS['browsecatmode']) as $catRow) {
            $catdropdown .= '<option value="' . $catRow['id'] . '">' . htmlspecialchars($catRow['name']) . "</option>\n";
        }
        $content .= '<div align="center"><form method="get" action="?">' . $lang['text_search_offers']
            . '&nbsp;&nbsp;<input type="text" id="specialboxg" name="search" />&nbsp;&nbsp;';
        $content .= '<select name="category"><option value="0">' . $lang['select_show_all'] . '</option>' . $catdropdown
            . '</select>&nbsp;&nbsp;<input type="submit" class="btn" value="' . $lang['submit_search'] . '" /></form></div>';
        $content .= $this->capture(function () {
            end_frame();
        });
        $content .= '<br /><br />';

        $lastOffer = strtotime((string) ($curUser['last_offer'] ?? ''));

        if ($rows->isEmpty()) {
            $content .= '<table align="center" class="main" width="500" border="0" cellpadding="0" cellspacing="0"><tr><td class="embedded"><h2>'
                . $lang['text_nothing_found'] . '</h2><table width="100%" border="1" cellspacing="0" cellpadding="10"><tr><td class="text">'
                . $lang['text_nothing_found'] . '</td></tr></table></td></tr></table>';
        } else {
            $catid = (string) $request->query('category', '');
            $content .= '<table class="torrents" cellspacing="0" cellpadding="5" width="100%">';
            $content .= '<tr><td class="colhead" style="padding: 0px"><a href="?category=' . $catid . '&amp;sort=cat&amp;type=' . $catOrderType . '">' . $lang['col_type'] . '</a></td>';
            $content .= '<td class="colhead" width="100%"><a href="?category=' . $catid . '&amp;sort=name&amp;type=' . $nameOrderType . '">' . $lang['col_title'] . '</a></td>';
            $content .= '<td colspan="3" class="colhead"><a href="?category=' . $catid . '&amp;sort=v_res&amp;type=' . $vResOrderType . '">' . $lang['col_vote_results'] . '</a></td>';
            $content .= '<td class="colhead"><a href="?category=' . $catid . '&amp;sort=comments&amp;type=' . $commentsOrderType . '"><img class="comments" src="pic/trans.gif" alt="comments" title="' . $lang['title_comment'] . '" />' . ($lang['col_comment'] ?? '') . '</a></td>';
            $content .= '<td class="colhead"><a href="?category=' . $catid . '&amp;sort=added&amp;type=' . $addedOrderType . '"><img class="time" src="pic/trans.gif" alt="time" title="' . $lang['title_time_added'] . '" /></a></td>';
            if ($GLOBALS['offervotetimeout_main'] > 0 && $GLOBALS['offeruptimeout_main'] > 0) {
                $content .= '<td class="colhead">' . $lang['col_timeout'] . '</td>';
            }
            $content .= '<td class="colhead">' . $lang['col_offered_by'] . '</td>'
                . (user_can('offermanage') ? '<td class="colhead">' . $lang['col_act'] . '</td>' : '') . "</tr>\n";

            $lastcomTooltip = [];
            $i = 0;
            foreach ($rows as $arr) {
                $arr = $arr->toArray();

                $addedby = get_username($arr['userid']);
                $comms = $arr['comments'];
                if ($comms == 0) {
                    $comment = '<a href="comment.php?action=add&amp;pid=' . $arr['id'] . '&amp;type=offer" title="' . $lang['title_add_comments'] . '">0</a>';
                } else {
                    if (! $lastcom = $GLOBALS['Cache']->get_value('offer_' . $arr['id'] . '_last_comment_content')) {
                        $lastcom = DB::table('comments')
                            ->where('offer', $arr['id'])
                            ->orderByDesc('added')
                            ->first(['user', 'added', 'text']);
                        $lastcom = $lastcom ? (array) $lastcom : null;
                        $GLOBALS['Cache']->cache_value('offer_' . $arr['id'] . '_last_comment_content', $lastcom, 1855);
                    }
                    $timestamp = strtotime((string) ($lastcom['added'] ?? ''));
                    $hasnewcom = isset($lastcom['user']) && $lastcom['user'] != $curUser['id'] && $timestamp >= $lastOffer;
                    if (($curUser['showlastcom'] ?? '') != 'no') {
                        $title = '';
                        $onmouseover = '';
                        if ($lastcom) {
                            if (($curUser['timetype'] ?? '') != 'timealive') {
                                $lastcomtime = $lang['text_at_time'] . $lastcom['added'];
                            } else {
                                $lastcomtime = $lang['text_blank'] . gettime($lastcom['added'], true, false, true);
                            }
                            $counter = $i;
                            $lastcomTooltip[$counter]['id'] = 'lastcom_' . $counter;
                            $lastcomTooltip[$counter]['content'] = ($hasnewcom ? "<b>(<font class='new'>" . $lang['text_new'] . "</font>)</b> " : '')
                                . $lang['text_last_commented_by'] . get_username($lastcom['user']) . $lastcomtime . '<br />'
                                . format_comment(
                                    mb_substr($lastcom['text'], 0, 100, 'UTF-8') . (mb_strlen($lastcom['text'], 'UTF-8') > 100 ? ' ......' : ''),
                                    true, false, false, true, 600, false, false
                                );
                            $onmouseover = 'onmouseover="domTT_activate(this, event, \'content\', document.getElementById(\'' . $lastcomTooltip[$counter]['id'] . '\'), \'trail\', false, \'delay\', 500,\'lifetime\',3000,\'fade\',\'both\',\'styleClass\',\'niceTitle\',\'fadeMax\', 87,\'maxWidth\', 400);"';
                        }
                    } else {
                        $title = ' title="' . ($hasnewcom ? $lang['title_has_new_comment'] : $lang['title_no_new_comment']) . '"';
                        $onmouseover = '';
                    }
                    $comment = '<b><a' . $title . ' href="?id=' . $arr['id'] . '&amp;off_details=1#startcomments" ' . $onmouseover . '>'
                        . ($hasnewcom ? '<font class="new">' : '') . $comms . ($hasnewcom ? '</font>' : '') . '</a></b>';
                }

                if ($arr['allowed'] == 'allowed') {
                    $allowed = '&nbsp;<b>[<font color="green">' . $lang['text_allowed'] . '</font>]</b>';
                } elseif ($arr['allowed'] == 'denied') {
                    $allowed = '&nbsp;<b>[<font color="red">' . $lang['text_denied'] . '</font>]</b>';
                } else {
                    $allowed = '&nbsp;<b>[<font color="orange">' . $lang['text_pending'] . '</font>]</b>';
                }

                $zvote = $arr['yeah'] == 0 ? $arr['yeah'] : '<b><a href="?id=' . $arr['id'] . '&amp;offer_vote=1">' . $arr['yeah'] . '</a></b>';
                $pvote = $arr['against'] == 0 ? $arr['against'] : '<b><a href="?id=' . $arr['id'] . '&amp;offer_vote=1">' . $arr['against'] . '</a></b>';

                if ($arr['yeah'] == 0 && $arr['against'] == 0) {
                    $vRes = '0';
                } else {
                    $vRes = '<b><a href="?id=' . $arr['id'] . '&amp;offer_vote=1" title="' . $lang['title_show_vote_details'] . '">'
                        . '<font color="green">' . $arr['yeah'] . '</font> - <font color="red">' . $arr['against'] . '</font> = '
                        . ($arr['yeah'] - $arr['against']) . '</a></b>';
                }

                $addtime = gettime($arr['added'], false, true);
                $dispname = $arr['name'];
                $countDispname = mb_strlen($arr['name'], 'UTF-8');
                $maxLengthOfOfferName = 70;
                if ($countDispname > $maxLengthOfOfferName) {
                    $dispname = mb_substr($dispname, 0, $maxLengthOfOfferName - 2, 'UTF-8') . '..';
                }

                $content .= '<tr><td class="rowfollow" style="padding: 0px"><a href="?category=' . $arr['cat_id'] . '">'
                    . return_category_image($arr['cat_id'], '') . '</a></td><td style="text-align: left">'
                    . '<a href="?id=' . $arr['id'] . '&amp;off_details=1" title="' . htmlspecialchars($arr['name']) . '"><b>'
                    . htmlspecialchars($dispname) . '</b></a>'
                    . (($curUser['appendnew'] ?? '') != 'no' && strtotime((string) $arr['added']) >= $lastOffer ? "<b> (<font class='new'>" . $lang['text_new'] . "</font>)</b>" : '')
                    . $allowed . '</td><td class="rowfollow nowrap" style="padding: 5px" align="center">' . $vRes
                    . '</td><td class="rowfollow nowrap" ' . (! user_can('againstoffer') ? ' colspan="2" ' : '')
                    . " style='padding: 5px'><a href=\"?id=" . $arr['id'] . '&amp;vote=yeah" title="' . $lang['title_i_want_this']
                    . '"><font color="green"><b>' . $lang['text_yep'] . '</b></font></a></td>'
                    . (get_user_class() >= $GLOBALS['againstoffer_class']
                        ? '<td class="rowfollow nowrap" align="center"><a href="?id=' . $arr['id'] . '&amp;vote=against" title="'
                            . $lang['title_do_not_want_it'] . '"><font color="red"><b>' . $lang['text_nah'] . '</b></font></a></td>' : '');

                $content .= '<td class="rowfollow">' . $comment . '</td><td class="rowfollow nowrap">' . $addtime . '</td>';
                if ($GLOBALS['offervotetimeout_main'] > 0 && $GLOBALS['offeruptimeout_main'] > 0) {
                    $timeout = null;
                    if ($arr['allowed'] == 'allowed') {
                        $futuretime = strtotime((string) $arr['allowedtime']) + $GLOBALS['offeruptimeout_main'];
                        $timeout = gettime(date('Y-m-d H:i:s', $futuretime), false, true, true, false, true);
                    } elseif ($arr['allowed'] == 'pending') {
                        $futuretime = strtotime((string) $arr['added']) + $GLOBALS['offervotetimeout_main'];
                        $timeout = gettime(date('Y-m-d H:i:s', $futuretime), false, true, true, false, true);
                    }
                    if (! $timeout) {
                        $timeout = 'N/A';
                    }
                    $content .= '<td class="rowfollow nowrap">' . $timeout . '</td>';
                }
                $content .= '<td class="rowfollow">' . $addedby . '</td>'
                    . (user_can('offermanage')
                        ? '<td class="rowfollow"><a href="?id=' . $arr['id'] . '&amp;del_offer=1"><img class="staff_delete" src="pic/trans.gif" alt="D" title="' . $lang['title_delete'] . '" /></a><br /><a href="?id=' . $arr['id'] . '&amp;edit_offer=1"><img class="staff_edit" src="pic/trans.gif" alt="E" title="' . $lang['title_edit'] . '" /></a></td>' : '')
                    . '</tr>';

                ++$i;
            }
            $content .= "</table>\n";

            $paginationBottom = view('partials.pagination', ['paginator' => $rows])->render();
            $content .= $paginationBottom;

            if (! isset($curUser['showlastcom']) || $curUser['showlastcom'] == 'yes') {
                $content .= $this->capture(function () use ($lastcomTooltip) {
                    create_tooltip_container($lastcomTooltip, 400);
                });
            }
        }

        $content .= $this->capture(function () {
            end_main_frame();
        });

        // last_offer stamp update
        \App\Models\User::query()->where('id', $curUser['id'])->update(['last_offer' => date('Y-m-d H:i:s')]);

        return view('offer.index', [
            'content' => $content,
            'pageTitle' => $lang['head_offers'],
        ]);
    }
}
