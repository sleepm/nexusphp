<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Shared GD rendering for the legacy userbar image endpoints (mybar.php /
 * cc98bar.php). Mirrors the drawing logic of the original scripts: a background
 * PNG from public/pic/userbar/ gets the username / upload / download strings
 * drawn over it, honouring per-segment colour / font-size / position overrides.
 */
trait RendersUserBar
{
    /**
     * Resolve the bar owner. Returns null when the user does not exist, hides
     * their stats with "strong" privacy, or has not reached the userbar class.
     */
    protected function fetchUserBarUser(int $userId): ?object
    {
        $row = DB::table('users')
            ->select('username', 'uploaded', 'downloaded', 'class', 'privacy')
            ->where('id', $userId)
            ->first();
        if (! $row) {
            return null;
        }
        if ($row->privacy === 'strong') {
            return null;
        }
        if ((int) $row->class < (int) get_setting('authority.userbar', 2)) {
            return null;
        }
        return $row;
    }

    /**
     * Render the userbar PNG bytes for the given background and segments.
     * Each segment config has the keys 'draw' (bool), 'red', 'green', 'blue'
     * (0-255), 'size' (1-5), 'x', 'y' and is keyed by 'name'/'upload'/'download'.
     */
    protected function renderUserBar(int $userId, int $bgpic, array $segments): ?string
    {
        $user = $this->fetchUserBarUser($userId);
        if (! $user) {
            return null;
        }
        $imgFile = public_path('pic/userbar/' . $bgpic . '.png');
        if (! is_file($imgFile)) {
            return null;
        }
        $img = @imagecreatefrompng($imgFile);
        if (! $img) {
            return null;
        }
        imagealphablending($img, false);

        $textMap = [
            'name' => $user->username,
            'upload' => mksize($user->uploaded),
            'download' => mksize($user->downloaded),
        ];
        foreach ($segments as $key => $cfg) {
            if (empty($cfg['draw'])) {
                continue;
            }
            $colour = imagecolorallocate($img, (int) $cfg['red'], (int) $cfg['green'], (int) $cfg['blue']);
            imagestring($img, (int) $cfg['size'], (int) $cfg['x'], (int) $cfg['y'], $textMap[$key] ?? '', $colour);
        }

        imagesavealpha($img, true);
        ob_start();
        imagepng($img);
        $content = (string) ob_get_clean();
        imagedestroy($img);

        return $content;
    }

    /**
     * Clamp a legacy query/path parameter to [min, max], falling back to the
     * default when absent or out of range.
     */
    protected function boundParam($value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $int = (int) $value;
        return ($int >= $min && $int <= $max) ? $int : $default;
    }
}
