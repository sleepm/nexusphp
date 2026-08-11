<?php

namespace App\Repositories;

use App\Models\LoginAttempt;
use App\Models\Setting;

class LoginAttemptRepository extends BaseRepository
{
    public function getTotalAttempts(string $ip = ''): int
    {
        $ip = $ip ?: getip();
        return (int) LoginAttempt::query()->where('ip', $ip)->sum('attempts');
    }

    public function getMaxAttempts(): int
    {
        return (int) Setting::get('security.maxloginattempts', 10);
    }

    public function getRemainingMarkup(): string
    {
        $remaining = $this->getMaxAttempts() - $this->getTotalAttempts();
        $color = $remaining <= 2 ? 'red' : 'green';
        return sprintf('<font color="%s" size="2">[%s]</font>', $color, $remaining);
    }

    /**
     * Throw an exception (banned) when the ip has reached the maximum attempts.
     */
    public function checkAndThrow(string $type = 'Login'): void
    {
        $maxAttempts = $this->getMaxAttempts();
        if ($this->getTotalAttempts() >= $maxAttempts) {
            LoginAttempt::query()->where('ip', getip())->update(['banned' => 'yes']);
            $langFunctions = get_legacy_lang_file('functions');
            $message = $type . $langFunctions['std_locked'] . $maxAttempts . $langFunctions['std_attempts_reached'];
            throw new \App\Exceptions\NexusException($message);
        }
    }

    public function recordFailure(string $type = 'login', bool $recover = false): void
    {
        $ip = getip();
        $row = LoginAttempt::query()->where('ip', $ip)->first();
        if (!$row) {
            LoginAttempt::query()->create([
                'ip' => $ip,
                'added' => now(),
                'attempts' => 1,
                'type' => $recover ? 'recover' : 'login',
            ]);
            return;
        }
        $row->increment('attempts');
        if ($recover) {
            $row->update(['type' => 'recover']);
        }
    }
}
