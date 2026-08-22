<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Rule;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RulesController extends Controller
{
    /**
     * Site rules page. Mirrors legacy public/rules.php: renders every rule
     * row of the guest language (falls back to English when the guest language
     * is not marked rule_lang). Open to guests and logged-in users alike.
     */
    public function web(Request $request)
    {
        $lang = get_legacy_lang_file('rules');
        $GLOBALS['lang_rules'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        /** @var \App\Models\User|null $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        $GLOBALS['CURUSER'] = $currentUser ? $currentUser->toArray() : [];

        $langId = $this->resolveLangId();

        $rules = Rule::query()
            ->where('lang_id', $langId)
            ->orderBy('id')
            ->get(['title', 'text']);

        $content = $this->capture(function () use ($rules) {
            begin_main_frame();
            foreach ($rules as $rule) {
                begin_frame($rule->title, false);
                print(format_comment($rule->text));
                end_frame();
            }
            end_main_frame();
        });

        return view('rules', compact('content') + [
            'pageTitle' => $lang['head_rules'],
        ]);
    }

    /**
     * Resolve the language id for the rules/FAQ content: the guest language
     * folder, unless that language is not marked rule_lang, in which case the
     * English (id 6) content is shown. Mirrors the legacy logic.
     */
    private function resolveLangId(): int
    {
        $langFolder = get_langfolder_cookie();
        $language = Language::query()
            ->where('site_lang_folder', $langFolder)
            ->where('site_lang', 1)
            ->first(['id', 'rule_lang']);

        if ($language && $language->rule_lang) {
            return (int) $language->id;
        }

        return 6;
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
