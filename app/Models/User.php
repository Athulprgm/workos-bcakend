<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'is_active',
    'telegram_chat_id',
    'telegram_notifications_enabled',
    'telegram_events',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Check if the user is a super admin.
     */
    public function isSuperAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true);
    }

    /**
     * Check if the user is an admin or super admin.
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'admin'], true) || ! $this->isTester();
    }

    /**
     * A developer has full workspace access but cannot manage team accounts.
     * Accounts created before the developer role existed keep the legacy
     * "user" value and are treated as developers.
     */
    public function isDeveloper(): bool
    {
        return in_array($this->role, [UserRole::Developer->value, UserRole::User->value], true);
    }

    /**
     * Admin accounts may add, edit, or remove team accounts and testers.
     */
    public function canManageTeam(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Deactivated accounts keep their data but cannot sign in.
     */
    public function isActive(): bool
    {
        return $this->is_active !== false;
    }

    /**
     * Human-readable label for navigation, settings, and the Team screen.
     */
    public function roleLabel(): string
    {
        return UserRole::labelFor($this->role);
    }

    /**
     * A tester is restricted to the Testing module and authorized projects.
     */
    public function isTester(): bool
    {
        return $this->role === UserRole::Tester->value;
    }

    /**
     * Whether the person can be selected as a report General Manager.
     */
    public function isGeneralManager(): bool
    {
        return in_array($this->role, ['general_manager', 'admin', 'super_admin'], true);
    }

    /**
     * Projects owned by this user.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Tasks assigned to this user.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Tags owned by this user.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Workspaces owned by this user.
     */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /**
     * Work logs recorded by this user.
     */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    /**
     * Work reports generated for this user.
     */
    public function workReports(): HasMany
    {
        return $this->hasMany(WorkReport::class);
    }

    /**
     * Reports explicitly shared with this user as General Manager.
     */
    public function sharedWorkReports(): HasMany
    {
        return $this->hasMany(WorkReport::class, 'gm_user_id');
    }

    /**
     * Tester invitations owned by this user.
     */
    public function testerInvitations(): HasMany
    {
        return $this->hasMany(TesterInvitation::class, 'owner_id');
    }

    /**
     * Invitations that grant this tester account access.
     */
    public function testerAccessInvitations(): HasMany
    {
        return $this->hasMany(TesterInvitation::class, 'tester_user_id');
    }

    /**
     * Bugs reported by this user.
     */
    public function reportedBugs(): HasMany
    {
        return $this->hasMany(Bug::class, 'created_by');
    }

    /**
     * Bugs assigned to this user as tester.
     */
    public function assignedBugs(): HasMany
    {
        return $this->hasMany(Bug::class, 'tester_id');
    }

    public function googleAccount(): HasOne
    {
        return $this->hasOne(GoogleAccount::class);
    }

    public function sheetConnections(): HasMany
    {
        return $this->hasMany(GoogleSheetConnection::class);
    }

    /**
     * Telegram messages sent on behalf of this user.
     */
    public function telegramNotifications(): HasMany
    {
        return $this->hasMany(TelegramNotification::class);
    }

    public function githubRepositoryLinks(): HasMany
    {
        return $this->hasMany(GithubRepositoryLink::class);
    }

    public function githubLinks(): HasMany
    {
        return $this->hasMany(GithubLink::class);
    }

    public function aiMemories(): HasMany
    {
        return $this->hasMany(AiMemory::class);
    }

    /**
     * Whether this tester still has at least one active invitation.
     */
    public function hasActiveTesterAccess(): bool
    {
        return $this->testerAccessInvitations()->active()->exists();
    }

    /**
     * Project IDs a caller can access.
     *
     * Owners see all their own projects (null means "no tester narrowing");
     * testers see only projects explicitly granted to their active invitations.
     *
     * @return array<int, int>|null
     */
    public function accessibleProjectIds(): ?array
    {
        if (! $this->isTester()) {
            return null;
        }

        return TesterProjectAccess::query()
            ->whereHas('invitation', fn ($query) => $query
                ->where('tester_user_id', $this->id)
                ->active())
            ->pluck('project_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ensure default multi-company workspaces exist for this user.
     */
    public function ensureDefaultWorkspaces(): void
    {
        if ($this->workspaces()->count() === 0) {
            $this->workspaces()->create([
                'name' => 'External Company',
                'slug' => 'external-company',
                'company_type' => 'external',
                'color' => '#6366F1',
                'settings' => ['role' => 'Software Engineer / Consultant', 'notify' => true],
            ]);

            $this->workspaces()->create([
                'name' => 'My Company',
                'slug' => 'my-company',
                'company_type' => 'my_company',
                'color' => '#2DD4BF',
                'settings' => ['role' => 'Founder / Lead Engineer', 'notify' => true],
            ]);

            $this->workspaces()->create([
                'name' => 'Personal Workspace',
                'slug' => 'personal-workspace',
                'company_type' => 'personal',
                'color' => '#F59E0B',
                'settings' => ['role' => 'Individual Contributor', 'notify' => false],
            ]);
        }
    }

    /**
     * The GitHub account connected to this user.
     */
    public function githubAccount(): HasOne
    {
        return $this->hasOne(GithubAccount::class);
    }

    /**
     * Every active tester invitation's project IDs mapped to the project owner.
     *
     * @return Collection<int, int>
     */
    public function testerProjectIds()
    {
        return TesterProjectAccess::query()
            ->whereHas('invitation', fn ($query) => $query
                ->where('tester_user_id', $this->id)
                ->active())
            ->pluck('project_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'telegram_notifications_enabled' => 'boolean',
            'telegram_events' => 'array',
        ];
    }
}
