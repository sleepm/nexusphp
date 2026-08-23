<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserCpController extends Controller
{
    public function web(Request $request)
    {
        $currentUser = Auth::guard('nexus')->user();
        if (!$currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        foreach (['secret', 'passhash', 'passkey', 'auth_key'] as $hidden) {
            $curUser[$hidden] = $currentUser->{$hidden};
        }
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }

        $lang = get_legacy_lang_file('usercp');
        $langFunctions = get_legacy_lang_file('functions');

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_usercp'] = $lang;
        $GLOBALS['lang_functions'] = $langFunctions;
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();
        $GLOBALS['SITEEMAIL'] = (string) get_setting('main.SITEEMAIL', '');
        $GLOBALS['Cache'] = $GLOBALS['Cache'] ?? null;

        foreach ([
            'UC_PEASANT' => 0, 'UC_USER' => 1, 'UC_POWER_USER' => 2, 'UC_ELITE_USER' => 3,
            'UC_CRAZY_USER' => 4, 'UC_INSANE_USER' => 5, 'UC_VETERAN_USER' => 6,
            'UC_EXTREME_USER' => 7, 'UC_ULTIMATE_USER' => 8, 'UC_NEXUS_MASTER' => 9,
            'UC_VIP' => 10, 'UC_RETIREE' => 11, 'UC_UPLOADER' => 12, 'UC_MODERATOR' => 13,
            'UC_ADMINISTRATOR' => 14, 'UC_SYSOP' => 15, 'UC_STAFFLEADER' => 16,
        ] as $constant => $value) {
            defined($constant) || define($constant, $value);
        }

        $GLOBALS['enablebitbucket_main'] = get_setting('main.enablebitbucket', 'no');
        $GLOBALS['enablead_advertisement'] = get_setting('advertisement.enablead', 'no');
        $GLOBALS['enablenoad_advertisement'] = get_setting('advertisement.enablenoad', 'no');
        $GLOBALS['enablebonusnoad_advertisement'] = get_setting('advertisement.enablebonusnoad', 'no');
        $GLOBALS['noad_advertisement'] = (int) get_setting('advertisement.noad', 0);
        $GLOBALS['bonusnoad_advertisement'] = (int) get_setting('advertisement.bonusnoad', 0);
        $GLOBALS['enabletooltip_tweak'] = get_setting('tweak.enabletooltip', 'no');
        $GLOBALS['showextinfo'] = ['imdb' => get_setting('main.showimdbinfo', 'no')];
        $GLOBALS['showmovies'] = ['hot' => get_setting('main.showhotmovies', 'no'), 'classic' => get_setting('main.showclassicmovies', 'no')];
        $GLOBALS['showshoutbox_main'] = get_setting('main.showshoutbox', 'no');
        $GLOBALS['showfunbox_main'] = get_setting('main.showfunbox', 'no');
        $GLOBALS['showhelpbox_main'] = get_setting('main.showhelpbox', 'no');
        $GLOBALS['enablenfo_main'] = get_setting('main.enablenfo', 'no');
        $GLOBALS['enablelocation_tweak'] = get_setting('tweak.enablelocation', 'no');
        $GLOBALS['prolinkpoint_bonus'] = (float) get_setting('bonus.prolinkpoint', 0);
        $GLOBALS['disableemailchange'] = get_setting('security.changeemail', 'no');
        $GLOBALS['emailnotify_smtp'] = get_setting('smtp.emailnotify', 'no');
        $GLOBALS['smtptype'] = get_setting('smtp.smtptype', 'none');
        $GLOBALS['browsecatmode'] = (int) get_setting('main.browsecat', 0);
        $GLOBALS['specialcatmode'] = (int) get_setting('main.specialcat', 0);
        $GLOBALS['enablespecial'] = get_setting('main.spsct', 'no');
        $GLOBALS['showschool'] = get_setting('main.enableschool', 'no');
        $GLOBALS['enabletracker_url'] = get_setting('main.enabletracker_url', 'no');

        $action = $request->input('action', '');
        $type = $request->input('type', '');

        $allowedActions = ['personal', 'tracker', 'forum', 'security'];
        if ($action && !in_array($action, $allowedActions)) {
            abort(400, $lang['std_invalid_action']);
        }

        if ($action) {
            return match ($action) {
                'personal' => $this->handlePersonal($request, $curUser, $lang),
                'tracker' => $this->handleTracker($request, $curUser, $lang),
                'forum' => $this->handleForum($request, $curUser, $lang),
                'security' => $this->handleSecurity($request, $curUser, $lang, $currentUser),
                default => abort(400),
            };
        }

        return $this->renderHome($curUser, $lang);
    }

    private function handlePersonal(Request $request, array $curUser, array $lang)
    {
        $type = $request->input('type', '');

        if ($type == 'save') {
            $updateset = [];
            $parked = $request->input('parked', 'no');
            $acceptpms = $request->input('acceptpms', 'yes');
            $deletepms = $request->input('deletepms') ? 'yes' : 'no';
            $savepms = $request->input('savepms') ? 'yes' : 'no';
            $commentpm = $request->input('commentpm', 'no');
            $gender = $request->input('gender', 'N/A');
            $country = $request->input('country', 0);
            $school = $request->input('school', '');
            $download = $request->input('download', 0);
            $upload = $request->input('upload', 0);
            $isp = $request->input('isp', 0);
            $avatar = $request->input('avatar', '');
            $savatar = $request->input('savatar', '');
            $info = trim($request->input('info', ''));
            $tracker_url_id = (int) $request->input('tracker_url_id', 0);
            $userid = $curUser['id'];

            if ($avatar == '') {
                $avatar = $savatar;
            }

            $updateset = [];
            $updateset['parked'] = $parked;
            $updateset['acceptpms'] = $acceptpms;
            $updateset['deletepms'] = $deletepms;
            $updateset['savepms'] = $savepms;
            $updateset['commentpm'] = $commentpm;
            $updateset['gender'] = $gender;
            $updateset['info'] = htmlspecialchars($info);
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'tracker_url_id')) {
                $updateset['tracker_url_id'] = $tracker_url_id;
            }

            if (preg_match("/^https?:\/\/[^\s'\"<>]+\.(jpg|gif|png|jpeg)$/i", $avatar) && !preg_match("/\.(php|js|cgi)/i", $avatar)) {
                $updateset['avatar'] = htmlspecialchars(trim($avatar));
            }

            if (is_valid_id($country)) {
                $updateset['country'] = $country;
            }
            if (is_valid_id($download)) {
                $updateset['download'] = $download;
            }
            if (is_valid_id($upload)) {
                $updateset['upload'] = $upload;
            }
            if (is_valid_id($isp)) {
                $updateset['isp'] = $isp;
            }

            if (!empty($request->input('notifs'))) {
                preg_match_all('/\[(.*)\]/Ui', $curUser['notifs'], $notifsArr);
                $notifsArr = array_fill_keys($notifsArr[1], 1);
                foreach (User::$notificationOptions as $option) {
                    if (isset($request->input('notifs')[$option])) {
                        $notifsArr[$option] = 1;
                    } else {
                        unset($notifsArr[$option]);
                    }
                }
                $updateset['notifs'] = '[' . implode('][', array_keys($notifsArr)) . ']';
            }

            User::query()->where('id', $userid)->update($updateset);
            clear_user_cache($userid, $curUser['passkey']);

            return redirect('usercp.php?action=personal&type=saved');
        }

        $pageTitle = $lang['head_control_panel'] . $lang['head_personal_settings'];

        $content = $this->capture(function () use ($curUser, $lang, $type) {
            $countries = '<option value="0">---- ' . $lang['select_none_selected'] . ' ----</option>';
            $ct = \Illuminate\Support\Facades\DB::table('countries')->orderBy('name')->get();
            foreach ($ct as $row) {
                $sel = $row->id == $curUser['country'] ? ' selected' : '';
                $countries .= '<option value="' . $row->id . '"' . $sel . '>' . htmlspecialchars($row->name) . '</option>';
            }

            $trackerUrls = '<option value="0">---- ' . $lang['select_none_selected'] . ' ----</option>';
            $trackerUrlTableExists = \Illuminate\Support\Facades\Schema::hasTable('tracker_urls');
            if ($trackerUrlTableExists) {
                $trackerUrlList = \App\Models\TrackerUrl::listAll();
                foreach ($trackerUrlList as $item) {
                    $sel = $item->id == ($curUser['tracker_url_id'] ?? 0) ? ' selected' : '';
                    $trackerUrls .= '<option value="' . $item->id . '"' . $sel . '>' . htmlspecialchars($item->url) . '</option>';
                }
            }

            $isplist = '<option value="0">---- ' . $lang['select_none_selected'] . ' ----</option>';
            $ispRows = \Illuminate\Support\Facades\DB::table('isp')->orderBy('id')->get();
            foreach ($ispRows as $row) {
                $sel = $row->id == $curUser['isp'] ? ' selected' : '';
                $isplist .= '<option value="' . $row->id . '"' . $sel . '>' . htmlspecialchars($row->name) . '</option>';
            }

            $downloadspeed = '<option value="0">---- ' . $lang['select_none_selected'] . ' ----</option>';
            $dsRows = \Illuminate\Support\Facades\DB::table('downloadspeed')->orderBy('id')->get();
            foreach ($dsRows as $row) {
                $sel = $row->id == $curUser['download'] ? ' selected' : '';
                $downloadspeed .= '<option value="' . $row->id . '"' . $sel . '>' . htmlspecialchars($row->name) . '</option>';
            }

            $uploadspeed = '<option value="0">---- ' . $lang['select_none_selected'] . ' ----</option>';
            $usRows = \Illuminate\Support\Facades\DB::table('uploadspeed')->orderBy('id')->get();
            foreach ($usRows as $row) {
                $sel = $row->id == $curUser['upload'] ? ' selected' : '';
                $uploadspeed .= '<option value="' . $row->id . '"' . $sel . '>' . htmlspecialchars($row->name) . '</option>';
            }

            $bitbucketImages = '';
            $bbRows = \Illuminate\Support\Facades\DB::table('bitbucket')->where('public', '1')->get();
            foreach ($bbRows as $row) {
                $bitbucketImages .= '<option value="' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/bitbucket/' . $row->name . '">' . $row->name . '</option>';
            }

            $this->usercpmenu('personal');
            $this->form('personal');
            echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';
            if ($type == 'saved') {
                echo '<tr><td colspan="2" class="heading" valign="top" align="center"><font color="red">' . $lang['text_saved'] . '</font></td></tr>';
            }

            $this->tr($lang['row_account_parked'],
                '<input type="checkbox" name="parked"' . ($curUser['parked'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['checkbox_pack_my_account'] . '<br /><font class="small" size="1">' . $lang['text_account_pack_note'] . '</font>', 1
            );

            $pmY = $lang['text_accept_pms']
                . '<input type="radio" name="acceptpms"' . ($curUser['acceptpms'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['radio_all_except_blocks']
                . '<input type="radio" name="acceptpms"' . ($curUser['acceptpms'] == 'friends' ? ' checked' : '') . ' value="friends">' . $lang['radio_friends_only']
                . '<input type="radio" name="acceptpms"' . ($curUser['acceptpms'] == 'no' ? ' checked' : '') . ' value="no">' . $lang['radio_staff_only']
                . '<br /><input type="checkbox" name="deletepms"' . ($curUser['deletepms'] == 'yes' ? ' checked' : '') . '> ' . $lang['checkbox_delete_pms']
                . '<br /><input type="checkbox" name="savepms"' . ($curUser['savepms'] == 'yes' ? ' checked' : '') . '> ' . $lang['checkbox_save_pms']
                . '<br /><input type="checkbox" name="commentpm"' . ($curUser['commentpm'] == 'yes' ? ' checked' : '') . ' value="yes"> ' . $lang['checkbox_pm_on_comments'];

            foreach (User::$notificationOptions as $option) {
                $pmY .= sprintf('<br /><input type="checkbox" name="notifs[%s]"%s value="yes" /> %s', $option, is_null($curUser['notifs']) || str_contains($curUser['notifs'], "[{$option}]") ? ' checked' : '', $lang["checkbox_pm_on_{$option}"]);
            }
            $this->tr($lang['row_pms'], $pmY, 1);

            $this->tr($lang['row_gender'],
                '<input type="radio" name="gender"' . ($curUser['gender'] == 'N/A' ? ' checked' : '') . ' value="N/A">' . $lang['radio_not_available']
                . '<input type="radio" name="gender"' . ($curUser['gender'] == 'Male' ? ' checked' : '') . ' value="Male">' . $lang['radio_male']
                . '<input type="radio" name="gender"' . ($curUser['gender'] == 'Female' ? ' checked' : '') . ' value="Female">' . $lang['radio_female'], 1
            );
            if ($trackerUrlTableExists) {
                $this->tr($lang['row_tracker_url'], '<select name="tracker_url_id">' . $trackerUrls . '</select><br /><font class="small" size="1">' . $lang['row_tracker_url_help'] . '</font>', 1);
            }
            $this->tr($lang['row_country'], '<select name="country">' . $countries . '</select>', 1);

            $showschool = $GLOBALS['showschool'];
            if ($showschool == 'yes') {
                $schools = '<option value="35">---- ' . $lang['select_none_selected'] . ' ----</option>';
                $scRows = \Illuminate\Support\Facades\DB::table('schools')->orderBy('name')->get();
                foreach ($scRows as $row) {
                    $sel = $row->id == $curUser['school'] ? ' selected' : '';
                    $schools .= '<option value="' . $row->id . '"' . $sel . '>' . htmlspecialchars($row->name) . '</option>';
                }
                $this->tr($lang['row_school'], '<select name="school">' . $schools . '</select>', 1);
            }

            $this->tr($lang['row_network_bandwidth'], '<b>' . $lang['text_downstream_rate'] . '</b>: <select name="download">' . $downloadspeed . '</select>&nbsp;&nbsp;<b>' . $lang['text_upstream_rate'] . '</b>: <select name="upload">' . $uploadspeed . '</select>&nbsp;&nbsp;<b>' . $lang['text_isp'] . '</b>: <select name="isp">' . $isplist . '</select>', 1);

            $avatarUrl = $curUser['avatar'] ? "'" . $curUser['avatar'] . "'" : "'" . get_protocol_prefix() . $GLOBALS['BASEURL'] . "/pic/default_avatar.png'";
            $enablebitbucket_main = $GLOBALS['enablebitbucket_main'];
            $this->tr($lang['row_avatar_url'], '<img src=' . $avatarUrl . ' name="avatarimg"><br />
  <select name="savatar" OnChange="document.forms[0].avatarimg.src=this.value;this.form.avatar.value=this.value;">
  <option value="' . $curUser['avatar'] . '">' . $lang['select_choose_avatar'] . '</option>
  <option value="' . get_protocol_prefix() . $GLOBALS['BASEURL'] . '/pic/default_avatar.png">' . $lang['select_nothing'] . '</option>
  ' . $bitbucketImages . '
  </select><input type="text" name="avatar" style="width: 400px" value="' . htmlspecialchars($curUser['avatar'] ?? '') .
                '"><br />' . $lang['text_avatar_note'] . ($enablebitbucket_main == 'yes' ? $lang['text_bitbucket_note'] : ''), 1);

            $this->tr($lang['row_info'], '<textarea name="info" style="width:700px" rows="10">' . htmlspecialchars($curUser['info']) . '</textarea><br />' . $lang['text_info_note'], 1);
            $this->submit();
            echo '</table></form>';
        });

        return view('usercp', compact('content', 'pageTitle'));
    }

    private function handleTracker(Request $request, array $curUser, array $lang)
    {
        $type = $request->input('type', '');
        $enabletooltip_tweak = $GLOBALS['enabletooltip_tweak'];
        $enablead_advertisement = $GLOBALS['enablead_advertisement'];
        $enablenoad_advertisement = $GLOBALS['enablenoad_advertisement'];
        $enablebonusnoad_advertisement = $GLOBALS['enablebonusnoad_advertisement'];
        $noad_advertisement = $GLOBALS['noad_advertisement'];
        $bonusnoad_advertisement = $GLOBALS['bonusnoad_advertisement'];
        $showaddisabled = true;
        if ($enablead_advertisement == 'yes') {
            if (get_user_class() >= $noad_advertisement || ($enablebonusnoad_advertisement == 'yes' && !empty($curUser['noaduntil']) && strtotime($curUser['noaduntil']) >= TIMENOW)) {
                $showaddisabled = false;
            }
        }
        $showtooltipsetting = $enabletooltip_tweak == 'yes';

        if ($type == 'save') {
            $updateset = [];
            $pmnotif = $request->input('pmnotif', '');
            $emailnotif = $request->input('emailnotif', '');

            preg_match_all('/\[(.*)\]/Ui', $curUser['notifs'], $notifs);
            $notifs = array_fill_keys($notifs[1], 1);
            foreach ($notifs as $key => $value) {
                foreach (['incldead', 'spstate', 'inclbookmarked'] as $item) {
                    if (str_starts_with($key, $item)) {
                        unset($notifs[$key]);
                    }
                }
            }

            if ($pmnotif == 'yes') {
                $notifs['pm'] = 1;
            } else {
                unset($notifs['pm']);
            }
            if ($emailnotif == 'yes') {
                $notifs['email'] = 1;
            } else {
                unset($notifs['email']);
            }

            $this->browsecheck('categories', 'cat', $notifs);
            $this->browsecheck('sources', 'sou', $notifs);
            $this->browsecheck('media', 'med', $notifs);
            $this->browsecheck('codecs', 'cod', $notifs);
            $this->browsecheck('standards', 'sta', $notifs);
            $this->browsecheck('processings', 'pro', $notifs);
            $this->browsecheck('teams', 'tea', $notifs);
            $this->browsecheck('audiocodecs', 'aud', $notifs);

            $incldead = $request->input('incldead');
            if (isset($incldead) && $incldead != 1) {
                $notifs["incldead=$incldead"] = 1;
            }
            $spstate = $request->input('spstate');
            if ($spstate) {
                $notifs["spstate=$spstate"] = 1;
            }
            $inclbookmarked = $request->input('inclbookmarked');
            if ($inclbookmarked) {
                $notifs["inclbookmarked=$inclbookmarked"] = 1;
            }

            $stylesheet = $request->input('stylesheet');
            $sitelanguage = $request->input('sitelanguage');
            $fontsize = $request->input('fontsize', 'medium');

            $updateset['notifs'] = '[' . implode('][', array_keys($notifs)) . ']';
            $updateset['fontsize'] = $fontsize;

            if (is_valid_id($stylesheet)) {
                $updateset['stylesheet'] = $stylesheet;
            }
            if (is_valid_id($sitelanguage)) {
                $lang_folder = validlang($sitelanguage);
                if (get_langfolder_cookie() != $lang_folder) {
                    set_langfolder_cookie($lang_folder);
                }
                $updateset['lang'] = $sitelanguage;
            }

            $updateset['torrentsperpage'] = min(100, intval($request->input('torrentsperpage', 0)));
            $showmovies = $GLOBALS['showmovies'];
            if ($showmovies['hot'] == 'yes') {
                $updateset['showhot'] = $request->input('show_hot', 'no');
            }
            if ($showmovies['classic'] == 'yes') {
                $updateset['showclassic'] = $request->input('show_classic', 'no');
            }
            if ($showtooltipsetting) {
                $updateset['tooltip'] = $request->input('tooltip', 'off');
            }
            $enablead_advertisement = $GLOBALS['enablead_advertisement'];
            if ($enablead_advertisement == 'yes' && !$showaddisabled) {
                $updateset['noad'] = $request->input('showad', 'yes') == 'yes' ? 'no' : 'yes';
            }
            $updateset['timetype'] = $request->input('timetype', 'timeadded');
            $updateset['appendsticky'] = $request->input('appendsticky', 'no') == 'yes' ? 'yes' : 'no';
            $updateset['appendnew'] = $request->input('appendnew', 'no') == 'yes' ? 'yes' : 'no';
            $updateset['appendpromotion'] = $request->input('appendpromotion', 'off');
            $updateset['appendpicked'] = $request->input('appendpicked', 'no') == 'yes' ? 'yes' : 'no';
            $updateset['dlicon'] = $request->input('dlicon', 'no') == 'yes' ? 'yes' : 'no';
            $updateset['bmicon'] = $request->input('bmicon', 'no') == 'yes' ? 'yes' : 'no';
            $updateset['showcomnum'] = $request->input('showcomnum', 'no') == 'yes' ? 'yes' : 'no';
            if ($showtooltipsetting) {
                $updateset['showlastcom'] = $request->input('showlastcom', 'no') == 'yes' ? 'yes' : 'no';
            }
            $updateset['pmnum'] = max(1, min(100, floor($request->input('pmnum', 20))));
            $showfunbox_main = $GLOBALS['showfunbox_main'];
            if ($showfunbox_main == 'yes') {
                $updateset['showfb'] = $request->input('showfb', 'no') == 'yes' ? 'yes' : 'no';
            }
            $updateset['sbnum'] = max(10, min(500, intval($request->input('sbnum', 70))));
            $updateset['sbrefresh'] = max(10, min(3600, intval($request->input('sbrefresh', 120))));
            $updateset['hidehb'] = $request->input('hidehb') == 'yes' ? 'yes' : 'no';
            $showextinfo = $GLOBALS['showextinfo'];
            if ($showextinfo['imdb'] == 'yes') {
                $updateset['showimdb'] = $request->input('showimdb', 'no') == 'yes' ? 'yes' : 'no';
            }
            $updateset['showdescription'] = $request->input('showdescription', 'no') == 'yes' ? 'yes' : 'no';
            $enablenfo_main = $GLOBALS['enablenfo_main'];
            if ($enablenfo_main == 'yes') {
                $updateset['shownfo'] = $request->input('shownfo', 'no') == 'yes' ? 'yes' : 'no';
            }
            $updateset['showsmalldescr'] = $request->input('smalldescr', 'no') == 'yes' ? 'yes' : 'no';
            $updateset['showcomment'] = $request->input('showcomment', 'no') == 'yes' ? 'yes' : 'no';

            User::query()->where('id', $curUser['id'])->update($updateset);

            return redirect('usercp.php?action=tracker&type=saved');
        }

        $content = $this->capture(function () use ($request, $curUser, $lang, $type, $showaddisabled, $showtooltipsetting, $enablead_advertisement, $enablenoad_advertisement, $enablebonusnoad_advertisement, $noad_advertisement, $bonusnoad_advertisement) {
            $pageTitle = $GLOBALS['lang_usercp']['head_control_panel'] . $GLOBALS['lang_usercp']['head_tracker_settings'];

            $this->usercpmenu('tracker');
            $this->form('tracker');
            echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';
            if ($type == 'saved') {
                echo '<tr><td colspan="2" class="heading" valign="top" align="center"><font color="red">' . $lang['text_saved'] . '</font></td></tr>';
            }

            $emailnotify_smtp = $GLOBALS['emailnotify_smtp'];
            $smtptype = $GLOBALS['smtptype'];
            if ($emailnotify_smtp == 'yes' && $smtptype != 'none') {
                $this->tr($lang['row_email_notification'], '<input type="checkbox" name="pmnotif"' . (strpos($curUser['notifs'], '[pm]') !== false ? ' checked' : '') . ' value="yes"> ' . $lang['checkbox_notification_received_pm'] . '<br /><input type="checkbox" name="emailnotif"' . (strpos($curUser['notifs'], '[email]') !== false ? ' checked' : '') . ' value="yes" /> ' . $lang['checkbox_notification_default_categories'], 1);
            }

            $enablespecial = $GLOBALS['enablespecial'];
            $browsecatmode = $GLOBALS['browsecatmode'];
            $specialcatmode = $GLOBALS['specialcatmode'];

            $special_state = 0;
            for ($i = 0; $i <= 7; $i++) {
                if (strpos($curUser['notifs'], "[spstate=$i]") !== false) {
                    $special_state = $i;
                    break;
                }
            }

            $categories = build_search_box_category_table($browsecatmode, 'yes', 'torrents.php?allsec=1', false, 3, $curUser['notifs'], ['section_name' => true]);
            $delimiter = '<div style="height: 1px;background-color: #eee;margin: 10px 0"></div>';
            if ($enablespecial == 'yes') {
                $categories .= $delimiter . build_search_box_category_table($specialcatmode, 'yes', 'special.php?allsec=1', false, 3, $curUser['notifs'], ['section_name' => true]);
            }
            $categories .= $delimiter . '<table><caption><font class="big">' . $lang['text_additional_selection'] . '</font></caption><tr><td class="bottom"><b>' . $lang['text_show_dead_active'] . '</b><br /><select name="incldead"><option value="0"' . (strpos($curUser['notifs'], '[incldead=0]') !== false ? ' selected' : '') . '>' . $lang['select_including_dead'] . '</option><option value="1"' . (strpos($curUser['notifs'], '[incldead=1]') !== false || strpos($curUser['notifs'], 'incldead') == false ? ' selected' : '') . '>' . $lang['select_active'] . '</option><option value="2"' . (strpos($curUser['notifs'], '[incldead=2]') !== false ? ' selected' : '') . '>' . $lang['select_dead'] . '</option></select></td><td class="bottom" align="left"><b>' . $lang['text_show_special_torrents'] . '</b><br /><select name="spstate"><option value="0"' . ($special_state == 0 ? ' selected' : '') . '>' . $lang['select_all'] . '</option>' . promotion_selection($special_state) . '</select></td><td class="bottom"><b>' . $lang['text_show_bookmarked'] . '</b><br /><select name="inclbookmarked"><option value="0"' . (strpos($curUser['notifs'], '[inclbookmarked=0]') !== false ? ' selected' : '') . '>' . $lang['select_all'] . '</option><option value="1"' . (strpos($curUser['notifs'], '[inclbookmarked=1]') !== false ? ' selected' : '') . '>' . $lang['select_bookmarked'] . '</option><option value="2"' . (strpos($curUser['notifs'], '[inclbookmarked=2]') !== false ? ' selected' : '') . '>' . $lang['select_bookmarked_exclude'] . '</option></select></td></tr></table>';
            $this->tr($lang['row_browse_default_categories'], $categories, 1);

            $ssRows = \Illuminate\Support\Facades\DB::table('stylesheets')->get();
            $ss_sa = [];
            foreach ($ssRows as $row) {
                $ss_sa[$row->name] = $row->id;
            }
            ksort($ss_sa);
            $stylesheets = '';
            foreach ($ss_sa as $ss_name => $ss_id) {
                $ss = $ss_id == $curUser['stylesheet'] ? ' selected' : '';
                $stylesheets .= '<option value="' . $ss_id . '"' . $ss . '>' . htmlspecialchars($ss_name) . '</option>';
            }
            $this->tr($lang['row_stylesheet'], '<select name="stylesheet">' . $stylesheets . '</select>&nbsp;&nbsp;<font class="small">' . $lang['text_stylesheet_note'] . '<a href="aboutnexus.php#stylesheet"><b>' . $lang['text_stylesheet_link'] . '</b></a></font>.', 1);

            $this->tr($lang['row_font_size'], '<select name="fontsize"><option value="small"' . ($curUser['fontsize'] == 'small' ? ' selected' : '') . '>' . $lang['select_small'] . '</option><option value="medium"' . ($curUser['fontsize'] == 'medium' ? ' selected' : '') . '>' . $lang['select_medium'] . '</option><option value="large"' . ($curUser['fontsize'] == 'large' ? ' selected' : '') . '>' . $lang['select_large'] . '</option></select>', 1);

            $s = '<select name="sitelanguage">';
            $langs = langlist('site_lang', true);
            foreach ($langs as $row) {
                $se = $row['site_lang_folder'] == get_langfolder_cookie() ? ' selected' : '';
                $s .= '<option value="' . $row['id'] . '"' . $se . '>' . htmlspecialchars($row['lang_name']) . '</option>';
            }
            $s .= '</select>&nbsp;&nbsp;<font class="small">' . $lang['text_translation_note'] . '<a href="aboutnexus.php#translation"><b>' . $lang['text_translation_link'] . '</b></a></font>.';
            $this->tr($lang['row_site_language'], $s, 1);

            $showmovies = $GLOBALS['showmovies'];
            if ($showmovies['hot'] == 'yes' || $showmovies['classic'] == 'yes') {
                $html = '';
                if ($showmovies['hot'] == 'yes') {
                    $html .= '<input type="checkbox" name="show_hot"' . ($curUser['showhot'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['checkbox_show_hot'] . '&nbsp;';
                }
                if ($showmovies['classic'] == 'yes') {
                    $html .= '<input type="checkbox" name="show_classic"' . ($curUser['showclassic'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['checkbox_show_classic'] . '&nbsp;';
                }
                $this->tr($lang['row_recommended_movies'], $html, 1);
            }
            $this->tr($lang['row_pm_boxes'], $lang['text_show'] . '<input type="text" name="pmnum" size="5" value="' . $curUser['pmnum'] . '">' . $lang['text_pms_per_page'], 1);

            $showshoutbox_main = $GLOBALS['showshoutbox_main'];
            $showhelpbox_main = $GLOBALS['showhelpbox_main'];
            if ($showshoutbox_main == 'yes') {
                $shoutboxHtml = $lang['text_show_last'] . '<input type="text" name="sbnum" size="5" value="' . $curUser['sbnum'] . '">' . $lang['text_messages_at_shoutbox'] . '<br />' . $lang['text_refresh_shoutbox_every'] . '<input type="text" name="sbrefresh" size="5" value="' . $curUser['sbrefresh'] . '">' . $lang['text_seconds'];
                if ($showhelpbox_main == 'yes') {
                    $shoutboxHtml .= '<br /><input type="checkbox" name="hidehb"' . ($curUser['hidehb'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_hide_helpbox_messages'];
                }
                $this->tr($lang['row_shoutbox'], $shoutboxHtml, 1);
            }

            $showfunbox_main = $GLOBALS['showfunbox_main'];
            if ($showfunbox_main == 'yes') {
                $this->tr($lang['row_funbox'], '<input type="checkbox" name="showfb"' . ($curUser['showfb'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_funbox'], 1);
            }

            $showextinfo = $GLOBALS['showextinfo'];
            $enablenfo_main = $GLOBALS['enablenfo_main'];
            $detailHtml = '<input type="checkbox" name="showdescription"' . ($curUser['showdescription'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_description'] . '<br />';
            if ($enablenfo_main == 'yes' && get_user_class() >= UC_POWER_USER) {
                $detailHtml .= '<input type="checkbox" name="shownfo"' . ($curUser['shownfo'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_nfo'] . '<br />';
            }
            if ($showextinfo['imdb'] == 'yes') {
                $detailHtml .= '<input type="checkbox" name="showimdb"' . ($curUser['showimdb'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_imdb_info'];
            }
            $this->tr($lang['row_torrent_detail'], $detailHtml, 1);
            $this->tr($lang['row_discuss'], '<input type="checkbox" name="showcomment"' . ($curUser['showcomment'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_comments'], 1);

            if ($enablead_advertisement == 'yes') {
                $adHtml = '<input type="checkbox" name="showad"' . ($curUser['noad'] == 'yes' ? '' : ' checked') . ($showaddisabled ? ' disabled="disabled"' : '') . ' value="yes" />' . $lang['text_show_advertisement_note'];
                if ($enablenoad_advertisement == 'yes') {
                    $adHtml .= '<br />' . get_user_class_name($noad_advertisement, false, true, true) . $lang['text_can_turn_off_advertisement'];
                }
                if ($enablebonusnoad_advertisement == 'yes') {
                    $adHtml .= '<br />' . get_user_class_name($bonusnoad_advertisement, false, true, true) . $lang['text_buy_no_advertisement'] . '<a href="mybonus.php"><b>' . $lang['text_bonus_center'] . '</b></a>';
                }
                $this->tr($lang['row_show_advertisements'], $adHtml, 1);
            }

            $this->tr($lang['row_time_type'], '<input type="radio" name="timetype"' . ($curUser['timetype'] == 'timeadded' ? ' checked' : '') . ' value="timeadded">' . $lang['text_time_added'] . '&nbsp;&nbsp;<input type="radio" name="timetype"' . ($curUser['timetype'] == 'timealive' ? ' checked' : '') . ' value="timealive">' . $lang['text_time_elapsed'] . '<br />', 1);

            $browsePageHtml = $lang['text_browse_setting_warning']
                . '<br /><b>' . $lang['row_torrent_page'] . ': </b><br />' . $lang['text_show'] . '<input type="text" size="5" name="torrentsperpage" value="' . $curUser['torrentsperpage'] . '"> ' . $lang['text_torrents_per_page'] . $lang['text_zero_equals_default'] . '<br />';
            if ($showtooltipsetting) {
                $browsePageHtml .= '<b>' . $lang['text_tooltip_type'] . '</b>: <br />';
                if ($showextinfo['imdb'] == 'yes') {
                    $browsePageHtml .= '<input type="radio" name="tooltip"' . ($curUser['tooltip'] == 'minorimdb' ? ' checked' : '') . ' value="minorimdb">' . $lang['text_minor_imdb_info'] . '<br />'
                        . '<input type="radio" name="tooltip"' . ($curUser['tooltip'] == 'medianimdb' ? ' checked' : '') . ' value="medianimdb">' . $lang['text_median_imdb_info'] . '<br />';
                }
                $browsePageHtml .= '<input type="radio" name="tooltip"' . ($curUser['tooltip'] == 'off' ? ' checked' : '') . ' value="off">' . $lang['text_off'] . '<br />';
            }
            $browsePageHtml .= '<b>' . $lang['text_append_words_to_torrents'] . ': </b><br />'
                . '<input type="checkbox" name="appendsticky"' . ($curUser['appendsticky'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_append_sticky'] . '<br />'
                . '<input type="checkbox" name="appendnew"' . ($curUser['appendnew'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_append_new'] . '<br />'
                . $lang['text_torrents_on_promotion']
                . '<input type="radio" name="appendpromotion"' . ($curUser['appendpromotion'] == 'highlight' ? ' checked' : '') . " value='highlight'>" . $lang['text_highlight']
                . '<input type="radio" name="appendpromotion"' . ($curUser['appendpromotion'] == 'word' ? ' checked' : '') . " value='word'>" . $lang['text_append_words']
                . '<input type="radio" name="appendpromotion"' . ($curUser['appendpromotion'] == 'icon' ? ' checked' : '') . " value='icon'>" . $lang['text_append_icon']
                . '<input type="radio" name="appendpromotion"' . ($curUser['appendpromotion'] == 'off' ? ' checked' : '') . " value='off'>" . $lang['text_no_mark'] . '<br />'
                . '<input type="checkbox" name="appendpicked"' . ($curUser['appendpicked'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_append_picked'] . '<br />'
                . '<b>' . $lang['text_show_title'] . ': </b><br />'
                . '<input type="checkbox" name="smalldescr"' . ($curUser['showsmalldescr'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_small_description'] . '<br />'
                . '<b>' . $lang['text_show_action_icons'] . ': </b><br />'
                . '<input type="checkbox" name="dlicon"' . ($curUser['dlicon'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_download_icon'] . ' <img class="download" src="pic/trans.gif" alt="Download" /><br />'
                . '<input type="checkbox" name="bmicon"' . ($curUser['bmicon'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_bookmark_icon'] . ' <img class="bookmark" src="pic/trans.gif" alt="Bookmark" /><br />'
                . '<b>' . $lang['text_comments_reviews'] . ': </b><br />'
                . '<input type="checkbox" name="showcomnum"' . ($curUser['showcomnum'] == 'yes' ? ' checked' : '') . ' value="yes">' . $lang['text_show_comment_number'];
            if ($showtooltipsetting) {
                $browsePageHtml .= '<select name="showlastcom" style="width: 70px;"><option value="yes"' . ($curUser['showlastcom'] != 'no' ? ' selected' : '') . '>' . $lang['select_with'] . '</option><option value="no"' . ($curUser['showlastcom'] == 'no' ? ' selected' : '') . '>' . $lang['select_without'] . '</option></select>' . $lang['text_last_comment_on_tooltip'];
            }
            $this->tr($lang['row_browse_page'], $browsePageHtml, 1);

            $this->submit();
            echo '</table></form>';
        });

        $pageTitle = $lang['head_control_panel'] . $lang['head_tracker_settings'];
        return view('usercp', compact('content', 'pageTitle'));
    }

    private function handleForum(Request $request, array $curUser, array $lang)
    {
        $type = $request->input('type', '');
        $enabletooltip_tweak = $GLOBALS['enabletooltip_tweak'];
        $showtooltipsetting = $enabletooltip_tweak == 'yes';

        if ($type == 'save') {
            $updateset = [];
            $updateset['avatars'] = $request->input('avatars') ? 'yes' : 'no';
            $updateset['showlastpost'] = $request->input('ttlastpost') ? 'yes' : 'no';
            $updateset['signatures'] = $request->input('signatures') ? 'yes' : 'no';
            $updateset['signature'] = htmlspecialchars(trim($request->input('signature', '')));
            $updateset['topicsperpage'] = min(100, intval($request->input('topicsperpage', 0)));
            $updateset['postsperpage'] = min(100, intval($request->input('postsperpage', 0)));
            $updateset['clicktopic'] = $request->input('clicktopic', 'firstpage');

            User::query()->where('id', $curUser['id'])->update($updateset);

            return redirect('usercp.php?action=forum&type=saved');
        }

        $content = $this->capture(function () use ($curUser, $lang, $type, $showtooltipsetting) {
            $this->usercpmenu('forum');
            $this->form('forum');
            echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';
            if ($type == 'saved') {
                echo '<tr><td colspan="2" class="heading" valign="top" align="center"><font color="red">' . $lang['text_saved'] . '</font></td></tr>';
            }

            $this->tr($lang['row_topics_per_page'], '<input type="text" size="10" name="topicsperpage" value="' . $curUser['topicsperpage'] . '">' . $lang['text_zero_equals_default'], 1);
            $this->tr($lang['row_posts_per_page'], '<input type="text" size="10" name="postsperpage" value="' . $curUser['postsperpage'] . '"> ' . $lang['text_zero_equals_default'], 1);
            $this->tr($lang['row_view_avatars'], '<input type="checkbox" name="avatars"' . ($curUser['avatars'] == 'yes' ? ' checked' : '') . '>' . $lang['checkbox_low_bandwidth_note'], 1);
            $this->tr($lang['row_view_signatures'], '<input type="checkbox" name="signatures"' . ($curUser['signatures'] == 'yes' ? ' checked' : '') . '>' . $lang['checkbox_low_bandwidth_note'], 1);
            if ($showtooltipsetting) {
                $this->tr($lang['row_tooltip_last_post'], '<input type="checkbox" name="ttlastpost"' . ($curUser['showlastpost'] == 'yes' ? ' checked' : '') . '>' . $lang['checkbox_last_post_note'], 1);
            }
            $this->tr($lang['row_click_on_topic'], '<input type="radio" name="clicktopic"' . ($curUser['clicktopic'] == 'firstpage' ? ' checked' : '') . ' value="firstpage">' . $lang['text_go_to_first_page'] . '<input type="radio" name="clicktopic"' . ($curUser['clicktopic'] == 'lastpage' ? ' checked' : '') . ' value="lastpage">' . $lang['text_go_to_last_page'], 1);
            $this->tr($lang['row_forum_signature'], '<textarea name="signature" style="width:700px" rows="10">' . htmlspecialchars($curUser['signature']) . '</textarea><br />' . $lang['text_signature_note'], 1);
            $this->submit();
            echo '</table></form>';
        });

        $pageTitle = $lang['head_control_panel'] . $lang['head_forum_settings'];
        return view('usercp', compact('content', 'pageTitle'));
    }

    private function handleSecurity(Request $request, array $curUser, array $lang, User $currentUser)
    {
        $type = $request->input('type', '');
        $userInfo = $currentUser;

        if ($type == 'confirm') {
            $response = $request->input('response');
            if (!$response) {
                abort(403, $lang['std_enter_old_password'] . $this->goback());
            }
            $challenge = \Nexus\Database\NexusDB::cache_get(get_challenge_key($userInfo->username));
            if (empty($challenge)) {
                abort(403, 'expired!' . $this->goback());
            }
            $expectedResponse = hash_hmac('sha256', $userInfo->passhash, $challenge);
            if (!hash_equals($expectedResponse, $response)) {
                abort(403, $lang['std_wrong_password_note'] . $this->goback());
            }

            $updateset = [];
            $changedemail = 0;
            $passupdated = 0;
            $privacyupdated = 0;
            $resetpasskey = $request->input('resetpasskey');
            $email = htmlspecialchars(trim($request->input('email', '')));
            $chpassword = $request->input('chpassword', '');
            $privacy = $request->input('privacy', 'normal');
            $twoStepSecret = $request->input('two_step_secret', '');
            $twoStepSecretHash = $request->input('two_step_code');

            if (!empty($twoStepSecretHash)) {
                $ga = new \PHPGangsta_GoogleAuthenticator();
                if (empty($curUser['two_step_secret'])) {
                    $secretToVerify = $twoStepSecret;
                    $updateset['two_step_secret'] = $twoStepSecret;
                } else {
                    $secretToVerify = $curUser['two_step_secret'];
                    $updateset['two_step_secret'] = '';
                }
                if (!$ga->verifyCode($secretToVerify, $twoStepSecretHash)) {
                    abort(403, 'Invalid two step code' . $this->goback('-2'));
                }
            }

            if ($chpassword != '') {
                $sec = mksecret();
                $passhash = hash('sha256', $sec . $chpassword);
                $updateset['secret'] = $sec;
                $updateset['passhash'] = $passhash;
                $authKey = mksecret();
                $updateset['auth_key'] = $authKey;
                logincookie($curUser['id'], $authKey);
                $passupdated = 1;
            }

            $disableemailchange = $GLOBALS['disableemailchange'];
            $smtptype = $GLOBALS['smtptype'];
            $SITENAME = $GLOBALS['SITENAME'];
            $BASEURL = $GLOBALS['BASEURL'];
            $SITEEMAIL = $GLOBALS['SITEEMAIL'];

            if ($disableemailchange != 'no' && $smtptype != 'none' && $email != $curUser['email']) {
                if (EmailBanned($email)) {
                    abort(403, $lang['std_email_address_banned']);
                }
                if (!EmailAllowed($email)) {
                    abort(403, $lang['std_wrong_email_address_domains'] . allowedemails());
                }
                if (!validemail($email)) {
                    abort(403, $lang['std_wrong_email_address_format'] . $this->goback('-2'));
                }
                $emailExists = \Illuminate\Support\Facades\DB::table('users')->where('email', $email)->where('id', '!=', $curUser['id'])->exists();
                if ($emailExists) {
                    abort(403, $lang['std_email_in_use'] . $this->goback('-2'));
                }
                $changedemail = 1;
            }

            if ($resetpasskey == 1) {
                $passkey = md5($curUser['username'] . date('Y-m-d H:i:s') . $curUser['passhash']);
                $updateset['passkey'] = $passkey;
            }

            if ($changedemail == 1) {
                $sec = mksecret();
                $hash = md5($sec . $email . $sec);
                $obemail = rawurlencode($email);
                $updateset['editsecret'] = $sec;
                $subject = $SITENAME . $lang['mail_profile_change_confirmation'];
                $changeEmailOne = sprintf($lang['mail_change_email_one'], $SITENAME);
                $changeEmailNine = sprintf($lang['mail_change_email_nine'], $SITENAME);
                $body = <<<EOD
{$changeEmailOne}{$curUser['username']}{$lang['mail_change_email_two']}($email){$lang['mail_change_email_three']}

{$lang['mail_change_email_four']}{$_SERVER['REMOTE_ADDR']}{$lang['mail_change_email_five']}

{$lang['mail_change_email_six']}<b><a href="javascript:void(null)" onclick="window.open('http://{$BASEURL}/confirmemail.php/{$curUser['id']}/{$hash}/{$obemail}')">{$lang['mail_here']}</a></b>{$lang['mail_change_email_six_1']}<br />
http://{$BASEURL}/confirmemail.php/{$curUser['id']}/{$hash}/{$obemail}

{$lang['mail_change_email_seven']}

------{$lang['mail_change_email_eight']}
{$changeEmailNine}
EOD;
                sent_mail($email, $SITENAME, $SITEEMAIL, $subject, str_replace('<br />', '<br />', nl2br($body)), 'profile change', false, false, '');
            }

            if ($privacy != 'normal' && $privacy != 'low' && $privacy != 'strong') {
                abort(403, 'whoops');
            }
            $updateset['privacy'] = $privacy;
            if ($curUser['privacy'] != $privacy) {
                $privacyupdated = 1;
            }

            $user = $curUser['id'];
            \Nexus\Database\NexusDB::transaction(function () use ($user, $updateset, $request) {
                User::query()->where('id', $user)->update($updateset);
                if (!empty($request->input('resetauthkey')) && $request->input('resetauthkey') == 1) {
                    $torrentRep = new \App\Repositories\TorrentRepository();
                    $torrentRep->resetTrackerReportAuthKeySecret($user);
                }
                do_action('usercp_security_update', $request->all());
            });
            $to = 'usercp.php?action=security&type=saved';
            if ($changedemail == 1) {
                $to .= '&mail=1';
            }
            if ($resetpasskey == 1) {
                $to .= '&passkey=1';
            }
            if ($passupdated == 1) {
                $to .= '&password=1';
            }
            if ($privacyupdated == 1) {
                $to .= '&privacy=1';
            }
            clear_user_cache($curUser['id']);
            \Nexus\Database\NexusDB::cache_del(get_challenge_key($userInfo->username));

            return redirect($to);
        }

        $content = $this->capture(function () use ($request, $curUser, $lang, $type, $userInfo) {
            if ($type == 'save') {
                $resetpasskey = $request->input('resetpasskey');
                $resetauthkey = $request->input('resetauthkey');
                $email = htmlspecialchars(trim($request->input('email', '')));
                $chpassword = $request->input('chpassword', '');
                $passagain = $request->input('passagain', '');
                $privacy = $request->input('privacy', 'normal');
                $two_step_secret = $request->input('two_step_secret', '');
                $two_step_code = $request->input('two_step_code');

                $this->usercpmenu('security');
                $this->form('security', 'confirm', 'security');
                echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';
                if ($resetpasskey == 1) {
                    echo '<input type="hidden" name="resetpasskey" value="1">';
                }
                if ($resetauthkey == 1) {
                    echo '<input type="hidden" name="resetauthkey" value="1">';
                }
                echo '<input type="hidden" name="email" value="' . $email . '">';
                echo '<input type="hidden" name="chpassword" value="' . $chpassword . '">';
                echo '<input type="hidden" name="privacy" value="' . $privacy . '">';
                echo '<input type="hidden" name="two_step_secret" value="' . $two_step_secret . '">';
                echo '<input type="hidden" name="two_step_code" value="' . $two_step_code . '">';
                echo '<tr><td class="rowhead nowrap" valign="top" align="right" width="1%">' . $lang['row_security_check'] . '</td><td valign="top" align="left" width="99%"><input type="password" class="oldpassword" style="width: 200px"><br /><font class="small">' . $lang['text_security_check_note'] . '</font></td></tr>';
                echo '<input type="hidden" name="username" value="' . $curUser['username'] . '">';
                echo '<input type="hidden" name="response">';
                do_action('usercp_security_update_confirm', $request->all());
                $this->submit('button');
                echo '</table></form>';
                render_password_challenge_js('security', 'username', 'oldpassword');
                return;
            }

            $this->usercpmenu('security');
            $this->form('security', $type == 'save' ? 'confirm' : 'save', 'security');
            echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';

            if ($type == 'saved') {
                $savedMsg = $lang['text_saved'];
                if ($request->input('mail') == '1') {
                    $savedMsg .= $lang['std_confirmation_email_sent'];
                }
                if ($request->input('passkey') == '1') {
                    $savedMsg .= $lang['std_passkey_reset'];
                }
                if ($request->input('password') == '1') {
                    $savedMsg .= $lang['std_password_changed'];
                }
                if ($request->input('privacy') == '1') {
                    $savedMsg .= $lang['std_privacy_level_updated'];
                }
                echo '<tr><td colspan="2" class="heading" valign="top" align="center"><font color="red">' . $savedMsg . '</font></td></tr>';
            }

            $this->tr($lang['row_reset_passkey'], '<input type="checkbox" name="resetpasskey" value="1" />' . $lang['checkbox_reset_my_passkey'] . '<br /><font class="small">' . $lang['text_reset_passkey_note'] . '</font>', 1);

            if (!empty($curUser['two_step_secret'])) {
                $this->tr($lang['row_two_step_secret'], '<input type="text" name="two_step_code" />' . $lang['text_two_step_secret_unbind_note'], 1);
            } else {
                $ga = new \PHPGangsta_GoogleAuthenticator();
                $twoStepSecret = $ga->createSecret();
                $twoStepQrCodeUrl = $ga->getQRCodeGoogleUrl(sprintf('%s(%s)', get_setting('basic.SITENAME'), $curUser['username']), $twoStepSecret);
                $twoStepY = '<div style="display: flex;align-items:center">';
                $twoStepY .= sprintf('<div><img src="%s" /></div>', $twoStepQrCodeUrl);
                $twoStepY .= sprintf(
                    '<div style="padding-left: 20px">%s<a href="%s" target="_blank">Link</a><br /><br />%s%s<br/><br/>%s<input type="hidden" name="two_step_secret" value="%s" /><input type="text" name="two_step_code" readonly onfocus="this.removeAttribute(\'readonly\')"/></div>',
                    $lang['text_two_step_secret_bind_by_qrdoe_note'],
                    $twoStepQrCodeUrl,
                    $lang['text_two_step_secret_bind_manually_note'],
                    $twoStepSecret,
                    $lang['text_two_step_secret_bind_complete_note'],
                    $twoStepSecret
                );
                $twoStepY .= '</div>';
                $this->tr($lang['row_two_step_secret'], $twoStepY, 1);
            }
            printf('<tr><td class="rowhead" valign="top" align="right">%s</td><td class="rowfollow" valign="top" align="left">', nexus_trans('passkey.passkey'));
            \App\Repositories\UserPasskeyRepository::renderList($curUser['id']);
            printf('</td></tr>');

            $disableemailchange = $GLOBALS['disableemailchange'];
            $smtptype = $GLOBALS['smtptype'];
            if ($disableemailchange != 'no' && $smtptype != 'none') {
                $this->tr($lang['row_email_address'], '<input type="text" name="email" style="width: 200px" value="' . htmlspecialchars($curUser['email']) . '" /><br /><font class="small">' . $lang['text_email_address_note'] . '</font>', 1);
            }
            do_action('usercp_security_setting_form');
            $this->tr($lang['row_change_password'], '<input type="password" class="password" style="width: 200px" />', 1);
            echo '<input type="hidden" name="chpassword" />';
            $this->tr($lang['row_type_password_again'], '<input type="password" class="passagain" style="width: 200px" />', 1);
            $this->tr($lang['row_privacy_level'], $this->priv('normal', $lang['radio_normal']) . ' ' . $this->priv('low', $lang['radio_low']) . ' ' . $this->priv('strong', $lang['radio_strong']), 1);
            $this->submit('button');
            echo '</table></form>';

            render_password_hash_js('security', 'password', 'chpassword', false, 'passagain');
        });

        $pageTitle = $lang['head_control_panel'] . $lang['head_security_settings'];
        return view('usercp', compact('content', 'pageTitle'));
    }

    private function renderHome(array $curUser, array $lang)
    {
        $pageTitle = $lang['head_control_panel'] . $lang['head_home'];

        $content = $this->capture(function () use ($curUser, $lang) {
            \Nexus\Nexus::js('vendor/jquery-loading/jquery.loading.min.js', 'footer', true);
            $this->usercpmenu();

            $commentcount = get_row_count('comments', 'WHERE user=' . sqlesc($curUser['id']));

            if ($curUser['added'] == '0000-00-00 00:00:00' || $curUser['added'] == null) {
                $joindate = 'N/A';
            } else {
                $joindate = $curUser['added'] . ' (' . gettime($curUser['added'], true, false, true) . ')';
            }

            $Cache = $GLOBALS['Cache'] ?? null;
            if (!$forumposts = ($Cache ? $Cache->get_value('user_' . $curUser['id'] . '_post_count') : false)) {
                $forumposts = get_row_count('posts', 'WHERE userid=' . $curUser['id']);
                if ($Cache) {
                    $Cache->cache_value('user_' . $curUser['id'] . '_post_count', $forumposts, 3600);
                }
            }
            $dayposts = 0;
            $percentages = '';
            if ($forumposts) {
                $seconds3 = (TIMENOW - strtotime($curUser['added']));
                $days = round($seconds3 / 86400, 0);
                if ($days > 1) {
                    $dayposts = round(($forumposts / $days), 1);
                }
                if (!$postcount = ($Cache ? $Cache->get_value('total_posts_count') : false)) {
                    $postcount = get_row_count('posts');
                    if ($Cache) {
                        $Cache->cache_value('total_posts_count', $postcount, 96400);
                    }
                }
                $percentages = round($forumposts * 100 / $postcount, 3) . '%';
            }

            echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';
            $this->tr($lang['row_join_date'], $joindate, 1);
            $this->tr($lang['row_email_address'], $curUser['email'], 1);

            $seedBoxIcon = (new \App\Repositories\SeedBoxRepository())->renderIcon($curUser['ip'], $curUser['id']);
            $enablelocation_tweak = $GLOBALS['enablelocation_tweak'];
            if ($enablelocation_tweak == 'yes') {
                list($loc_pub, $loc_mod) = get_ip_location($curUser['ip']);
                $this->tr($lang['row_ip_location'], hide_text($curUser['ip'] . ' <span title="' . $loc_mod . '">[' . $loc_pub . ']</span>' . $seedBoxIcon), 1);
            } else {
                $this->tr($lang['row_ip_location'], hide_text($curUser['ip'] . $seedBoxIcon), 1);
            }
            if ($curUser['avatar']) {
                $this->tr($lang['row_avatar'], '<img src="' . $curUser['avatar'] . '" border="0">', 1);
            }
            $this->tr($lang['row_passkey'], hide_text($curUser['passkey']), 1);
            if (get_setting('security.login_type') == 'passkey' && get_setting('security.login_secret_deadline') > date('Y-m-d H:i:s')) {
                $this->tr($lang['row_passkey_login_url'], sprintf('%s/%s/%s', getSchemeAndHttpHost(), get_setting('security.login_secret'), $curUser['passkey']), 1);
            }

            $prolinkpoint_bonus = $GLOBALS['prolinkpoint_bonus'];
            if ($prolinkpoint_bonus) {
                $prolinkclick = get_row_count('prolinkclicks', 'WHERE userid=' . $curUser['id']);
                $this->tr($lang['row_promotion_link'], $prolinkclick . ' [<a href="promotionlink.php">' . $lang['text_read_more'] . '</a>]', 1);
            }
            $this->tr($lang['row_invitations'], $curUser['invites'] . ' [<a href="invite.php?id=' . $curUser['id'] . '" title="' . $lang['link_send_invitation'] . '">' . $lang['text_send'] . '</a>]', 1);
            $this->tr($lang['row_karma_points'], $curUser['seedbonus'] . ' [<a href="mybonus.php" title="' . $lang['link_use_karma_points'] . '">' . $lang['text_use'] . '</a>]', 1);
            $this->tr($lang['row_written_comments'], $commentcount . ' [<a href="userhistory.php?action=viewcomments&id=' . $curUser['id'] . '" title="' . $lang['link_view_comments'] . '">' . $lang['text_view'] . '</a>]', 1);

            if (get_setting('seed_box.enabled') == 'yes') {
                $columnOperator = nexus_trans('label.seed_box_record.operator');
                $columnBandwidth = nexus_trans('label.seed_box_record.bandwidth');
                $columnIP = nexus_trans('label.seed_box_record.ip');
                $columnIPHelp = nexus_trans('label.seed_box_record.ip_help');
                $columnComment = nexus_trans('label.comment');
                $columnStatus = nexus_trans('label.seed_box_record.status');
                $res = \App\Models\SeedBoxRecord::query()->where('uid', $curUser['id'])->where('type', \App\Models\SeedBoxRecord::TYPE_USER)->get();
                $seedBox = '';
                if ($res->count() > 0) {
                    $seedBox .= "<table border='1' cellspacing='0' cellpadding='5' id='seed-box-table'><tr><td class='colhead'>ID</td><td class='colhead'>{$columnOperator}</td><td class='colhead'>{$columnBandwidth}</td><td class='colhead'>{$columnIP}</td><td class='colhead'>{$columnComment}</td><td class='colhead'>{$columnStatus}</td><td class='colhead'></td></tr>";
                    foreach ($res as $seedBoxRecord) {
                        $seedBox .= '<tr>';
                        $seedBox .= sprintf('<td>%s</td>', $seedBoxRecord->id);
                        $seedBox .= sprintf('<td>%s</td>', $seedBoxRecord->operator);
                        $seedBox .= sprintf('<td>%s</td>', $seedBoxRecord->bandwidth ?: '');
                        $seedBox .= sprintf('<td>%s</td>', $seedBoxRecord->ip ?: sprintf('%s ~ %s', $seedBoxRecord->ip_begin, $seedBoxRecord->ip_end));
                        $seedBox .= sprintf('<td>%s</td>', $seedBoxRecord->comment);
                        $seedBox .= sprintf('<td>%s</td>', $seedBoxRecord->statusText);
                        $seedBox .= sprintf('<td><img style="cursor: pointer" class="staff_delete remove-seed-box-btn" src="pic/trans.gif" alt="D" title="%s" data-id="%s"></td>', $GLOBALS['lang_functions']['text_delete'], $seedBoxRecord->id);
                        $seedBox .= '</tr>';
                    }
                    $seedBox .= '</table>';
                }
                $seedBox .= sprintf('<div><input type="button" id="add-seed-box-btn" value="%s"/></div>', $lang['add_seed_box_btn']);
                $this->tr($lang['row_seed_box'], $seedBox, 1);

                $seedBoxForm = <<<FORM
<div class="form-box">
<form id="seed-box-form">
    <div class="form-control-row">
        <div class="label">{$columnOperator}</div>
        <div class="field"><input type="text" name="params[operator]"></div>
    </div>
    <div class="form-control-row">
        <div class="label">{$columnBandwidth}</div>
        <div class="field"><input type="number" name="params[bandwidth]"></div>
    </div>
    <div class="form-control-row">
        <div class="label">{$columnIP}</div>
        <div class="field"><input type="text" name="params[ip]"></div>
    </div>
    <div class="form-control-row">
        <div class="label">{$columnComment}</div>
        <div class="field"><textarea name="params[comment]" rows="4"></textarea></div>
    </div>
</form>
</div>
FORM;
                $seedBoxJs = <<<JS
jQuery('#add-seed-box-btn').on('click', function () {
    layer.open({
        type: 1,
        title: "{$lang['row_seed_box']} {$lang['add_seed_box_btn']}",
        content: `$seedBoxForm`,
        btn: ['OK'],
        btnAlign: 'c',
        yes: function () {
            let params = jQuery('#seed-box-form').serialize()
            jQuery('body').loading({stoppable: false});
            jQuery.post('ajax.php', params + "&action=addSeedBoxRecord", function (response) {
                jQuery('body').loading('stop');
                if (response.ret != 0) {
                    layer.alert(response.msg)
                    return
                }
                window.location.reload()
            }, 'json')
        }
    })
});
jQuery('#seed-box-table').on('click', '.remove-seed-box-btn', function () {
    let params = {action: "removeSeedBoxRecord", params: {id: jQuery(this).attr("data-id")}}
    layer.confirm("{$GLOBALS['lang_functions']['std_confirm_remove']}", window.nexusLayerOptions.confirm, function (index) {
        jQuery('body').loading({stoppable: false});
        jQuery.post('ajax.php', params, function (response) {
            jQuery('body').loading('stop');
            if (response.ret != 0) {
                layer.alert(response.msg, window.nexusLayerOptions.alert)
                return
            }
            window.location.reload()
        }, 'json')
    })
});
JS;
                \Nexus\Nexus::js($seedBoxJs, 'footer', false);
            }

            $permissions = \App\Repositories\TokenRepository::listUserTokenPermissionAllowed();
            $permissionOptions = [];
            foreach ($permissions as $name => $label) {
                $permissionOptions[] = sprintf('<label><input type="checkbox" name="permissions[]" value="%s">%s</label>', $name, $label);
            }
            $permissionCheckbox = implode('', $permissionOptions);
            $token = '';
            $tokenLabel = nexus_trans('token.label');
            $columnName = nexus_trans('label.name');
            $columnPermission = nexus_trans('token.permission');
            $columnCreatedAt = nexus_trans('label.created_at');
            $actionCreate = nexus_trans('label.create');
            $actionLabel = nexus_trans('label.action');
            $userModel = \App\Models\User::query()->find($curUser['id']);
            $res = $userModel->tokens()->orderBy('id', 'desc')->get();
            if ($res->count() > 0) {
                $token .= "<table border='1' cellspacing='0' cellpadding='5' id='token-table'><tr><td class='colhead'>ID</td><td class='colhead'>{$columnName}</td><td class='colhead'>{$columnPermission}</td><td class='colhead'>{$columnCreatedAt}</td><td class='colhead'>{$actionLabel}</td></tr>";
                foreach ($res as $tokenRecord) {
                    $token .= '<tr>';
                    $token .= sprintf('<td>%s</td>', $tokenRecord->id);
                    $token .= sprintf('<td>%s</td>', $tokenRecord->name);
                    $token .= sprintf('<td>%s</td>', $tokenRecord->abilitiesText);
                    $token .= sprintf('<td>%s</td>', $tokenRecord->created_at);
                    $token .= sprintf('<td><img style="cursor: pointer" class="staff_delete token-del" src="pic/trans.gif" alt="D" title="%s" data-id="%s"></td>', $GLOBALS['lang_functions']['text_delete'], $tokenRecord->id);
                    $token .= '</tr>';
                }
                $token .= '</table>';
            }
            $token .= sprintf('<div><input type="button" id="add-token-box-btn" value="%s"/></div>', $actionCreate);
            $this->tr($tokenLabel, $token, 1);
            $tokenFoxForm = <<<FORM
<div class="form-box">
<form id="token-box-form">
    <div class="form-control-row">
        <div class="label">{$columnName}</div>
        <div class="field"><input type="text" name="name"></div>
    </div>
    <div class="form-control-row">
        <div class="label">{$columnPermission}</div>
        <div class="field">$permissionCheckbox</div>
    </div>
</form>
</div>
FORM;
            $tokenBoxJs = <<<JS
jQuery('#add-token-box-btn').on('click', function () {
    layer.open({
        type: 1,
        title: "{$tokenLabel} {$actionCreate}",
        content: `$tokenFoxForm`,
        btn: ['OK'],
        btnAlign: 'c',
        yes: function (index) {
            layer.close(index);
            jQuery('body').loading({stoppable: false});
            let params = jQuery('#token-box-form').serialize()
            jQuery.post('/web/token/add', params, function (response) {
                 jQuery('body').loading('stop');
                if (response.ret != 0) {
                    layer.alert(response.msg, window.nexusLayerOptions.alert)
                } else {
                    layer.alert(response.msg, window.nexusLayerOptions.alert, function(index) {
                        layer.close(index);
                        window.location.reload()
                    })
                }
            }, 'json')
        }
    })
});
jQuery('#token-table').on('click', '.token-del', function () {
    let params = {id: jQuery(this).attr("data-id")}
    layer.confirm("{$GLOBALS['lang_functions']['std_confirm_remove']}", window.nexusLayerOptions.confirm, function (index) {
        layer.close(index)
        jQuery('body').loading({stoppable: false});
        jQuery.post('/web/token/del', params, function (response) {
            if (response.ret != 0) {
                jQuery('body').loading('stop');
                layer.alert(response.msg, window.nexusLayerOptions.alert)
                return
            }
            window.location.reload()
        }, 'json')
    })
});
JS;
            \Nexus\Nexus::js($tokenBoxJs, 'footer', false);

            if ($forumposts) {
                $this->tr($lang['row_forum_posts'], $forumposts . ' [<a href="userhistory.php?action=viewposts&id=' . $curUser['id'] . '" title="' . $lang['link_view_posts'] . '">' . $lang['text_view'] . '</a>] (' . $dayposts . $lang['text_posts_per_day'] . '; ' . $percentages . $lang['text_of_total_posts'] . ')', 1);
            }
            echo '</table>';

            echo '<table border="0" cellspacing="0" cellpadding="5" width="' . CONTENT_WIDTH . '">';
            echo '<tr><td align="center" class="tabletitle"><b>' . $lang['text_recently_read_topics'] . '</b></td></tr>';
            echo '</table>';

            echo '<table border="0" cellspacing="0" cellpadding="3" width="' . CONTENT_WIDTH . '"><tr>'
                . '<td class="colhead" align="left" width="80%">' . $lang['col_topic_title'] . '</td>'
                . '<td class="colhead" align="center"><nobr>' . $lang['col_replies'] . '/' . $lang['col_views'] . '</nobr></td>'
                . '<td class="colhead" align="center">' . $lang['col_topic_starter'] . '</td>'
                . '<td class="colhead" align="center" width="20%">' . $lang['col_last_post'] . '</td>'
                . '</tr>';

            $topicRows = \Illuminate\Support\Facades\DB::select('
                SELECT t.*, rp.id as rpid FROM readposts rp
                INNER JOIN topics t ON t.id = rp.topicid
                WHERE rp.userid = ?
                ORDER BY rp.id DESC
                LIMIT 5
            ', [$curUser['id']]);

            foreach ($topicRows as $topicarr) {
                $topicid = $topicarr->id;
                $topicarr = (array) $topicarr;

                if (!$posts = ($Cache ? $Cache->get_value('topic_' . $topicid . '_post_count') : false)) {
                    $posts = get_row_count('posts', 'WHERE topicid=' . sqlesc($topicid));
                    if ($Cache) {
                        $Cache->cache_value('topic_' . $topicid . '_post_count', $posts, 3600);
                    }
                }
                $replies = max(0, $posts - 1);
                $views = number_format($topicarr['views']);

                $arr = get_post_row($topicarr['lastpost']);
                $postid = intval($arr['id'] ?? 0);
                $userid = intval($arr['userid'] ?? 0);
                $added = gettime($arr['added'] ?? '', true, false);

                $username = get_username($userid);
                $author = get_username($topicarr['userid']);
                $subject = '<a href="forums.php?action=viewtopic&topicid=' . $topicid . '"><b>' . htmlspecialchars($topicarr['subject']) . '</b></a>';

                echo '<tr class="tableb"><td style="padding-left: 10px" align="left" class="rowfollow">' . $subject . '</td>'
                    . '<td align="center" class="rowfollow">' . $replies . '/' . $views . '</td>'
                    . '<td align="center" class="rowfollow">' . $author . '</td>'
                    . '<td align="center" class="rowfollow"><nobr>' . $added . ' | ' . $username . '</nobr></td></tr>';
            }
            echo '</table>';
        });

        return view('usercp', compact('content', 'pageTitle'));
    }

    private function browsecheck(string $table, string $prefix, array &$result): void
    {
        $rows = \Illuminate\Support\Facades\DB::table($table)->get(['id']);
        foreach ($rows as $row) {
            if (isset($_POST[$prefix . $row->id]) && $_POST[$prefix . $row->id] == 'yes') {
                $result[$prefix . $row->id] = 1;
            } else {
                unset($result[$prefix . $row->id]);
            }
        }
    }

    private function usercpmenu(string $selected = 'home'): void
    {
        $lang = $GLOBALS['lang_usercp'];
        begin_main_frame();
        echo '<div id="usercpnav"><ul id="usercpmenu" class="menu">';
        echo '<li' . ($selected == 'home' ? ' class="selected"' : '') . '><a href="usercp.php">' . $lang['text_user_cp_home'] . '</a></li>';
        echo '<li' . ($selected == 'personal' ? ' class="selected"' : '') . '><a href="?action=personal">' . $lang['text_personal_settings'] . '</a></li>';
        echo '<li' . ($selected == 'tracker' ? ' class="selected"' : '') . '><a href="?action=tracker">' . $lang['text_tracker_settings'] . '</a></li>';
        echo '<li' . ($selected == 'forum' ? ' class="selected"' : '') . '><a href="?action=forum">' . $lang['text_forum_settings'] . '</a></li>';
        echo '<li' . ($selected == 'security' ? ' class="selected"' : '') . '><a href="?action=security">' . $lang['text_security_settings'] . '</a></li>';
        echo '</ul></div>';
        end_main_frame();
    }

    private function form(string $name, string $type = 'save', string $id = ''): void
    {
        if ($id == '') {
            $id = 'form' . random_str();
        }
        echo '<form method="post" action="usercp.php" id="' . $id . '"><input type="hidden" name="action" value="' . htmlspecialchars($name) . '"><input type="hidden" name="type" value="' . $type . '">';
    }

    private function submit(string $type = 'submit'): void
    {
        $lang = $GLOBALS['lang_usercp'];
        echo '<tr><td class="rowhead" valign="top" align="right">' . $lang['row_save_settings'] . '</td><td class="rowfollow" valign="top" align="left"><input type="' . $type . '" value="' . $lang['submit_save_settings'] . '"></td></tr>';
    }

    private function priv(string $name, string $descr): string
    {
        $curUser = $GLOBALS['CURUSER'];
        $checked = $curUser['privacy'] == $name ? ' checked="checked"' : '';
        return '<input type="radio" name="privacy" value="' . htmlspecialchars($name) . '"' . $checked . ' /> ' . htmlspecialchars($descr);
    }

    private function goback(string $where = '-1'): string
    {
        $lang = $GLOBALS['lang_usercp'];
        $text = $lang['text_go_back'];
        return '<a class="faqlink" href="javascript:history.go(' . htmlspecialchars($where) . ')">' . htmlspecialchars($text) . '</a>';
    }

    private function tr(string $label, string $value, int $small = 0): void
    {
        $func = $small ? 'tr_small' : 'tr';
        $func($label, $value, 1);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}