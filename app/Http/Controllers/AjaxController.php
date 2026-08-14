<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\BonusRepository;
use App\Repositories\ClaimRepository;
use App\Repositories\ExamRepository;
use App\Repositories\MedalRepository;
use App\Repositories\SeedBoxRepository;
use App\Repositories\TorrentRepository;
use App\Repositories\UserPasskeyRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Nexus\Database\NexusDB;
use Nexus\PTGen\PTGen;
use ReflectionClass;

/**
 * AJAX JSON interface, replaces legacy public/ajax.php (Phase 2 P2).
 *
 * POST {action, params}; returns json via success()/fail() exactly like the
 * legacy page. Actions are whitelisted static methods, so arbitrary method
 * invocation is not possible. Most actions require a logged-in session;
 * passkey WebAuthn helpers stay public (matching the legacy check).
 */
class AjaxController extends Controller
{
    public function web(Request $request)
    {
        $action = (string) $request->post('action', '');
        $params = (array) $request->post('params', []);

        if (!in_array($action, ['getPasskeyGetArgs', 'processPasskeyGet'], true) && !Auth::guard('nexus')->check()) {
            return $this->jsonFail('Not logged in');
        }

        $currentUser = Auth::guard('nexus')->user();

        try {
            $reflection = new ReflectionClass(self::class);
            if ($reflection->hasMethod($action) && $reflection->getMethod($action)->isPublic() && $reflection->getMethod($action)->isStatic()) {
                $result = self::$action($params, $currentUser);
                return response()->json(success($result));
            }
            if ($currentUser) {
                do_log("hacking attempt made by {$currentUser->username},uid {$currentUser->id}", 'error');
            }
            throw new \RuntimeException("Invalid action: $action");
        } catch (\Throwable $exception) {
            do_log($exception->getMessage() . $exception->getTraceAsString(), "error");
            return $this->jsonFail($exception->getMessage());
        }
    }

    private function jsonFail(string $message)
    {
        return response()->json(fail($message));
    }

    public static function toggleUserMedalStatus($params, $user)
    {
        $rep = new MedalRepository();
        return $rep->toggleUserMedalStatus($params['id'], $user->id);
    }

    public static function attendanceRetroactive($params, $user)
    {
        $rep = new AttendanceRepository();
        return $rep->retroactive($user->id, $params['date']);
    }

    public static function getPtGen($params, $user)
    {
        $rep = new PTGen();
        $result = $rep->generate($params['url']);
        if ($rep->isRawPTGen($result)) {
            return $result;
        } elseif ($rep->isIyuu($result)) {
            return $result['data'];
        }
        return '';
    }

    public static function addClaim($params, $user)
    {
        $rep = new ClaimRepository();
        return $rep->store($user->id, $params['torrent_id']);
    }

    public static function removeClaim($params, $user)
    {
        $rep = new ClaimRepository();
        return $rep->delete($params['id'], $user->id);
    }

    public static function removeUserLeechWarn($params, $user)
    {
        $rep = new UserRepository();
        return $rep->removeLeechWarn($user->id, $params['uid']);
    }

    public static function getOffer($params, $user)
    {
        $offer = \App\Models\Offer::query()->findOrFail($params['id']);
        return $offer->toArray();
    }

    public static function approvalModal($params, $user)
    {
        $rep = new TorrentRepository();
        return $rep->buildApprovalModal($user->id, $params['torrent_id']);
    }

    public static function approval($params, $user)
    {
        foreach (['torrent_id', 'approval_status'] as $field) {
            if (!isset($params[$field])) {
                throw new \InvalidArgumentException("Require $field");
            }
        }
        $rep = new TorrentRepository();
        return $rep->approval($user->id, $params);
    }

    public static function addSeedBoxRecord($params, $user)
    {
        $rep = new SeedBoxRepository();
        $params['uid'] = $user->id;
        $params['type'] = \App\Models\SeedBoxRecord::TYPE_USER;
        $params['status'] = \App\Models\SeedBoxRecord::STATUS_UNAUDITED;
        return $rep->store($params);
    }

    public static function removeSeedBoxRecord($params, $user)
    {
        $rep = new SeedBoxRepository();
        return $rep->delete($params['id'], $user->id);
    }

    public static function removeHitAndRun($params, $user)
    {
        $rep = new BonusRepository();
        return $rep->consumeToCancelHitAndRun($user->id, $params['id']);
    }

    public static function consumeBenefit($params, $user)
    {
        $rep = new UserRepository();
        return $rep->consumeBenefit($user->id, $params);
    }

    public static function clearShoutBox($params, $user)
    {
        if (!user_can('sbmanage')) {
            throw new \RuntimeException('Permission denied');
        }
        NexusDB::table('shoutbox')->delete();
        return true;
    }

    public static function buyMedal($params, $user)
    {
        $rep = new BonusRepository();
        return $rep->consumeToBuyMedal($user->id, $params['medal_id']);
    }

    public static function giftMedal($params, $user)
    {
        $rep = new BonusRepository();
        return $rep->consumeToGiftMedal($user->id, $params['medal_id'], $params['uid']);
    }

    public static function saveUserMedal($params, $user)
    {
        $data = [];
        foreach ($params as $param) {
            $fieldAndId = explode('_', $param['name']);
            $field = $fieldAndId[0];
            $id = $fieldAndId[1];
            $value = $param['value'];
            $data[$id][$field] = $value;
        }
        $rep = new MedalRepository();
        return $rep->saveUserMedal($user->id, $data);
    }

    public static function claimTask($params, $user)
    {
        $rep = new ExamRepository();
        return $rep->assignToUser($user->id, $params['exam_id']);
    }

    public static function addToken($params, $user)
    {
        if (empty($params['name'])) {
            throw new \InvalidArgumentException("Name is required");
        }
        $user = User::query()->findOrFail($user->id, User::$commonFields);
        $user->createToken($params['name']);
        return true;
    }

    public static function removeToken($params, $user)
    {
        if (empty($params['id'])) {
            throw new \InvalidArgumentException("id is required");
        }
        $user = User::query()->findOrFail($user->id, User::$commonFields);
        $user->tokens()->where('id', $params['id'])->delete();
        return true;
    }

    public static function getPasskeyCreateArgs($params, $user)
    {
        $rep = new UserPasskeyRepository();
        return $rep->getCreateArgs($user->id, $user->username);
    }

    public static function processPasskeyCreate($params, $user)
    {
        $rep = new UserPasskeyRepository();
        return $rep->processCreate($user->id, $params['challengeId'], $params['clientDataJSON'], $params['attestationObject']);
    }

    public static function deletePasskey($params, $user)
    {
        $rep = new UserPasskeyRepository();
        return $rep->delete($user->id, $params['credentialId']);
    }

    public static function getPasskeyList($params, $user)
    {
        $rep = new UserPasskeyRepository();
        return $rep->getList($user->id);
    }

    public static function getPasskeyGetArgs($params, $user)
    {
        $rep = new UserPasskeyRepository();
        return $rep->getGetArgs();
    }

    public static function processPasskeyGet($params, $user)
    {
        $rep = new UserPasskeyRepository();
        return $rep->processGet($params['challengeId'], $params['id'], $params['clientDataJSON'], $params['authenticatorData'], $params['signature'], $params['userHandle']);
    }
}