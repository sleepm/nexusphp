<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 2 P3 migration of the public search/feeds
 * endpoints: searchsuggest.php / getextinfoajax.php / opensearch.php.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes everything this test created.
 */
class SuggestOpensearchExtInfoTest extends TestCase
{
    private string $keywordPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
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

    // ------------------------------------------------------------------ searchsuggest.php

    public function testSearchSuggestReturnsEmptyListsWithoutQuery()
    {
        $this->get('/searchsuggest.php')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['', [], []]);

        $this->get('/searchsuggest.php?q=')
            ->assertOk()
            ->assertExactJson(['', [], []]);
    }

    public function testSearchSuggestGroupsKeywordsByCount()
    {
        $first = $this->keywordPrefix . 'aa';
        $second = $this->keywordPrefix . 'bb';
        $this->insertSuggest($first, 3);
        $this->insertSuggest($second, 1);

        $this->get('/searchsuggest.php?q=' . $this->keywordPrefix)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                $this->keywordPrefix,
                [$first, $second],
                ['3 times', '1 times'],
            ]);
    }

    public function testSearchSuggestCapsAtFiveAndSkipsLongKeywords()
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->insertSuggest($this->keywordPrefix . 'kw' . $i, $i);
        }
        $this->insertSuggest($this->keywordPrefix . str_repeat('x', 30), 99);

        $response = $this->get('/searchsuggest.php?q=' . $this->keywordPrefix)
            ->assertOk();

        $body = $response->json();
        $this->assertCount(5, $body[1]);
        $this->assertSame(5, count($body[2]));
        foreach ($body[1] as $suggestion) {
            $this->assertLessThanOrEqual(25, strlen($suggestion));
        }
    }

    // ------------------------------------------------------------------ getextinfoajax.php

    public function testExtInfoAjaxReturnsNoCacheXmlHeaders()
    {
        $this->get('/getextinfoajax.php?url=tt9999999&type=minor')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertHeaderContains('Cache-Control', 'no-cache')
            ->assertHeaderContains('Cache-Control', 'must-revalidate')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
    }

    public function testExtInfoAjaxMissingUrlReturnsEmptyFragment()
    {
        $this->get('/getextinfoajax.php')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    }

    public function testExtInfoAjaxUnknownModeFallsBackToMinor()
    {
        // unknown imdb id + invalid mode: empty fragment, no errors
        $this->get('/getextinfoajax.php?url=tt9999998&type=whatever')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    }

    // ------------------------------------------------------------------ opensearch.php

    public function testOpenSearchReturnsDescriptionXml()
    {
        $siteName = Setting::getSiteName();

        $this->get('/opensearch.php')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8')
            ->assertSee('<?xml version="1.0" encoding="utf-8"?>', false)
            ->assertSee('<OpenSearchDescription', false)
            ->assertSee('<ShortName>' . $siteName . ' Torrents</ShortName>', false)
            ->assertSee('torrents.php?search={searchTerms}&amp;page={startPage?}', false)
            ->assertSee('torrentrss.php?search={searchTerms}', false)
            ->assertSee('searchsuggest.php?q={searchTerms}', false)
            ->assertSee('opensearch.php" />', false)
            ->assertSee('<SyndicationRight>limited</SyndicationRight>', false)
            ->assertSee('<InputEncoding>UTF-8</InputEncoding>', false)
            ->assertSee('<OutputEncoding>UTF-8</OutputEncoding>', false);
    }
}