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
        // legacy edit.php form posts to takeedit.php without a CSRF token
        "takeedit.php",
        // legacy mybonus.php exchange forms post to mybonus.php without a CSRF token
        "mybonus.php",
        // legacy messages.php moveordel/forward/delete forms post without a CSRF token
        "messages.php",
        // legacy sendmessage.php / messages.php compose+forward forms post to takemessage.php without a CSRF token
        "takemessage.php",
        // legacy forums.php post/movetopic/deletetopic/deletepost/setsticky/setlocked/hltopic forms have no CSRF token
        "forums.php",
        // legacy moforums.php addforum/editforum forms have no CSRF token
        "moforums.php",
        // legacy AJAX (public/js/common.js ajax.post) sends no CSRF token
        "thanks.php",
        "ajax.php",
    ];
}
