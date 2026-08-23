<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * End-to-end coverage of the page.php migration (PageController::web) which
 * loads a raw PHP view file (optionally from a plugin's resource folder) —
 * mirroring the legacy dynamic page loader.
 */
class PagePageTest extends TestCase
{
    private const VIEW_PATH = '/app/resources/views/test_page_view.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
    }

    protected function tearDown(): void
    {
        if (is_file(self::VIEW_PATH)) {
            @unlink(self::VIEW_PATH);
        }
        parent::tearDown();
    }

    public function testRequiresViewParameter()
    {
        $this->get('/page.php')->assertStatus(400);
    }

    public function testMissingViewFileReturns404()
    {
        $this->get('/page.php?view=does_not_exist_xyz')->assertStatus(404);
    }

    public function testUnknownPluginReturns404()
    {
        $this->get('/page.php?view=some/view&plugin=unknown_plugin')->assertStatus(404);
    }

    public function testLoadsResourceView()
    {
        file_put_contents(self::VIEW_PATH, '<?php echo "PAGE_TEST_OK";');

        $this->get('/page.php?view=test_page_view')
            ->assertOk()
            ->assertSee('PAGE_TEST_OK');
    }

    public function testLoadsNestedResourceView()
    {
        $nested = '/app/resources/views/test/nested_page_view.php';
        if (!is_dir('/app/resources/views/test')) {
            mkdir('/app/resources/views/test', 0777, true);
        }
        file_put_contents($nested, '<?php echo "NESTED_OK";');
        try {
            $this->get('/page.php?view=test.nested_page_view')
                ->assertOk()
                ->assertSee('NESTED_OK');
        } finally {
            @unlink($nested);
            @rmdir('/app/resources/views/test');
        }
    }
}