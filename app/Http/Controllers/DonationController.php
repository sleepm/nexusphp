<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DonationController extends Controller
{
    /**
     * Donation page. Mirrors legacy public/donate.php so the donation link in
     * the site header keeps working under the Laravel router. Open to guests:
     * GET /donate.php renders the PayPal/Alipay donation forms (plus optional
     * custom text); ?do=thanks shows the post-donation thank-you note.
     */
    public function web(Request $request)
    {
        $lang = get_legacy_lang_file('donate');
        $GLOBALS['lang_donate'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if (Setting::getByName('main.donation', 'no') != 'yes') {
            return $this->noticePage($lang, $lang['std_sorry'], $lang['std_do_not_accept_donation']);
        }

        $accountantId = (int) Setting::getByName('main.ACCOUNTANTID', 1);

        if ((string) $request->query('do', '') == 'thanks') {
            $text = $lang['std_donation_success_note_one']
                . '<a href="sendmessage.php?receiver=' . $accountantId . '"><b>' . $lang['std_here'] . '</b></a>'
                . $lang['std_donation_success_note_two'];
            return $this->noticePage($lang, $lang['std_success'], $text);
        }

        $custom = trim((string) Setting::getByName('misc.donation_custom'));
        $paypal = safe_email((string) Setting::getByName('main.PAYPALACCOUNT', ''));
        $showPaypal = $paypal !== '' && check_email($paypal);
        $alipay = safe_email((string) Setting::getByName('main.ALIPAYACCOUNT', ''));
        $showAlipay = $alipay !== '' && check_email($alipay);

        if (! $showPaypal && ! $showAlipay && ! $custom) {
            return $this->noticePage($lang, $lang['std_error'], $lang['std_no_donation_account_available']);
        }

        if ($showPaypal && $showAlipay) {
            $tdattr = 'width="50%"';
        } elseif ($showPaypal || $showAlipay) {
            $tdattr = 'colspan="2" width="100%"';
        } else {
            $tdattr = '';
        }

        $siteName = Setting::getSiteName();
        $baseUrl = Setting::getBaseUrl();

        $content = $this->capture(function () use (
            $lang, $custom, $showPaypal, $showAlipay, $paypal, $alipay, $tdattr,
            $accountantId, $siteName, $baseUrl
        ) {
            print('<h2>' . $lang['text_donate'] . '</h2>');
            print('<table width=100%>');
            print('<tr><td colspan=2 class=text align=left>' . $lang['text_donation_note'] . '</td></tr>');
            if ($custom) {
                echo sprintf('<tr><td class="text" align="left" colspan="2">%s</td></tr>', format_comment($custom));
            }
            print('<tr>');

            if ($showPaypal) {
                print('<td class=text align=left valign=top ' . $tdattr . '>');
                print('<b>' . $lang['text_donate_with_paypal'] . '</b><br /><br />');
                print($lang['text_donate_paypal_note']);
                print(' <form action="https://www.paypal.com/cgi-bin/webscr" method="post">');
                print('  <input type="hidden" name="cmd" value="_xclick">');
                print('  <input type="hidden" name="business" value="' . $paypal . '">');
                print('  <input type="hidden" name="item_name" value="Donation to ' . $siteName . '">');
                print('  <p align="center">');
                print('<br />');
                print('  ' . $lang['text_select_donation_amount']);
                print('<br />');
                print('   <select name="amount">');
                print('<option value="" selected>' . $lang['select_choose_donation_amount'] . '</option>');
                $allowedDonationUsdAmounts = array(0, 1, 5, 10, 15, 20, 30, 40, 50, 60, 100, 300);
                foreach ($allowedDonationUsdAmounts as $amount) {
                    if ($amount == 0) {
                        echo '<option value="">' . $lang['select_other_donation_amount'] . '</option>';
                    } else {
                        $amount = number_format($amount, 2);
                        echo '<option value=' . $amount . '>' . $lang['text_usd_mark'] . $amount . $lang['text_donation'] . '</option>';
                    }
                }
                print('</select>');
                print('    <input type="hidden" name="image_url" value="">');
                print('    <input type="hidden" name="shipping" value="0">');
                print('    <input type="hidden" name="currency_code" value="USD">');
                print('    <input type="hidden" name="return" value="' . get_protocol_prefix() . $baseUrl . '/donate.php?do=thanks">');
                print('<input type="hidden" name="cancel_return" value="' . get_protocol_prefix() . $baseUrl . '/donate.php">');
                print('<br />');
                print('</p>');
                print('<p align="center">');
                print('<input type="image" src="pic/paypalbutton.gif" border="0" name="I1" alt="Make payments with PayPal">');
                print('<br /><br /></p>');
                print('</form></td>');
            }

            if ($showAlipay) {
                print('<td class=text align=left valign=top ' . $tdattr . '>');
                print('<b>' . $lang['text_donate_with_alipay'] . '</b><br /><br />');
                print('<form action="https://www.alipay.com/trade/fast_pay.htm" method="get">');
                print($lang['text_donate_alipay_note_one'] . '<b>' . $alipay . '</b>' . $lang['text_donate_alipay_note_two']);
                print('<br /><br /><br /><br /><br />');
                print('<p align="center">');
                print('<input type="image" src="pic/alipaybutton.gif" border="0" name="I2" alt="Make payments with Alipay" />');
                print('<br /><br /></p>');
                print('</form></td>');
            }

            print('</tr>');
            print('<tr><td class=text colspan=2 align=left>' . $lang['text_after_donation_note_one']
                . '<a href="sendmessage.php?receiver=' . $accountantId . '"><font class="striking"><b>' . $lang['text_send_us'] . '</b></font></a>'
                . $lang['text_after_donation_note_two'] . '</td></tr>');
            print('</table>');
        });

        return view('donate', compact('content') + [
            'pageTitle' => $lang['head_donation'],
        ]);
    }

    /**
     * Donated amount update form + submission. Mirrors legacy public/donated.php:
     * administrators (SYSOP and above) set a user's donated amount, then get
     * redirected back to the user's profile.
     */
    public function webDonated(Request $request)
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
        if (get_user_class() < User::CLASS_SYSOP) {
            abort(403, 'Access denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->input('username', ''));
            $donated = trim((string) $request->input('donated', ''));
            if ($username === '' || $donated === '') {
                abort(400, 'Missing form data.');
            }
            $user = User::query()->where('username', $username)->first(['id']);
            if (! $user) {
                abort(400, 'Unable to update account.');
            }
            User::query()->where('id', $user->id)->update(['donated' => (float) $donated]);
            return redirect(get_protocol_prefix() . Setting::getBaseUrl() . '/userdetails.php?id=' . $user->id);
        }

        $content = $this->capture(function () {
            print('<h1>Update Users Donated Amounts</h1>');
            print('<form method="post" action="donated.php">');
            print('<table border="1" cellspacing="0" cellpadding="5">');
            print('<tr><td class="rowhead">User name</td><td><input type="text" name="username" size="40"></td></tr>');
            print('<tr><td class="rowhead">Donated</td><td><input type="text" name="donated" size="5"></td></tr>');
            print('<tr><td colspan="2" align="center"><input type="submit" value="Okay" class="btn"></td></tr>');
            print('</table>');
            print('</form>');
        });

        return view('donated', compact('content') + [
            'pageTitle' => 'Update Users Donated Amounts',
        ]);
    }

    private function noticePage(array $lang, string $heading, string $text)
    {
        $content = $this->capture(function () use ($heading, $text) {
            stdmsg($heading, $text, false);
        });
        return view('donate', compact('content') + [
            'pageTitle' => $lang['head_donation'],
        ]);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}