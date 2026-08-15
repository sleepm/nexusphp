<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Nexus\Database\NexusDB;

/**
 * OpenSearch description XML, replaces legacy public/opensearch.php (Phase 2 P3).
 *
 * Serves the OpenSearch 1.1 description for the torrent search box;
 * referenced by the site <head> via <link rel="search">. Output is built from
 * DB-backed settings and cached for a day.
 */
class OpenSearchController extends Controller
{
    public function index(Request $request)
    {
        $xml = NexusDB::remember('nexus_opensearch_description', 86400, function () {
            $url = get_protocol_prefix() . Setting::getBaseUrl();
            $siteName = Setting::getSiteName();
            $slogan = get_setting('main.SLOGAN', '');
            $siteEmail = get_setting('main.SITEEMAIL', '');
            $year = substr((string) get_setting('tweak.datefounded', '2007'), 0, 4);
            $yearFounded = $year ? (int) $year : 2007;
            $attribution = 'Copyright (c) ' . $siteName . ' '
                . (date('Y') != $yearFounded ? $yearFounded . '-' : '') . date('Y')
                . ', all rights reserved';

            $faviconDataUrl = '';
            $faviconPath = public_path('favicon.ico');
            if (is_file($faviconPath)) {
                $faviconDataUrl = 'data:image/x-icon;base64,' . base64_encode(file_get_contents($faviconPath));
            }

            $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
            $xml .= '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/"';
            $xml .= " xmlns:moz=\"http://www.mozilla.org/2006/browser/search/\">\n";
            $xml .= '<ShortName>' . $siteName . " Torrents</ShortName>\n";
            $xml .= '<Description>Search Torrents at ' . $siteName . ' - ' . htmlspecialchars($slogan) . ".</Description>\n";
            $xml .= "<Url type=\"text/html\" rel=\"results\" pageOffset=\"0\" template=\"{$url}/torrents.php?search={searchTerms}&amp;page={startPage?}\" />\n";
            $xml .= "<Url type=\"application/rss+xml\" rel=\"results\" indexOffset=\"0\" template=\"{$url}/torrentrss.php?search={searchTerms}&amp;rows={count?}&amp;startindex={startIndex?}\" />\n";
            $xml .= "<Url type=\"application/opensearchdescription+xml\" rel=\"self\" template=\"{$url}/opensearch.php\" />\n";
            $xml .= "<Url type=\"application/x-suggestions+json\" rel=\"suggestions\" template=\"{$url}/searchsuggest.php?q={searchTerms}\" />\n";
            $xml .= '<Contact>' . $siteEmail . "</Contact>\n";
            $xml .= '<Tags>Torrents ' . constant('PROJECTNAME') . "</Tags>\n";
            $xml .= '<LongName>' . $siteName . " Torrents Search</LongName>\n";
            if ($faviconDataUrl !== '') {
                $xml .= '<Image height="32" width="32" type="image/x-icon">' . $faviconDataUrl . "</Image>\n";
            }
            $xml .= '<Image height="32" width="32" type="image/x-icon">' . $url . "/favicon.ico</Image>\n";
            $xml .= '<moz:SearchForm>' . $url . "</moz:SearchForm>\n";
            $xml .= "<Query role=\"example\" searchTerms=\"batman\" />\n";
            $xml .= '<Developer>' . $siteName . " Staff</Developer>\n";
            $xml .= '<Attribution>' . $attribution . "</Attribution>\n";
            $xml .= "<SyndicationRight>limited</SyndicationRight>\n";
            $xml .= "<Language>*</Language>\n";
            $xml .= "<InputEncoding>UTF-8</InputEncoding>\n";
            $xml .= "<OutputEncoding>UTF-8</OutputEncoding>\n";
            $xml .= '</OpenSearchDescription>';

            return $xml;
        });

        return response($xml)->header('Content-Type', 'text/xml; charset=utf-8');
    }
}