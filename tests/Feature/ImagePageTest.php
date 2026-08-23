<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the image.php migration (ImageController::web)
 * which renders the image-captcha PNG for the regimage flow.
 *
 * NOTE: data is written committed (no DatabaseTransactions) because the legacy
 * NexusDB layer uses its own PDO connection and would not see uncommitted rows;
 * tearDown() removes the regimage rows this test created.
 */
class ImagePageTest extends TestCase
{
    private array $createdHashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        $this->disableCookieEncryption();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        if ($this->createdHashes !== []) {
            DB::table('regimages')->whereIn('imagehash', $this->createdHashes)->delete();
        }
        parent::tearDown();
    }

    public function testRejectsInvalidAction()
    {
        $this->get('/image.php?action=foo&imagehash=abc')->assertStatus(404)
            ->assertSee('Invalid captcha action');
    }

    public function testRejectsMissingAction()
    {
        $this->get('/image.php?imagehash=abc')->assertStatus(404)
            ->assertSee('Invalid captcha action');
    }

    public function testUnknownImageHashReturns404()
    {
        $this->get('/image.php?action=regimage&imagehash=' . md5('nonexistent'))
            ->assertStatus(404);
    }

    public function testRendersCaptchaPng()
    {
        $imagehash = md5(uniqid('captcha', true));
        DB::table('regimages')->insert([
            'imagehash' => $imagehash,
            'dateline' => time(),
            'imagestring' => 'test123',
        ]);
        $this->createdHashes[] = $imagehash;

        $response = $this->get('/image.php?action=regimage&imagehash=' . $imagehash);
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $binary = $response->getContent();
        $this->assertStringStartsWith("\x89PNG", $binary);
    }
}