<?php

namespace App\Http\Controllers;

use App\Http\Resources\TagResource;
use App\Models\Setting;
use App\Models\Tag;
use App\Repositories\TagRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    private $repository;

    public function __construct(TagRepository $repository)
    {
        $this->repository = $repository;
    }

    private function getRules($id = null): array
    {
        return [
            'name' => ['required', 'string', Rule::unique('tags', 'name')->ignore($id)],
            'color' => 'required|string',
        ];
    }

    /**
     * BB tags help page. Mirrors legacy public/tags.php (open to guests: no
     * auth middleware in the route). Renders the full list of supported BB
     * tags with syntax/example/result tables plus a "test this code" form whose
     * POST result is previewed through format_comment().
     */
    public function web(Request $request)
    {
        $lang = get_legacy_lang_file('tags');
        $GLOBALS['lang_tags'] = $lang;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $currentUser = Auth::guard('nexus')->user();
        /** @var \App\Models\User|null $currentUser */
        $curUser = $currentUser ? $currentUser->toArray() : [];
        $GLOBALS['CURUSER'] = $curUser;

        $siteName = Setting::getSiteName();
        $test = (string) $request->input('test', '');

        $content = $this->capture(function () use ($lang, $curUser, $siteName, $test) {
            begin_main_frame();
            begin_frame($lang['text_tags']);

            print '<p>' . sprintf($lang['text_bb_tags_note'], $siteName) . '</p>';

            print '<form method="post" action="?">';
            print '<textarea name="test" cols="60" rows="3">' . ($test !== '' ? htmlspecialchars($test) : '') . '</textarea>';
            print '<input type="submit" style="height: 23px; margin-left: 5px" value="' . $lang['submit_test_this_code'] . '">';
            print '</form>';

            if ($test !== '') {
                print '<p><hr>' . format_comment($test) . '</hr></p>';
            }

            $this->insertTag($lang, $lang['text_bold'], $lang['text_bold_description'], $lang['text_bold_syntax'], $lang['text_bold_example']);
            $this->insertTag($lang, $lang['text_italic'], $lang['text_italic_description'], $lang['text_italic_syntax'], $lang['text_italic_example']);
            $this->insertTag($lang, $lang['text_underline'], $lang['text_underline_description'], $lang['text_underline_syntax'], $lang['text_underline_example']);
            $this->insertTag($lang, $lang['text_strikethrough'], $lang['text_strikethrough_description'], $lang['text_strikethrough_syntax'], $lang['text_strikethrough_example']);
            $this->insertTag($lang, $lang['text_hide'], $lang['text_hide_description'], $lang['text_hide_syntax'], $lang['text_hide_example']);
            $this->insertTag($lang, $lang['text_color_one'], $lang['text_color_one_description'], $lang['text_color_one_syntax'], $lang['text_color_one_example'], $lang['text_color_one_remarks']);
            $this->insertTag($lang, $lang['text_color_two'], $lang['text_color_two_description'], $lang['text_color_two_syntax'], $lang['text_color_two_example'], $lang['text_color_two_remarks']);
            $this->insertTag($lang, $lang['text_size'], $lang['text_size_description'], $lang['text_size_syntax'], $lang['text_size_example'], $lang['text_size_remarks']);
            $this->insertTag($lang, $lang['text_font'], $lang['text_font_description'], $lang['text_font_syntax'], $lang['text_font_example'], $lang['text_font_remarks']);
            $this->insertTag($lang, $lang['text_hyperlink_one'], $lang['text_hyperlink_one_description'], $lang['text_hyperlink_one_syntax'], sprintf($lang['text_hyperlink_one_example'], getSchemeAndHttpHost()), $lang['text_hyperlink_one_remarks']);
            $this->insertTag($lang, $lang['text_hyperlink_two'], $lang['text_hyperlink_two_description'], $lang['text_hyperlink_two_syntax'], sprintf($lang['text_hyperlink_two_example'], getSchemeAndHttpHost(), $siteName), $lang['text_hyperlink_two_remarks']);
            $this->insertTag($lang, $lang['text_image_one'], $lang['text_image_one_description'], $lang['text_image_one_syntax'], sprintf($lang['text_image_one_example'], getSchemeAndHttpHost()), $lang['text_image_one_remarks']);
            $this->insertTag($lang, $lang['text_image_two'], $lang['text_image_two_description'], $lang['text_image_two_syntax'], sprintf($lang['text_image_two_example'], getSchemeAndHttpHost()), $lang['text_image_two_remarks']);
            $this->insertTag($lang, $lang['text_quote_one'], $lang['text_quote_one_description'], $lang['text_quote_one_syntax'], sprintf($lang['text_quote_one_example'], $siteName));
            $this->insertTag($lang, $lang['text_quote_two'], $lang['text_quote_two_description'], $lang['text_quote_two_syntax'], sprintf($lang['text_quote_two_example'], $curUser['username'] ?? '', $siteName));
            $this->insertTag($lang, $lang['text_list'], $lang['text_description'], $lang['text_list_syntax'], $lang['text_list_example']);
            $this->insertTag($lang, $lang['text_preformat'], $lang['text_preformat_description'], $lang['text_preformat_syntax'], $lang['text_preformat_example']);
            $this->insertTag($lang, $lang['text_code'], $lang['text_code_description'], $lang['text_code_syntax'], $lang['text_code_example']);
            $this->insertTag($lang, $lang['text_site'], $lang['text_site_description'], $lang['text_site_syntax'], $lang['text_site_example']);
            $this->insertTag($lang, $lang['text_siteurl'], $lang['text_siteurl_description'], $lang['text_siteurl_syntax'], $lang['text_siteurl_example']);
            $this->insertTag($lang, $lang['text_left'], $lang['text_left_description'], $lang['text_left_syntax'], $lang['text_left_example']);
            $this->insertTag($lang, $lang['text_center'], $lang['text_center_description'], $lang['text_center_syntax'], $lang['text_center_example']);
            $this->insertTag($lang, $lang['text_right'], $lang['text_right_description'], $lang['text_right_syntax'], $lang['text_right_example']);
            $this->insertTag($lang, $lang['text_youtube'], $lang['text_youtube_description'], $lang['text_youtube_syntax'], $lang['text_youtube_example']);
            $this->insertTag($lang, $lang['text_video'], $lang['text_video_description'], $lang['text_video_syntax'], $lang['text_video_example']);
            $this->insertTag($lang, $lang['text_audio'], $lang['text_audio_description'], $lang['text_audio_syntax'], $lang['text_audio_example']);
            $this->insertTag($lang, $lang['text_spoiler'], $lang['text_spoiler_description'], $lang['text_spoiler_syntax'], $lang['text_spoiler_example']);
            $this->insertTag($lang, $lang['text_hr'], $lang['text_hr_description'], $lang['text_hr_syntax'], $lang['text_hr_example']);

            end_frame();
            end_main_frame();
        });

        return view('tags', compact('content') + [
            'pageTitle' => $lang['head_tags'],
        ]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        $result = $this->repository->getList($request->all());
        $resource = TagResource::collection($result);

        return $this->success($resource);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate($this->getRules());
        $data = array_filter($request->all());
        $result = $this->repository->store($data);
        $resource = new TagResource($result);
        return $this->success($resource);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return array
     */
    public function show($id)
    {
        $result = Tag::query()->findOrFail($id);
        $resource = new TagResource($result);
        return $this->success($resource);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return array
     */
    public function update(Request $request, $id)
    {
        $request->validate($this->getRules($id));
        $data = $request->all();
        if (isset($data['priority'])) {
            $data['priority'] = intval($data['priority']);
        }
        $result = $this->repository->update($data, $id);
        $resource = new TagResource($result);
        return $this->success($resource);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return array
     */
    public function destroy($id)
    {
        $result = $this->repository->delete($id);
        return $this->success($result);
    }

    /**
     * Render one BB tag documentation row (mirrors the legacy insert_tag()).
     */
    private function insertTag(
        array $lang,
        string $name,
        string $description,
        string $syntax,
        string $example,
        string $remarks = ''
    ): void {
        $result = format_comment($example);
        print("<p class=\"sub\"><b>$name</b></p>\n");
        print("<table class=\"main\" width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"5\">\n");
        print("<tr valign=\"top\"><td width=\"25%\">" . $lang['text_description'] . "</td><td>$description\n");
        print("<tr valign=\"top\"><td>" . $lang['text_syntax'] . "</td><td><tt>$syntax</tt>\n");
        print("<tr valign=\"top\"><td>" . $lang['text_example'] . "</td><td><tt>$example</tt>\n");
        print("<tr valign=\"top\"><td>" . $lang['text_result'] . "</td><td>$result\n");
        if ($remarks !== '') {
            print("<tr><td>" . $lang['text_remarks'] . "</td><td>$remarks\n");
        }
        print("</table>\n");
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
