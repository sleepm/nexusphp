<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    const TG_WEBHOOK_PREFIX = "tg-webhook";
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        self::TG_WEBHOOK_PREFIX . "/*",
        "web/token/*",
        // legacy invite.php posts to takeconfirm.php without a CSRF token
        "takeconfirm.php",
        // legacy upload.php form posts to takeupload.php without a CSRF token
        "takeupload.php",
        // legacy AJAX (public/js/common.js ajax.post) sends no CSRF token
        "thanks.php",
        "ajax.php",
    ];
}
