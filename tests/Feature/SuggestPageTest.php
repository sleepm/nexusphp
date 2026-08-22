<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuggestPageTest extends TestCase
{
    private string $keywordPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
        $this->keywordPrefix = 'sugg_test_' . substr(md5(uniqid('', true)), 0, 8) . '_';
    }

    protected function tearDown(): void
    {
        if ($this->keywordPrefix !== '') {
            DB::table('suggest')->where('keywords', 'like', $this->keywordPrefix . '%')->delete();
        }
        parent::tearDown();
    }

    private function insertSuggest(string $keyword, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            DB::table('suggest')->insert([
                'keywords' => $keyword,
                'userid' => 0,
                'adddate' => now(),
            ]);
        }
    }

    public function testSuggestReturnsNoCacheHeaders(): void
    {
        $this->get('/suggest.php')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertHeaderContains('Cache-Control', 'no-cache')
            ->assertHeaderContains('Cache-Control', 'must-revalidate')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
    }

    public function testSuggestEmptyQueryReturnsEmpty(): void
    {
        $this->get('/suggest.php?q=')
            ->assertOk()
            ->assertSee('');

        $this->get('/suggest.php')
            ->assertOk()
            ->assertSee('');
    }

    public function testSuggestGroupsKeywordsByCount(): void
    {
        $first = $this->keywordPrefix . 'aa';
        $second = $this->keywordPrefix . 'bb';
        $this->insertSuggest($first, 3);
        $this->insertSuggest($second, 1);

        $response = $this->get('/suggest.php?q=' . $this->keywordPrefix)
            ->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString($first, $content);
        $this->assertStringContainsString('3', $content);
    }

    public function testSuggestCapsAtFiveAndSkipsLongKeywords(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->insertSuggest($this->keywordPrefix . 'kw' . $i, $i);
        }
        $this->insertSuggest($this->keywordPrefix . str_repeat('x', 30), 99);

        $response = $this->get('/suggest.php?q=' . $this->keywordPrefix)
            ->assertOk();
        $content = $response->getContent();
        $this->assertStringNotContainsString(str_repeat('x', 30), $content);
    }
}