<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end coverage of the task.php migration
 * (TaskController::web) against a real database.
 */
class TaskPageTest extends TestCase
{
    protected array $createdUserIds = [];
    protected array $createdExamIds = [];

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
        if ($this->createdExamIds !== []) {
            DB::table('exams')->whereIn('id', $this->createdExamIds)->delete();
        }
        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $name, int $class, array $overrides = []): User
    {
        $user = User::query()->forceCreate(array_merge([
            'username' => $name,
            'passhash' => str_repeat('a', 32),
            'secret' => 'secret',
            'auth_key' => 'authkey_' . $name,
            'email' => $name . '@example.com',
            'status' => 'confirmed',
            'enabled' => 'yes',
            'class' => $class,
            'passkey' => md5($name),
            'ip' => '127.0.0.1',
            'avatar' => '',
            'title' => '',
            'signature' => '',
            'seedbonus' => 100,
            'uploaded' => 1024 * 1024 * 1024,
            'downloaded' => 10 * 1024 * 1024 * 1024,
            'warned' => 'no',
            'showfb' => 'yes',
            'hidehb' => 'no',
            'added' => now(),
            'last_access' => now(),
            'commentpm' => 'no',
        ], $overrides));
        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function makeTask(array $overrides = []): Exam
    {
        $exam = Exam::query()->forceCreate(array_merge([
            'name' => 'Test Task',
            'description' => 'A test task description',
            'begin' => now()->subDay(),
            'end' => now()->addDays(7),
            'duration' => 0,
            'status' => Exam::STATUS_ENABLED,
            'type' => Exam::TYPE_TASK,
            'is_discovered' => Exam::DISCOVERED_NO,
            'filters' => [],
            'indexes' => [
                ['index' => Exam::INDEX_UPLOADED, 'checked' => true, 'require_value' => 10],
            ],
            'success_reward_bonus' => 1000,
            'fail_deduct_bonus' => 500,
            'max_user_count' => 10,
            'recurring' => null,
            'priority' => 0,
            'background_color' => 'blue',
        ], $overrides));
        $this->createdExamIds[] = $exam->id;

        return $exam;
    }

    private function cookieFor(User $user): string
    {
        $tokenJson = json_encode(['user_id' => $user->id, 'expires' => time() + 3600]);
        $signature = hash_hmac('sha256', $tokenJson, $user->auth_key);
        return base64_encode($tokenJson . '.' . $signature);
    }

    private function getTaskPage(User $user)
    {
        app('auth')->forgetGuards();

        return $this->withCookie('c_secure_pass', $this->cookieFor($user))
            ->withCookie('c_lang_folder', 'en')
            ->get('/task.php');
    }

    public function testRequiresLogin()
    {
        $this->get('/task.php')->assertRedirect();
    }

    public function testShowsEnabledTasks()
    {
        $user = $this->makeUser('taskuser', User::CLASS_USER);
        $enabled = $this->makeTask(['name' => 'Enabled Task']);
        $disabled = $this->makeTask([
            'name' => 'Disabled Task',
            'status' => Exam::STATUS_DISABLED,
        ]);

        $this->getTaskPage($user)
            ->assertOk()
            ->assertSee('Enabled Task')
            ->assertDontSee('Disabled Task');
    }

    public function testShowsOnlyTaskType()
    {
        $user = $this->makeUser('tasktypetest', User::CLASS_USER);
        $task = $this->makeTask(['name' => 'ATask', 'type' => Exam::TYPE_TASK]);
        $exam = $this->makeTask(['name' => 'AnExam', 'type' => Exam::TYPE_EXAM]);

        $this->getTaskPage($user)
            ->assertOk()
            ->assertSee('ATask')
            ->assertDontSee('AnExam');
    }

    public function testShowsClaimButtonForUnclaimedTask()
    {
        $user = $this->makeUser('claimtest', User::CLASS_USER);
        $this->makeTask();

        $claimBtn = sprintf('value="%s"', nexus_trans('exam.action_claim_task'));
        $this->getTaskPage($user)
            ->assertOk()
            ->assertSee($claimBtn, false);
    }

    public function testShowsAlreadyClaimedForClaimedTask()
    {
        $user = $this->makeUser('claimedtest', User::CLASS_USER);
        $task = $this->makeTask();

        DB::table('exam_users')->insert([
            'uid' => $user->id,
            'exam_id' => $task->id,
            'status' => ExamUser::STATUS_NORMAL,
            'begin' => now(),
            'end' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $alreadyBtn = sprintf('value="%s"', nexus_trans('exam.claimed_already'));
        $claimBtn = sprintf('value="%s"', nexus_trans('exam.action_claim_task'));
        $this->getTaskPage($user)
            ->assertOk()
            ->assertSee($alreadyBtn, false)
            ->assertDontSee($claimBtn, false);
    }

    public function testShowsClaimedUserCount()
    {
        $user = $this->makeUser('counttest', User::CLASS_USER);
        $task = $this->makeTask(['max_user_count' => 5]);

        DB::table('exam_users')->insert([
            'uid' => $user->id,
            'exam_id' => $task->id,
            'status' => ExamUser::STATUS_NORMAL,
            'begin' => now(),
            'end' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getTaskPage($user)
            ->assertOk()
            ->assertSee('1/5');
    }

    public function testShowsInfiniteWhenMaxUserCountIsZero()
    {
        $user = $this->makeUser('infinitetest', User::CLASS_USER);
        $this->makeTask(['max_user_count' => 0]);

        $this->getTaskPage($user)
            ->assertOk()
            ->assertSee(nexus_trans('label.infinite'));
    }
}