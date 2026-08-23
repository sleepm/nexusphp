<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MySQL server status page — replaces legacy public/mysql_stats.php.
 *
 * GET /mysql_stats.php requires the SYSOP class. The page renders the output
 * of `SHOW STATUS` as an ops dashboard: uptime / startup time, server traffic
 * (received / sent / total, plus per-hour rates), connection counters, query
 * statistics broken down per statement type, and the remaining status
 * variables split across three columns.
 */
class MysqlStatsController extends Controller
{
    private array $byteUnits = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];

    public function web(Request $request)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = Auth::guard('nexus')->user();
        if (! $currentUser) {
            abort(401);
        }
        $curUser = $currentUser->toArray();
        if (($curUser['parked'] ?? '') == 'yes') {
            abort(403, 'Your account is parked.');
        }
        if (get_user_class() < User::CLASS_SYSOP) {
            abort(403, 'Permission denied.');
        }

        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $serverStatus = [];
        foreach (DB::select('SHOW STATUS') as $row) {
            $serverStatus[$row->Variable_name] = $row->Value;
        }

        $content = $this->capture(function () use ($serverStatus) {
            print('<h1 align="center">' . "\n"
                . '    Mysql Server Status' . "\n"
                . '</h1>' . "\n");

            $uptime = (int) ($serverStatus['Uptime'] ?? 0);
            $startup = time() - $uptime;

            print("\t<table id=\"torrenttable\" border=\"1\"><tr><td>\n");
            print('This MySQL server has been running for ' . $this->timespanFormat($uptime)
                . '. It started up on ' . $this->localisedDate($startup) . "\n");
            print("\t</td></tr></table>\n");

            $queryStats = [];
            foreach ($serverStatus as $name => $value) {
                if (substr($name, 0, 4) == 'Com_') {
                    $queryStats[str_replace('_', ' ', substr($name, 4))] = $value;
                    unset($serverStatus[$name]);
                }
            }

            $bytesReceived = (int) ($serverStatus['Bytes_received'] ?? 0);
            $bytesSent = (int) ($serverStatus['Bytes_sent'] ?? 0);
            $abortedConnects = (int) ($serverStatus['Aborted_connects'] ?? 0);
            $abortedClients = (int) ($serverStatus['Aborted_clients'] ?? 0);
            $connections = (int) ($serverStatus['Connections'] ?? 0);
            $questions = (int) ($serverStatus['Questions'] ?? 0);

            print('<ul>' . "\n");
            print('<li>' . "\n");
            print('    <b>Server traffic:</b> These tables show the network traffic statistics of this MySQL server since its startup' . "\n");
            print('    <br />' . "\n");
            print('    <table border="0">' . "\n");
            print('        <tr>' . "\n");
            print('            <td valign="top">' . "\n");
            print('                <table id="torrenttable" border="0">' . "\n");
            print('                    <tr>' . "\n");
            print('                        <th colspan="2" bgcolor="lightgrey">&nbsp;Traffic&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;&nbsp;Per Hour&nbsp;</th>' . "\n");
            print('                    </tr>' . "\n");
            $this->trafficRow('Received', $bytesReceived, $uptime);
            $this->trafficRow('Sent', $bytesSent, $uptime);
            $this->trafficRow('Total', $bytesReceived + $bytesSent, $uptime, true);
            print('                </table>' . "\n");
            print('            </td>' . "\n");
            print('            <td valign="top">' . "\n");
            print('                <table id="torrenttable" border="0">' . "\n");
            print('                    <tr>' . "\n");
            print('                        <th colspan="2" bgcolor="lightgrey">&nbsp;Connections&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per Hour&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;%&nbsp;</th>' . "\n");
            print('                    </tr>' . "\n");
            $this->connectionRow('Failed Attempts', $abortedConnects, $uptime, $connections);
            $this->connectionRow('Aborted Clients', $abortedClients, $uptime, $connections);
            $this->connectionRow('Total', $connections, $uptime, $connections, true);
            print('                </table>' . "\n");
            print('            </td>' . "\n");
            print('        </tr>' . "\n");
            print('    </table>' . "\n");
            print('</li>' . "\n");
            print('<br />' . "\n");
            print('<li>' . "\n");
            print('    <b>Query Statistics:</b> Since it\'s start up, ' . number_format($questions, 0, '.', ',') . ' queries have been sent to the server.' . "\n");
            print('    <table border="0">' . "\n");
            print('        <tr>' . "\n");
            print('            <td colspan="2">' . "\n");
            print('                <br />' . "\n");
            print('                <table id="torrenttable" border="0" align="right">' . "\n");
            print('                    <tr>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;Total&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per&nbsp;Hour&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per&nbsp;Minute&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per&nbsp;Second&nbsp;</th>' . "\n");
            print('                    </tr>' . "\n");
            print('                    <tr>' . "\n");
            print('                        <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format($questions, 0, '.', ',') . '&nbsp;</td>' . "\n");
            print('                        <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format(($questions * 3600 / $uptime), 2, '.', ',') . '&nbsp;</td>' . "\n");
            print('                        <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format(($questions * 60 / $uptime), 2, '.', ',') . '&nbsp;</td>' . "\n");
            print('                        <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format(($questions / $uptime), 2, '.', ',') . '&nbsp;</td>' . "\n");
            print('                    </tr>' . "\n");
            print('                </table>' . "\n");
            print('            </td>' . "\n");
            print('        </tr>' . "\n");
            print('        <tr>' . "\n");
            print('            <td valign="top">' . "\n");
            print('                <table id="torrenttable" border="0">' . "\n");
            print('                    <tr>' . "\n");
            print('                        <th colspan="2" bgcolor="lightgrey">&nbsp;Query&nbsp;Type&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per&nbsp;Hour&nbsp;</th>' . "\n");
            print('                        <th bgcolor="lightgrey">&nbsp;%&nbsp;</th>' . "\n");
            print('                    </tr>' . "\n");

            $countRows = 0;
            $denominator = ($questions - $connections) > 0 ? ($questions - $connections) : 1;
            foreach ($queryStats as $name => $value) {
                print('                        <tr>' . "\n");
                print('                            <td bgcolor="#EFF3FF">&nbsp;' . htmlspecialchars($name) . '&nbsp;</td>' . "\n");
                print('                            <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format($value, 0, '.', ',') . '&nbsp;</td>' . "\n");
                print('                            <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format(($value * 3600 / $uptime), 2, '.', ',') . '&nbsp;</td>' . "\n");
                print('                            <td bgcolor="#EFF3FF" align="right">&nbsp;' . number_format(($value * 100 / $denominator), 2, '.', ',') . '&nbsp;%&nbsp;</td>' . "\n");
                print('                        </tr>' . "\n");
                if (++$countRows == (int) ceil(count($queryStats) / 2)) {
                    print('                </table>' . "\n");
                    print('            </td>' . "\n");
                    print('            <td valign="top">' . "\n");
                    print('                <table id="torrenttable" border="0">' . "\n");
                    print('                    <tr>' . "\n");
                    print('                        <th colspan="2" bgcolor="lightgrey">&nbsp;Query&nbsp;Type&nbsp;</th>' . "\n");
                    print('                        <th bgcolor="lightgrey">&nbsp;&oslash;&nbsp;Per&nbsp;Hour&nbsp;</th>' . "\n");
                    print('                        <th bgcolor="lightgrey">&nbsp;%&nbsp;</th>' . "\n");
                    print('                    </tr>' . "\n");
                }
            }
            print('                </table>' . "\n");
            print('            </td>' . "\n");
            print('        </tr>' . "\n");
            print('    </table>' . "\n");
            print('</li>' . "\n");

            unset($serverStatus['Aborted_clients']);
            unset($serverStatus['Aborted_connects']);
            unset($serverStatus['Bytes_received']);
            unset($serverStatus['Bytes_sent']);
            unset($serverStatus['Connections']);
            unset($serverStatus['Questions']);
            unset($serverStatus['Uptime']);

            if (! empty($serverStatus)) {
                print('<br />' . "\n");
                print('<li>' . "\n");
                print('    <b>More status variables</b><br />' . "\n");
                print('    <table border="0">' . "\n");
                print('        <tr>' . "\n");
                print('            <td valign="top">' . "\n");
                print('                <table id="torrenttable" border="0">' . "\n");
                print('                    <tr>' . "\n");
                print('                        <th bgcolor="lightgrey">&nbsp;Variable&nbsp;</th>' . "\n");
                print('                        <th bgcolor="lightgrey">&nbsp;Value&nbsp;</th>' . "\n");
                print('                    </tr>' . "\n");
                $countRows = 0;
                $totalVars = count($serverStatus);
                foreach ($serverStatus as $name => $value) {
                    print('                        <tr>' . "\n");
                    print('                            <td bgcolor="#EFF3FF">&nbsp;' . htmlspecialchars(str_replace('_', ' ', $name)) . '&nbsp;</td>' . "\n");
                    print('                            <td bgcolor="#EFF3FF" align="right">&nbsp;' . htmlspecialchars($value) . '&nbsp;</td>' . "\n");
                    print('                        </tr>' . "\n");
                    if (++$countRows == (int) ceil($totalVars / 3) || $countRows == (int) ceil($totalVars * 2 / 3)) {
                        print('                </table>' . "\n");
                        print('            </td>' . "\n");
                        print('            <td valign="top">' . "\n");
                        print('                <table id="torrenttable" border="0">' . "\n");
                        print('                    <tr>' . "\n");
                        print('                        <th bgcolor="lightgrey">&nbsp;Variable&nbsp;</th>' . "\n");
                        print('                        <th bgcolor="lightgrey">&nbsp;Value&nbsp;</th>' . "\n");
                        print('                    </tr>' . "\n");
                    }
                }
                print('                </table>' . "\n");
                print('            </td>' . "\n");
                print('        </tr>' . "\n");
                print('    </table>' . "\n");
                print('</li>' . "\n");
            }
            print('</ul>' . "\n");
        });

        return view('mysql_stats', compact('content') + [
            'pageTitle' => 'MySQL Server Stats',
        ]);
    }

    private function trafficRow(string $label, int $bytes, int $uptime, bool $total = false): void
    {
        $bg = $total ? 'lightgrey' : '#EFF3FF';
        print('                    <tr>' . "\n");
        print('                        <td bgcolor="' . $bg . '">&nbsp;' . $label . '&nbsp;</td>' . "\n");
        print('                        <td bgcolor="' . $bg . '" align="right">&nbsp;' . join(' ', $this->formatByteDown($bytes)) . '&nbsp;</td>' . "\n");
        print('                        <td bgcolor="' . $bg . '" align="right">&nbsp;' . join(' ', $this->formatByteDown($bytes * 3600 / $uptime)) . '&nbsp;</td>' . "\n");
        print('                    </tr>' . "\n");
    }

    private function connectionRow(string $label, int $value, int $uptime, int $connections, bool $total = false): void
    {
        $bg = $total ? 'lightgrey' : '#EFF3FF';
        print('                    <tr>' . "\n");
        print('                        <td bgcolor="' . $bg . '">&nbsp;' . $label . '&nbsp;</td>' . "\n");
        print('                        <td bgcolor="' . $bg . '" align="right">&nbsp;' . number_format($value, 0, '.', ',') . '&nbsp;</td>' . "\n");
        print('                        <td bgcolor="' . $bg . '" align="right">&nbsp;' . number_format(($value * 3600 / $uptime), 2, '.', ',') . '&nbsp;</td>' . "\n");
        print('                        <td bgcolor="' . $bg . '" align="right">&nbsp;' . (($connections > 0) ? number_format(($value * 100 / $connections), 2, '.', ',') . '&nbsp;%' : '---') . '&nbsp;</td>' . "\n");
        print('                    </tr>' . "\n");
    }

    private function formatByteDown(float|int $value, int $limes = 6, int $comma = 0): array
    {
        $dh = pow(10, $comma);
        $li = pow(10, $limes);
        $returnValue = $value;
        $unit = $this->byteUnits[0];

        for ($d = 6, $ex = 15; $d >= 1; $d--, $ex -= 3) {
            if (isset($this->byteUnits[$d]) && $value >= $li * pow(10, $ex)) {
                $value = round($value / (pow(1024, $d) / $dh)) / $dh;
                $unit = $this->byteUnits[$d];
                break;
            }
        }

        $returnValue = ($unit != $this->byteUnits[0])
            ? number_format($value, $comma, '.', ',')
            : number_format($value, 0, '.', ',');

        return [$returnValue, $unit];
    }

    private function timespanFormat(float|int $seconds): string
    {
        $days = floor($seconds / 86400);
        if ($days > 0) {
            $seconds -= $days * 86400;
        }
        $hours = floor($seconds / 3600);
        if ($days > 0 || $hours > 0) {
            $seconds -= $hours * 3600;
        }
        $minutes = floor($seconds / 60);
        if ($days > 0 || $hours > 0 || $minutes > 0) {
            $seconds -= $minutes * 60;
        }

        return (string) $days . ' Days ' . (string) $hours . ' Hours '
            . (string) $minutes . ' Minutes ' . (string) $seconds . ' Seconds ';
    }

    private function localisedDate(int $timestamp): string
    {
        return date('F d, Y \a\t h:i A', $timestamp);
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
