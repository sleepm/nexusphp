<?php

namespace App\Http\Controllers;

use App\Http\Resources\PollResource;
use App\Models\Poll;
use App\Models\PollAnswer;
use App\Models\Setting;
use App\Repositories\PollRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PollController extends Controller
{
    private $repository;

    public function __construct(PollRepository $repository)
    {
        $this->repository = $repository;
    }

    private function getRules(): array
    {
        return [
            'family_id' => 'required|numeric',
            'name' => 'required|string',
            'peer_id' => 'required|string',
            'agent' => 'required|string',
            'comment' => 'required|string',

        ];
    }
    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        $result = $this->repository->getList($request->all());
        $resource = PollResource::collection($result);
        return $this->success($resource);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate($this->getRules());
        $result = $this->repository->store($request->all());
        $resource = new PollResource($result);
        return $this->success($resource);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return array
     */
    public function show($id)
    {
        $result = Poll::query()->findOrFail($id);
        $resource = new PollResource($result);
        return $this->success($resource);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return array
     */
    public function update(Request $request, $id)
    {
        $request->validate($this->getRules());
        $result = $this->repository->update($request->all(), $id);
        $resource = new PollResource($result);
        return $this->success($resource);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return array
     */
    public function destroy($id)
    {
        $result = $this->repository->delete($id);
        return $this->success($result);
    }

    /**
     * @return array
     */
    public function latest()
    {
        $user = Auth::user();
        $poll = Poll::query()->orderBy('id', 'desc')->first();
        $selection = null;
        $answerStats = [];
        if ($poll) {
            $baseAnswerQuery = $poll->answers()->where('selection', '<=', Poll::MAX_OPTION_INDEX);
            $poll->answers_count = (clone $baseAnswerQuery)->count();
            $answer = $poll->answers()->where('userid', $user->id)->first();
            $options = [];
            for ($i = 0; $i <= Poll::MAX_OPTION_INDEX; $i++) {
                $field = "option{$i}";
                $value = $poll->{$field};
                if ($value !== '') {
                    $options[$i] = $value;
                }
            }
            if ($answer) {
                $selection = $answer->selection;
            } else {
                $options["255"] = "弃权(我想偷看结果！)";
            }
            $poll->options = $options;

            $answerStats = (clone $baseAnswerQuery)
                ->selectRaw("selection, count(*) as count")->groupBy("selection")
                ->get()->pluck('count', 'selection')->toArray();
            foreach ($answerStats as $index => &$value) {
                $value = number_format(($value / $poll->answers_count) * 100, 1) . '%';
            }
            $resource = new PollResource($poll);
        } else {
            $resource = new JsonResource(null);
        }

        $resource->additional([
            'selection' => $selection,
            'answer_stats' => $answerStats,
            'site_info' => site_info(),
        ]);
        return $this->success($resource);
    }

    public function vote(Request $request)
    {
        $request->validate([
            'poll_id' => 'required',
            'selection' => 'required|integer|min:0|max:255',
        ]);
        $pollId = $request->poll_id;
        $selection = $request->selection;
        $user = Auth::user();
        $poll = Poll::query()->findOrFail($pollId);
        $data = [
            'userid' => $user->id,
            'selection' => $selection,
        ];
        $answer = $poll->answers()->create($data);
        return $this->success($answer->toArray());
    }

    /**
     * Create/edit poll form + submission. Mirrors legacy public/makepoll.php.
     *
     * GET /makepoll.php (?action=edit&pollid=N&returnto=main) renders the poll
     * form (with a fresh-poll warning when the latest poll is less than 3 days
     * old); POST /makepoll.php creates or updates a poll, flushes the homepage
     * poll caches and redirects back.
     */
    public function webMakePoll(Request $request)
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
        if (! user_can('pollmanage', false, $currentUser->id)) {
            abort(403, 'Access denied.');
        }

        $lang = get_legacy_lang_file('makepoll');
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        if ($request->isMethod('POST')) {
            $pollId = (int) $request->input('pollid', 0);
            $question = trim((string) $request->input('question', ''));
            $options = [];
            for ($i = 0; $i <= Poll::MAX_OPTION_INDEX; ++$i) {
                $options['option' . $i] = trim((string) $request->input('option' . $i, ''));
            }
            $returnto = trim((string) $request->input('returnto', ''));

            if ($question === '' || $options['option0'] === '' || $options['option1'] === '') {
                return back()->withInput()->with('error', $lang['std_missing_form_data'] ?? 'Please fill in the required fields.');
            }

            $data = ['question' => $question] + $options;
            if ($pollId > 0) {
                $poll = Poll::query()->where('id', $pollId)->first();
                if (! $poll) {
                    abort(404, $lang['std_no_poll_id'] ?? 'No poll with that ID.');
                }
                $poll->update($data);
            } else {
                $data['added'] = now();
                $poll = Poll::query()->create($data);
                $pollId = (int) $poll->id;
            }

            Cache::forget(IndexController::CACHE_POLL_CONTENT);
            Cache::forget(IndexController::CACHE_POLL_RESULT);

            $baseUrl = get_protocol_prefix() . Setting::getBaseUrl();
            if ($returnto == 'main') {
                return redirect($baseUrl);
            }
            if ($pollId > 0) {
                return redirect($baseUrl . '/log.php?action=poll#' . $pollId);
            }
            return redirect($baseUrl);
        }

        $pollId = (int) $request->query('pollid', 0);
        $poll = null;
        if ($request->query('action') == 'edit') {
            if ($pollId <= 0) {
                abort(400);
            }
            $poll = Poll::query()->where('id', $pollId)->first();
            if (! $poll) {
                abort(404, $lang['std_no_poll_id'] ?? 'No poll with that ID.');
            }
        }

        $lastPollWarning = null;
        if (! $poll) {
            $lastPoll = Poll::query()->orderByDesc('added')->first(['question', 'added']);
            if ($lastPoll) {
                $days = (int) floor($lastPoll->added->diffInHours(now()) / 24);
                $hours = (int) floor($lastPoll->added->diffInHours(now()));
                if ($days < 3) {
                    $age = $days >= 1
                        ? $days . $lang['text_day'] . add_s($days)
                        : $hours . $lang['text_hour'] . add_s($hours);
                    $lastPollWarning = ['question' => $lastPoll->question, 'age' => $age];
                }
            }
        }

        return view('makepoll', [
            'lang' => $lang,
            'poll' => $poll,
            'lastPollWarning' => $lastPollWarning,
            'returnto' => (string) ($request->query('returnto') ?: $request->headers->get('referer', '')),
            'pageTitle' => $poll ? $lang['head_edit_poll'] : ($lang['head_new_poll'] ?? ''),
        ]);
    }

    /**
     * Poll overview / detail. Mirrors legacy public/polloverview.php.
     *
     * GET /polloverview.php renders the polls list; GET /polloverview.php?id=N
     * shows the poll detail (single overview row, options and the paginated
     * per-user votes).
     */
    public function webOverview(Request $request)
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
        if (! user_can('pollmanage', false, $currentUser->id)) {
            abort(403, 'Access denied.');
        }

        $lang = get_legacy_lang_file('polloverview');
        $GLOBALS['CURUSER'] = $curUser;
        $GLOBALS['lang_functions'] = get_legacy_lang_file('functions');
        $GLOBALS['CURLANGDIR'] = get_langfolder_cookie();
        $GLOBALS['BASEURL'] = Setting::getBaseUrl();
        $GLOBALS['SITENAME'] = Setting::getSiteName();

        $pollId = (int) $request->query('id', 0);

        if ($pollId > 0) {
            $poll = Poll::query()->where('id', $pollId)->first();
            if (! $poll) {
                abort(404, $lang['text_no_poll_id'] ?? 'Sorry, no poll with that ID.');
            }

            $options = [];
            for ($i = 0; $i <= Poll::MAX_OPTION_INDEX; ++$i) {
                $value = $poll->{'option' . $i};
                if ($value !== '' && $value !== null) {
                    $options[$i] = $value;
                }
            }

            $votes = PollAnswer::query()
                ->select('pollanswers.*')
                ->leftJoin('users', 'pollanswers.userid', '=', 'users.id')
                ->where('pollanswers.pollid', $pollId)
                ->where('pollanswers.selection', '<', 20)
                ->orderBy('users.username')
                ->paginate(100);

            return view('polloverview', [
                'lang' => $lang,
                'poll' => $poll,
                'options' => $options,
                'votes' => $votes,
                'pageTitle' => $lang['head_poll_overview'] ?? '',
            ]);
        }

        $pollList = Poll::query()->orderByDesc('id')->get(['id', 'added', 'question']);
        if ($pollList->isEmpty()) {
            abort(404, $lang['text_no_users_voted'] ?? 'Sorry...no users have voted yet!');
        }

        return view('polloverview', [
            'lang' => $lang,
            'poll' => null,
            'pollList' => $pollList,
            'pageTitle' => $lang['head_poll_overview'] ?? '',
        ]);
    }

}
