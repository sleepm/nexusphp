<?php

namespace App\Http\Controllers;

use App\Enums\Permission\PermissionEnum;
use App\Models\BonusLogs;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\BonusRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BonusLogController extends Controller
{
    /**
     * User bonus log. Mirrors legacy public/bonus-log.php so the per-user bonus
     * detail link from userdetails.php keeps working under the Laravel router.
     *
     * GET /bonus-log.php?uid=&category=&business_type= renders the paginated
     * bonus log table (common + optional seeding categories). Viewing another
     * user's log requires the viewhistory permission.
     */
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

        // globals the shared legacy helpers expect (mirrors public/bonus-log.php bootstrap)
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $uid = (int) $request->input('uid', $curUser['id']);
        if ($uid <= 0) {
            abort(404, "Invalid uid: $uid");
        }
        $user = User::query()->where('id', $uid)->first(User::$commonFields);
        if (! $user) {
            abort(404, "Invalid uid: $uid");
        }
        if ($uid != $curUser['id']) {
            $canView = user_can(PermissionEnum::VIEW_USER_HISTORY->value, false, $curUser['id']);
            if (! $canView) {
                abort(403, 'Access denied.');
            }
        }

        $isRecordSeedingBonusLog = Setting::getIsRecordSeedingBonusLog();
        $defaultCategory = BonusLogs::CATEGORY_COMMON;
        $category = (string) $request->input('category', $defaultCategory);
        $categoryOptions = BonusLogs::listCategoryOptions($isRecordSeedingBonusLog);
        if (! isset($categoryOptions[$category])) {
            abort(404, "Invalid category: $category");
        }
        $businessType = (int) $request->input('business_type', 0);
        $businessTypeOptions = BonusLogs::listBusinessTypeOptions($isRecordSeedingBonusLog ? '' : $defaultCategory);
        if ($businessType && ! isset($businessTypeOptions[$businessType])) {
            abort(404, "Invalid business_type: $businessType");
        }

        $pageTitle = nexus_trans('bonus-log.title_for_user');

        $content = $this->capture(function () use ($uid, $user, $category, $businessType, $categoryOptions, $businessTypeOptions, $request) {
            print('<h1 align="center">' . nexus_trans('bonus-log.title_for_user')
                . '<a href="userdetails.php?id=' . htmlspecialchars($uid) . '"><b>&nbsp;' . htmlspecialchars($user->username) . '</b></a></h1>');

            $textSelectOnePlease = nexus_trans('nexus.select_one_please');
            $categoryOptionsText = $businessTypeOptionsText = '';
            foreach ($categoryOptions as $name => $text) {
                $categoryOptionsText .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    $name, $request->input('category') == $name ? ' selected' : '', $text
                );
            }
            foreach ($businessTypeOptions as $name => $text) {
                $businessTypeOptionsText .= sprintf(
                    '<option value="%s"%s>%s</option>',
                    $name, $request->input('business_type') == $name ? ' selected' : '', $text
                );
            }

            $resetText = nexus_trans('label.reset');
            $submitText = nexus_trans('label.submit');
            $categoryText = nexus_trans('bonus-log.category');
            $businessTypeText = nexus_trans('bonus-log.fields.business_type');
            print('<div>
                <form id="filterForm" action="' . $request->fullUrl() . '" method="get">
                    <input type="hidden" name="uid" value="' . $uid . '" />
                    <span>' . $categoryText . ':</span>
                    <select name="category">
                        ' . $categoryOptionsText . '
                    </select>
                    &nbsp;&nbsp;
                    <span>' . $businessTypeText . ':</span>
                    <select name="business_type">
                        <option value="0">-' . $textSelectOnePlease . '-</option>
                        ' . $businessTypeOptionsText . '
                    </select>
                    &nbsp;&nbsp;
                    <input type="submit" value="' . $submitText . '">
                    <input type="button" id="reset" value="' . $resetText . '">
                </form>
            </div>');

            print('<script>jQuery("#reset").on(\'click\', function () {
    jQuery("select[name=category]").val(\'\')
    jQuery("select[name=business_type]").val(\'\')
})</script>');

            $rep = new BonusRepository();
            $total = $rep->getCount($category, $uid, $businessType);
            $pagerParam = '?uid=' . $uid . '&category=' . $category . '&business_type=' . $businessType;
            list($pagertop, $pagerbottom, $limit, $offset, $pageSize, $page) = pager(50, $total, "$pagerParam&");
            $list = $rep->getList($category, $uid, $businessType, $page + 1, $pageSize);

            print('<table id="bonus-log-table" width="100%" cellpadding="5">');
            print('<tr>
                <td class="colhead" align="left">' . nexus_trans('bonus-log.fields.business_type') . '</td>
                <td class="colhead" align="left">' . nexus_trans('bonus-log.fields.old_total_value') . '</td>
                <td class="colhead" align="left">' . nexus_trans('bonus-log.fields.value') . '</td>
                <td class="colhead" align="left">' . nexus_trans('bonus-log.fields.new_total_value') . '</td>
                <td class="colhead" align="left">' . nexus_trans('label.comment') . '</td>
                <td class="colhead" align="left">' . nexus_trans('label.created_at') . '</td>
            </tr>');
            foreach ($list as $row) {
                print('<tr>
                    <td class="rowfollow nowrap" align="left">' . $row->businessTypeText . '</td>
                    <td class="rowfollow nowrap" align="left">' . ($row->old_total_value > 0 ? number_format($row->old_total_value, 1) : '-') . '</td>
                    <td class="rowfollow nowrap" align="left">' . ($row->old_total_value < $row->new_total_value ? '+' . number_format($row->value, 1) : '-' . number_format($row->value, 1)) . '</td>
                    <td class="rowfollow nowrap" align="left">' . ($row->new_total_value > 0 ? number_format($row->new_total_value, 1) : '-') . '</td>
                    <td class="rowfollow nowrap" align="left">' . $row->comment . '</td>
                    <td class="rowfollow nowrap" align="left">' . $row->created_at . '</td>
                </tr>');
            }
            print('</table>');
            print($pagerbottom);
        });

        return view('bonus-log', compact('content') + [
            'pageTitle' => $pageTitle,
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
