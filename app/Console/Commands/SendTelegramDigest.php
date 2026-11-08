<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Bug;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SendTelegramDigest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:daily-digest {--user= : Send the digest to one user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the WorkOS daily summary through Telegram';

    /**
     * Execute the console command.
     */
    public function handle(TelegramService $telegram): int
    {
        if (! $telegram->isEnabled()) {
            $this->warn('Telegram is not configured. Set TELEGRAM_BOT_TOKEN and a chat ID first.');

            return self::SUCCESS;
        }

        $users = $this->recipients();

        if ($users->isEmpty() && ! $telegram->hasGlobalChat()) {
            $this->info('No Telegram recipients are configured.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($users as $user) {
            $notification = $telegram->sendToUser(
                $user,
                TelegramService::EVENT_DAILY_DIGEST,
                $this->digestFor($user, $telegram),
            );

            if ($notification !== null) {
                $sent++;
            }
        }

        if ($this->option('user') === null
            && $telegram->hasGlobalChat()
            && ! $this->globalChatIsAlreadyCovered($users)) {
            $notification = $telegram->sendToGlobal(
                TelegramService::EVENT_DAILY_DIGEST,
                $this->globalDigest($telegram),
            );

            if ($notification !== null) {
                $sent++;
            }
        }

        $this->info("Sent {$sent} Telegram digest(s).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        $userId = $this->option('user');

        if ($userId !== null) {
            return User::query()->whereKey((int) $userId)->get();
        }

        return User::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_notifications_enabled', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function globalChatIsAlreadyCovered(Collection $recipients): bool
    {
        $globalChatId = (string) config('services.telegram.chat_id');

        return $recipients->contains(
            fn (User $user): bool => (string) $user->telegram_chat_id === $globalChatId
        );
    }

    private function globalDigest(TelegramService $telegram): string
    {
        $openTasks = Task::query()
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]);

        $dueToday = (clone $openTasks)->whereDate('due_at', today())->count();
        $overdue = (clone $openTasks)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        $completedToday = Task::query()
            ->where('status', TaskStatus::Done->value)
            ->whereDate('completed_at', today())
            ->count();

        $openBugs = Bug::query()->whereNotIn('status', ['closed']);

        $minutes = (int) WorkLog::query()
            ->whereDate('date', today())
            ->sum('time_spent_minutes');

        return $telegram->message('☀️ WorkOS daily digest', [
            'Scope' => 'All users',
            'Date' => today()->format('M d, Y'),
            'Open tasks' => $openTasks->count(),
            'Due today' => $dueToday,
            'Overdue' => $overdue,
            'Completed today' => $completedToday,
            'Open bugs' => $openBugs->count(),
            'Critical bugs' => (clone $openBugs)->where('priority', 'critical')->count(),
            'Tracked today' => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60),
        ]);
    }

    private function digestFor(User $user, TelegramService $telegram): string
    {
        $openTasks = Task::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]);

        $dueToday = (clone $openTasks)->whereDate('due_at', today())->count();
        $overdue = (clone $openTasks)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        $completedToday = Task::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->where('status', TaskStatus::Done->value)
            ->whereDate('completed_at', today())
            ->count();

        $openBugs = Bug::query()
            ->visibleTo($user)
            ->whereNotIn('status', ['closed']);

        $minutes = (int) WorkLog::query()
            ->where('user_id', $user->id)
            ->whereDate('date', today())
            ->sum('time_spent_minutes');

        return $telegram->message('☀️ WorkOS daily digest', [
            'For' => $user->name,
            'Date' => today()->format('M d, Y'),
            'Open tasks' => $openTasks->count(),
            'Due today' => $dueToday,
            'Overdue' => $overdue,
            'Completed today' => $completedToday,
            'Open bugs' => $openBugs->count(),
            'Critical bugs' => (clone $openBugs)->where('priority', 'critical')->count(),
            'Tracked today' => sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60),
        ]);
    }
}
