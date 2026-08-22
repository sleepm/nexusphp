<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\Language;
use App\Models\Setting;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    /**
     * FAQ page. Mirrors legacy public/faq.php: renders the welcome intro,
     * a table of contents (categories + items), then each category + its
     * items. Open to guests.
     */
    public function web(Request $request)
    {
        $lang = get_legacy_lang_file('faq');
        $GLOBALS['lang_faq'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $siteName = Setting::getSiteName();
        $slogan = get_setting('main.SLOGAN', '');

        $langId = $this->resolveLangId();

        $categs = Faq::query()
            ->where('type', Faq::TYPE_CATEG)
            ->where('lang_id', $langId)
            ->orderBy('order')
            ->get()
            ->keyBy('link_id');

        $items = Faq::query()
            ->where('type', Faq::TYPE_ITEM)
            ->where('lang_id', $langId)
            ->orderBy('order')
            ->get()
            ->groupBy('categ');

        $content = $this->capture(function () use ($lang, $siteName, $slogan, $categs, $items) {
            begin_main_frame();

            begin_frame($lang['text_welcome_to'] . $siteName . ' - ' . $slogan);
            echo sprintf(
                $lang['text_welcome_content_one']
                . sprintf($lang['text_welcome_content_two'], $siteName, $siteName)
            );
            end_frame();

            if ($categs->isNotEmpty()) {
                $this->renderToc($lang, $categs, $items);
                $this->renderContent($lang, $categs, $items);
            }

            end_main_frame();
        });

        return view('faq', compact('content') + [
            'pageTitle' => $lang['head_faq'],
        ]);
    }

    private function renderToc(array $lang, $categs, $items): void
    {
        begin_frame('<span id="top">' . $lang['text_contents'] . '</span>');
        foreach ($categs as $linkId => $categ) {
            if ($categ->flag != Faq::FLAG_NORMAL) {
                continue;
            }
            print('<ul><li><a href="#id' . $categ->link_id . '"><b>' . $categ->question . '</b></a><ul>' . "\n");
            $categItems = $items->get($categ->link_id, collect());
            foreach ($categItems as $item) {
                if ($item->flag == Faq::FLAG_NORMAL) {
                    print('<li><a href="#id' . $item->link_id . '" class="faqlink">' . $item->question . '</a></li>' . "\n");
                } elseif ($item->flag == Faq::FLAG_UPDATED) {
                    print('<li><a href="#id' . $item->link_id . '" class="faqlink">' . $item->question . '</a> <img class="faq_updated" src="pic/trans.gif" alt="Updated" /></li>' . "\n");
                } elseif ($item->flag == Faq::FLAG_NEW) {
                    print('<li><a href="#id' . $item->link_id . '" class="faqlink">' . $item->question . '</a> <img class="faq_new" src="pic/trans.gif" alt="New" /></li>' . "\n");
                }
            }
            print('</ul></li></ul><br />' . "\n");
        }
        end_frame();
    }

    private function renderContent(array $lang, $categs, $items): void
    {
        foreach ($categs as $linkId => $categ) {
            if ($categ->flag != Faq::FLAG_NORMAL) {
                continue;
            }
            $frame = $categ->question . ' - <a href="#top"><img class="top" src="pic/trans.gif" alt="Top" title="Top" /></a>';
            begin_frame($frame);
            print('<span id="id' . $categ->link_id . '"></span>');
            $categItems = $items->get($categ->link_id, collect());
            foreach ($categItems as $item) {
                if ($item->flag != Faq::FLAG_HIDDEN) {
                    print('<br /><span id="id' . $item->link_id . '"><b>' . $item->question . '</b></span><br />' . "\n");
                    print('<br />' . $item->answer . "\n<br /><br />\n");
                }
            }
            end_frame();
        }
    }

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