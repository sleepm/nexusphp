<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class Advertisement extends NexusModel
{
    protected $table = 'advertisements';

    const TYPE_BBCODES = 'bbcodes';
    const TYPE_XHTML = 'xhtml';
    const TYPE_TEXT = 'text';
    const TYPE_IMAGE = 'image';
    const TYPE_FLASH = 'flash';

    const POSITION_HEADER = 'header';
    const POSITION_FOOTER = 'footer';
    const POSITION_BELOWNAV = 'belownav';
    const POSITION_BELOWSEARCHBOX = 'belowsearchbox';
    const POSITION_TORRENTDETAIL = 'torrentdetail';
    const POSITION_COMMENT = 'comment';
    const POSITION_INTEROVERFORUMS = 'interoverforums';
    const POSITION_FORUMPOST = 'forumpost';
    const POSITION_POPUP = 'popup';

    public static array $types = [
        self::TYPE_BBCODES => 'BB codes',
        self::TYPE_XHTML => 'XHTML',
        self::TYPE_TEXT => 'Text',
        self::TYPE_IMAGE => 'Image',
        self::TYPE_FLASH => 'Flash',
    ];

    public static array $positions = [
        self::POSITION_HEADER => 'Header',
        self::POSITION_FOOTER => 'Footer',
        self::POSITION_BELOWNAV => 'Below Navigation',
        self::POSITION_BELOWSEARCHBOX => 'Below SearchBox',
        self::POSITION_TORRENTDETAIL => 'Torrent Detail',
        self::POSITION_COMMENT => 'Comment Page',
        self::POSITION_INTEROVERFORUMS => 'Inter Overforums',
        self::POSITION_FORUMPOST => 'Forum Post Page',
        self::POSITION_POPUP => 'Popup',
    ];

    protected $fillable = [
        'enabled', 'type', 'position', 'displayorder', 'name', 'parameters', 'code', 'starttime', 'endtime',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'displayorder' => 'integer',
        'starttime' => 'datetime:Y-m-d H:i:s',
        'endtime' => 'datetime:Y-m-d H:i:s',
    ];

    public function clicks()
    {
        return $this->hasMany(AdClick::class, 'adid');
    }

    public static function listTypes(): array
    {
        return self::$types;
    }

    public static function listPositions(): array
    {
        return self::$positions;
    }

    /**
     * Legacy rows stored a flat per-type parameters array with serialize(),
     * newer rows are stored as JSON keyed by type (['image' => ['url' => ...]]).
     * Normalize to the type-keyed shape on read, store as JSON going forward.
     */
    public function parameters(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (empty($value)) {
                    return [];
                }
                $data = @unserialize($value);
                if ($data === false) {
                    $data = json_decode($value, true);
                }
                if (!is_array($data)) {
                    return [];
                }
                return $this->normalizeParameters($data);
            },
            set: fn ($value) => json_encode(is_array($value) ? $value : [])
        );
    }

    private function normalizeParameters(array $data): array
    {
        // Already keyed by type (['image' => [...], ...]).
        if (count(array_intersect_key($data, self::$types)) > 0) {
            return $data;
        }
        // Legacy flat array for the current type, e.g. ['url' => ..., 'link' => ...].
        if ($this->type && in_array($this->type, array_keys(self::$types), true)) {
            return [$this->type => $data];
        }
        return $data;
    }

    public static function buildCode(string $type, array $parameters, int $adId): string
    {
        $params = $parameters[$type] ?? $parameters;
        if (!is_array($params)) {
            $params = [];
        }
        switch ($type) {
            case self::TYPE_BBCODES:
                $GLOBALS['lang_functions'] ??= get_legacy_lang_file('functions');
                return format_comment($params['code'] ?? '', true, false, true, true, 700, true, true, -1, 0, $adId);
            case self::TYPE_XHTML:
                return $params['code'] ?? '';
            case self::TYPE_TEXT:
                $content = htmlspecialchars($params['content'] ?? '');
                $size = !empty($params['size']) ? htmlspecialchars($params['size']) : '30pt';
                $content = '<span style="font-size: ' . $size . '">' . $content . '</span>';
                $link = rawurlencode(htmlspecialchars($params['link'] ?? ''));
                return '<a href="adredir.php?id=' . $adId . '&amp;url=' . $link . '" target="_blank">' . $content . '</a>';
            case self::TYPE_IMAGE:
                $imgadd = '';
                if (!empty($params['width'])) {
                    $imgadd .= ' width="' . $params['width'] . '"';
                }
                if (!empty($params['height'])) {
                    $imgadd .= ' height="' . $params['height'] . '"';
                }
                if (!empty($params['title'])) {
                    $imgadd .= ' title="' . htmlspecialchars($params['title']) . '"';
                }
                $link = rawurlencode(htmlspecialchars($params['link'] ?? ''));
                $url = htmlspecialchars($params['url'] ?? '');
                return '<a href="adredir.php?id=' . $adId . '&amp;url=' . $link . '" target="_blank"><img border="0" src="' . $url . '"' . $imgadd . ' alt="ad" /></a>';
            case self::TYPE_FLASH:
                $url = htmlspecialchars($params['url'] ?? '');
                $width = (int) ($params['width'] ?? 0);
                $height = (int) ($params['height'] ?? 0);
                return '<object width="' . $width . '" height="' . $height . '"><param name="movie" value="' . $url . '" /><embed src="' . $url . '" width="' . $width . '" height="' . $height . '" type="application/x-shockwave-flash"></embed></object>';
            default:
                return '';
        }
    }

    public function regenerateCode(): void
    {
        $this->code = self::buildCode($this->type, $this->parameters, (int) $this->id);
    }

    protected static function booted()
    {
        static::saved(function () {
            \Nexus\Database\NexusDB::cache_del('current_ad_array');
        });
        static::deleted(function () {
            \Nexus\Database\NexusDB::cache_del('current_ad_array');
        });
    }
}
