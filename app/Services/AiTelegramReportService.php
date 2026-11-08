<?php

namespace App\Services;

use App\Enums\ReportVisibility;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-powered natural language processor for daily work reports via Telegram.
 *
 * Supports English, broken English, Malayalam, and Manglish inputs.
 * Normalizes spelling, grammar, project matching, date detection (Asia/Kolkata),
 * professional rewriting, status classification, database persistence, and natural language edits.
 */
class AiTelegramReportService
{
    private const TIMEZONE = 'Asia/Kolkata';

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Process an incoming natural language Telegram message.
     *
     * @return array{text: string, options: array<string, mixed>}
     */
    public function handleMessage(string $text, User $user, string $chatId): array
    {
        if (! $this->gemini->isConfigured()) {
            return [
                'text' => "⚠️ <b>AI Processing Unavailable</b>\n\nGemini API key is not configured in WorkOS. Please configure <code>GEMINI_API_KEY</code> in settings.",
                'options' => [],
            ];
        }

        // Check if user has an active pending project disambiguation
        $cacheKey = "pending_telegram_report_{$user->id}";
        $pendingDraft = Cache::get($cacheKey);

        $nowKolkata = Carbon::now(self::TIMEZONE);
        $todayStr = $nowKolkata->toDateString();
        $yesterdayStr = $nowKolkata->copy()->subDay()->toDateString();

        // 1. Gather User's WorkOS projects
        $projects = $this->getUserProjects($user);
        $projectList = $projects->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
        ])->values()->all();

        // 2. Gather Recent Reports for editing context
        $recentReports = WorkReport::query()
            ->where('user_id', $user->id)
            ->where('period_end', '>=', $nowKolkata->copy()->subDays(7)->toDateString())
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'title', 'period_end', 'company_name', 'projects_worked', 'completed_work', 'work_in_progress', 'pending_tasks']);

        $recentContext = $recentReports->map(fn (WorkReport $r) => [
            'id' => $r->id,
            'title' => $r->title,
            'date' => $r->period_end?->toDateString(),
            'status' => ! empty($r->completed_work) ? 'Completed' : (! empty($r->work_in_progress) ? 'In Progress' : 'Pending'),
            'summary' => $r->title,
            'completed_items' => collect($r->completed_work ?? [])->pluck('title')->all(),
            'wip_items' => collect($r->work_in_progress ?? [])->pluck('title')->all(),
        ])->values()->all();

        // 3. Build system instruction
        $systemInstruction = <<<INSTRUCTION
You are the Senior WorkOS AI Integration Architect and Technical Report Generator.
Your task is to transform casual daily work update messages sent via Telegram into structured, professional daily work reports inside WorkOS.

LOCAL TEMPORAL CONTEXT:
- Timezone: Asia/Kolkata
- Current Local Date (Today): {$todayStr} ({$nowKolkata->format('l')})
- Yesterday's Date: {$yesterdayStr}

LANGUAGE & LOCALIZATION MASTERY:
- Accept inputs in: English, Broken English, Malayalam (മലയാളം), and Manglish (Malayalam written in Latin script).
- Manglish/Malayalam dictionary and hints:
  * "innu" / "inunnu" = today ({$todayStr})
  * "innale" = yesterday ({$yesterdayStr})
  * "kazhinju" / "theernnu" / "cheythu" = completed / done
  * "cheythondirikkunnu" / "nadakkunnu" / "work nadakkunnu" = in progress
  * "baki und" / "ini cheyyanam" = pending / backlog
  * "prashnam und" / "blocker" / "stuck aayi" = issues / blockers
  * "paripadi" / "pani" / "task" = work item / deliverable
- Output rule: The generated title, summary, and detailed description MUST ALWAYS be in professional, polished, technical English, regardless of whether the user wrote in Malayalam, Manglish, or broken English.

FACTUALITY & INTEGRITY:
- NEVER invent activities, tasks, or metrics not supported by the original message.
- NEVER claim that testing or QA was performed unless the user's message specifically stated or implied testing was done!
- Preserve exact technical meaning and scope.
- Fix all spelling errors, grammatical mistakes, and punctuation.

PROJECT MATCHING:
- Map work to the user's existing projects in WorkOS:
{$this->formatProjectsForPrompt($projectList)}
- Use exact matching and fuzzy semantic matching.
- Never create duplicate projects.
- If multiple projects are mentioned in the message, output separate report items in the "reports" array.
- If the project is ambiguous or could equally match multiple projects, set "is_ambiguous": true and populate "candidate_project_ids".

STATUS DETECTION:
- Allowed statuses: "Completed", "In Progress", "Pending", "On Hold".
- Do not automatically mark everything as Completed. If the message implies ongoing work or fixes without final completion, use "In Progress".

NATURAL LANGUAGE EDITING:
- Check if the user is asking to update, edit, modify, or append to a recent report:
  (e.g., "Change status to Completed", "Add mobile responsiveness testing", "Change date to yesterday", "Update description to mention...").
- Recent reports context:
{$this->formatRecentReportsForPrompt($recentContext)}
- If it is an edit request, set "is_edit_request": true, identify "target_report_id", and specify the modifications.

SCHEMA (Return pure JSON object):
{
  "is_edit_request": false,
  "target_report_id": null,
  "edit_instructions": null,
  "reports": [
    {
      "date": "YYYY-MM-DD",
      "matched_project_id": 123,
      "project_name": "Project Name",
      "is_ambiguous": false,
      "candidate_project_ids": [],
      "title": "Professional Work Title",
      "summary": "Concise 1-2 sentence professional summary of work performed.",
      "description": "Comprehensive, professional technical description of the exact work done without exaggerations.",
      "status": "Completed" | "In Progress" | "Pending" | "On Hold",
      "time_spent_minutes": 0,
      "next_steps": "Optional next steps if mentioned or implied"
    }
  ]
}
INSTRUCTION;

        $prompt = "User Telegram Message:\n\"{$text}\"";

        $aiResponse = $this->gemini->generateJson($prompt, $systemInstruction, [
            'temperature' => 0.1,
        ]);

        if (! is_array($aiResponse)) {
            Log::warning('AI Telegram report generation returned null or invalid JSON', [
                'user_id' => $user->id,
                'input' => $text,
            ]);

            return [
                'text' => "⚠️ <b>Could Not Process Report</b>\n\nI was unable to analyze your message into a structured report. Please try describing your work in a few words (e.g. <i>'Today fixed design bugs in deposit commission'</i>).",
                'options' => [],
            ];
        }

        // Handle Edit Request
        if (($aiResponse['is_edit_request'] ?? false) === true) {
            return $this->handleEditRequest($aiResponse, $text, $user);
        }

        $reportsData = $aiResponse['reports'] ?? [];

        if (empty($reportsData) || ! is_array($reportsData)) {
            return [
                'text' => "⚠️ <b>No Work Details Detected</b>\n\nPlease mention what work you performed and for which project.",
                'options' => [],
            ];
        }

        // Handle ambiguous project detection
        $first = $reportsData[0];
        if (! empty($first['is_ambiguous']) && ! empty($first['candidate_project_ids'])) {
            return $this->handleAmbiguousProject($first, $user);
        }

        // Process all detected reports (single or multiple projects)
        $responses = [];
        $lastReport = null;

        foreach ($reportsData as $reportItem) {
            $created = $this->persistReport($reportItem, $user, $text);
            $lastReport = $created['report'];
            $responses[] = $created['formatted_message'];
        }

        $finalText = implode("\n\n────────────────────\n\n", $responses);

        $options = [];
        if ($lastReport instanceof WorkReport) {
            $options = $this->buildReportKeyboard($lastReport);
        }

        return [
            'text' => $finalText,
            'options' => $options,
        ];
    }

    /**
     * Handle user selecting a project from the disambiguation inline keyboard.
     *
     * @return array{text: string, options: array<string, mixed>}
     */
    public function handleProjectSelection(int $projectId, User $user): array
    {
        $cacheKey = "pending_telegram_report_{$user->id}";
        $draft = Cache::get($cacheKey);

        if (! is_array($draft)) {
            return [
                'text' => '⚠️ The project selection session has expired. Please send your work update again.',
                'options' => [],
            ];
        }

        Cache::forget($cacheKey);

        $project = Project::query()->where('user_id', $user->id)->find($projectId)
            ?? Project::query()->find($projectId);

        if (! $project instanceof Project) {
            return [
                'text' => '⚠️ Selected project was not found.',
                'options' => [],
            ];
        }

        $draft['matched_project_id'] = $project->id;
        $draft['project_name'] = $project->name;
        $draft['is_ambiguous'] = false;

        $created = $this->persistReport($draft, $user, $draft['raw_input'] ?? '');

        return [
            'text' => $created['formatted_message'],
            'options' => $this->buildReportKeyboard($created['report']),
        ];
    }

    /**
     * Handle natural language editing of an existing report.
     *
     * @param  array<string, mixed>  $aiResponse
     * @return array{text: string, options: array<string, mixed>}
     */
    private function handleEditRequest(array $aiResponse, string $userText, User $user): array
    {
        $targetId = $aiResponse['target_report_id'] ?? null;

        $report = null;
        if ($targetId) {
            $report = WorkReport::query()->where('user_id', $user->id)->find($targetId);
        }

        if (! $report instanceof WorkReport) {
            $report = WorkReport::query()
                ->where('user_id', $user->id)
                ->orderByDesc('period_end')
                ->orderByDesc('id')
                ->first();
        }

        if (! $report instanceof WorkReport) {
            return [
                'text' => "⚠️ <b>No Existing Report Found to Edit</b>\n\nI couldn't locate a recent daily report to modify. You can submit a new daily report by describing your work.",
                'options' => [],
            ];
        }

        // Apply modifications based on AI report payload
        $updatedData = $aiResponse['reports'][0] ?? null;

        $status = $updatedData['status'] ?? null;
        $title = $updatedData['title'] ?? null;
        $summary = $updatedData['summary'] ?? null;
        $description = $updatedData['description'] ?? null;
        $newDate = $updatedData['date'] ?? null;

        $projectName = $report->projects_worked[0]['name']
            ?? ($report->completed_work[0]['project'] ?? null)
            ?? ($report->work_in_progress[0]['project'] ?? null)
            ?? (preg_match('/Daily Work Report:\s*(.+?)\s*\(/', $report->title, $m) ? trim($m[1]) : null)
            ?? $workLog?->project?->name
            ?? 'Deposit Commission Management';

        // Update WorkLog if exists
        $workLog = WorkLog::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $report->period_end->toDateString())
            ->latest('id')
            ->first();

        if ($workLog instanceof WorkLog) {
            if ($description) {
                $workLog->work_description = $description;
            }
            if ($newDate) {
                $workLog->date = $newDate;
            }
            $workLog->save();
        }

        // Update WorkReport fields
        if ($newDate) {
            $report->period_start = $newDate;
            $report->period_end = $newDate;
        }

        if ($title) {
            $report->title = "Daily Work Report: {$projectName} (".Carbon::parse($report->period_end)->format('M d, Y').')';
        }

        // Adjust deliverable status arrays
        $currentDeliverable = [
            'log_id' => $workLog?->id,
            'title' => $title ?: ($report->completed_work[0]['title'] ?? $report->work_in_progress[0]['title'] ?? $report->pending_tasks[0]['title'] ?? 'Daily Deliverable'),
            'project' => $projectName,
            'date' => Carbon::parse($report->period_end)->toDateString(),
            'description' => $description ?: ($workLog?->work_description ?? ''),
            'time_spent' => null,
        ];

        if ($status === 'Completed') {
            $report->completed_work = [$currentDeliverable];
            $report->work_in_progress = [];
            $report->pending_tasks = [];
        } elseif ($status === 'In Progress') {
            $report->completed_work = [];
            $report->work_in_progress = [$currentDeliverable];
            $report->pending_tasks = [];
        } elseif ($status === 'Pending' || $status === 'On Hold') {
            $report->completed_work = [];
            $report->work_in_progress = [];
            $report->pending_tasks = [$currentDeliverable];
        }

        $formattedDate = Carbon::parse($report->period_end)->format('d F Y');
        $rawMarkdown = $this->buildReportMarkdown(
            $projectName,
            $report->company_name,
            $formattedDate,
            $title ?: $currentDeliverable['title'],
            $summary ?: 'Updated via Telegram',
            $description ?: $currentDeliverable['description'],
            $status ?: 'In Progress'
        );

        $report->raw_content = $rawMarkdown;
        $report->save();

        $activeStatus = $status ?: (! empty($report->completed_work) ? 'Completed' : (! empty($report->work_in_progress) ? 'In Progress' : 'Pending'));

        $reply = "✅ <b>Daily Report Updated</b>\n\n"
            ."📅 <b>Date:</b> {$formattedDate}\n\n"
            ."📁 <b>Project:</b> {$projectName}\n\n"
            ."📝 <b>Work Title:</b>\n".e($title ?: $currentDeliverable['title'])."\n\n"
            ."📄 <b>Summary:</b>\n".e($summary ?: 'Updated work details based on your latest message.')."\n\n"
            ."📋 <b>Detailed Description:</b>\n".e($description ?: $currentDeliverable['description'])."\n\n"
            ."📊 <b>Status:</b> {$activeStatus}";

        return [
            'text' => $reply,
            'options' => $this->buildReportKeyboard($report),
        ];
    }

    /**
     * Handle project ambiguity by prompting user with inline buttons.
     *
     * @param  array<string, mixed>  $reportItem
     * @return array{text: string, options: array<string, mixed>}
     */
    private function handleAmbiguousProject(array $reportItem, User $user): array
    {
        // Cache draft for 30 minutes
        $cacheKey = "pending_telegram_report_{$user->id}";
        Cache::put($cacheKey, $reportItem, now()->addMinutes(30));

        $candidateIds = $reportItem['candidate_project_ids'] ?? [];
        $candidates = Project::query()
            ->whereIn('id', $candidateIds)
            ->where('user_id', $user->id)
            ->get();

        if ($candidates->isEmpty()) {
            $candidates = $this->getUserProjects($user)->take(4);
        }

        $keyboard = [];
        foreach ($candidates as $candidate) {
            $keyboard[] = [
                ['text' => '📁 '.$candidate->name, 'callback_data' => 'select_project:'.$candidate->id],
            ];
        }

        $title = $reportItem['title'] ?? 'Daily Work';
        $text = "🤔 <b>Which project was this work for?</b>\n\n"
            ."I detected your work on: <i>\"".e($title)."\"</i>\n\n"
            ."Please choose the matching WorkOS project below:";

        return [
            'text' => $text,
            'options' => [
                'reply_markup' => [
                    'inline_keyboard' => $keyboard,
                ],
            ],
        ];
    }

    /**
     * Persist report and associated work log to database.
     *
     * @param  array<string, mixed>  $data
     * @return array{report: WorkReport, formatted_message: string}
     */
    private function persistReport(array $data, User $user, string $rawMessage): array
    {
        return DB::transaction(function () use ($data, $user, $rawMessage): array {
            $nowKolkata = Carbon::now(self::TIMEZONE);
            $workDate = ! empty($data['date']) ? Carbon::parse($data['date']) : $nowKolkata;
            $workDateStr = $workDate->toDateString();

            // Resolve Project
            $projectId = $data['matched_project_id'] ?? null;
            $project = null;

            if ($projectId) {
                $project = Project::query()->where('user_id', $user->id)->find($projectId)
                    ?? Project::query()->find($projectId);
            }

            if (! $project instanceof Project && ! empty($data['project_name'])) {
                $name = trim($data['project_name']);
                $project = Project::query()
                    ->where('user_id', $user->id)
                    ->where('name', 'LIKE', "%{$name}%")
                    ->first();
            }

            // Fallback to user's first project or create general reference
            if (! $project instanceof Project) {
                $project = $this->getUserProjects($user)->first();
            }

            $workspace = $project?->workspace
                ?? $user->workspaces()->first()
                ?? \App\Models\Workspace::first();

            $workspaceId = $workspace?->id ?? 1;
            $companyName = $workspace?->name ?? 'WorkOS';
            $projectName = $project?->name ?? ($data['project_name'] ?? 'General');

            $title = $data['title'] ?? 'Daily Work Report';
            $summary = $data['summary'] ?? '';
            $description = $data['description'] ?? '';
            $status = in_array($data['status'] ?? '', ['Completed', 'In Progress', 'Pending', 'On Hold'], true)
                ? $data['status']
                : 'In Progress';

            $timeSpentMinutes = (int) ($data['time_spent_minutes'] ?? 0);
            $nextSteps = $data['next_steps'] ?? null;

            // 1. Save or Update WorkLog
            $workLog = WorkLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'project_id' => $project?->id,
                'date' => $workDateStr,
                'work_description' => $description ?: $title,
                'time_spent_minutes' => $timeSpentMinutes,
                'next_steps' => $nextSteps,
            ]);

            // 2. Prepare deliverables item
            $deliverableItem = [
                'log_id' => $workLog->id,
                'title' => $title,
                'project' => $projectName,
                'date' => $workDateStr,
                'time_spent' => $timeSpentMinutes > 0 ? "{$timeSpentMinutes}m" : null,
                'description' => $description,
            ];

            // 3. Find or Create WorkReport for this day
            $existingReport = WorkReport::query()
                ->where('user_id', $user->id)
                ->where('type', 'daily')
                ->whereDate('period_end', $workDateStr)
                ->first();

            $formattedDate = $workDate->format('d F Y');
            $reportTitle = "Daily Work Report: {$projectName} ({$workDate->format('M d, Y')})";
            $rawMarkdown = $this->buildReportMarkdown($projectName, $companyName, $formattedDate, $title, $summary, $description, $status);

            if ($existingReport instanceof WorkReport) {
                // Update / Merge into existing daily report
                $completed = $existingReport->completed_work ?? [];
                $wip = $existingReport->work_in_progress ?? [];
                $pending = $existingReport->pending_tasks ?? [];

                // Filter out any previous deliverable with same log_id
                $completed = array_values(array_filter($completed, fn ($i) => ($i['log_id'] ?? null) !== $workLog->id));
                $wip = array_values(array_filter($wip, fn ($i) => ($i['log_id'] ?? null) !== $workLog->id));
                $pending = array_values(array_filter($pending, fn ($i) => ($i['log_id'] ?? null) !== $workLog->id));

                if ($status === 'Completed') {
                    $completed[] = $deliverableItem;
                } elseif ($status === 'In Progress') {
                    $wip[] = $deliverableItem;
                } else {
                    $pending[] = $deliverableItem;
                }

                $projectsWorked = $existingReport->projects_worked ?? [];
                if (empty($projectsWorked) && $project) {
                    $projectsWorked = [[
                        'id' => $project->id,
                        'name' => $projectName,
                        'status' => $project->status?->value ?? 'active',
                        'tasks_count' => $project->tasks()->count() ?? 1,
                    ]];
                }

                $existingReport->projects_worked = $projectsWorked;
                $existingReport->completed_work = $completed;
                $existingReport->work_in_progress = $wip;
                $existingReport->pending_tasks = $pending;
                $existingReport->raw_content = $rawMarkdown;
                $existingReport->time_spent_minutes = ($existingReport->time_spent_minutes ?? 0) + $timeSpentMinutes;
                $existingReport->save();

                $report = $existingReport;
            } else {
                $report = WorkReport::create([
                    'user_id' => $user->id,
                    'workspace_id' => $workspaceId,
                    'type' => 'daily',
                    'period_start' => $workDateStr,
                    'period_end' => $workDateStr,
                    'title' => $reportTitle,
                    'company_name' => $companyName,
                    'visibility' => ReportVisibility::Public,
                    'projects_worked' => [
                        [
                            'id' => $project?->id,
                            'name' => $projectName,
                            'status' => $project?->status?->value ?? 'active',
                            'tasks_count' => $project?->tasks()->count() ?? 1,
                        ],
                    ],
                    'completed_work' => $status === 'Completed' ? [$deliverableItem] : [],
                    'work_in_progress' => $status === 'In Progress' ? [$deliverableItem] : [],
                    'pending_tasks' => in_array($status, ['Pending', 'On Hold'], true) ? [$deliverableItem] : [],
                    'issues_blockers' => [],
                    'time_spent_minutes' => $timeSpentMinutes,
                    'next_steps' => $nextSteps ? [['project' => $projectName, 'step' => $nextSteps]] : [],
                    'raw_content' => $rawMarkdown,
                ]);
            }

            // Exact Telegram format requested in Step 9
            $formattedMessage = "✅ <b>Daily Report Generated</b>\n\n"
                ."📅 <b>Date:</b> {$formattedDate}\n\n"
                ."📁 <b>Project:</b> {$projectName}\n\n"
                ."📝 <b>Work Title:</b>\n".e($title)."\n\n"
                ."📄 <b>Summary:</b>\n".e($summary)."\n\n"
                ."📋 <b>Detailed Description:</b>\n".e($description)."\n\n"
                ."📊 <b>Status:</b> {$status}";

            return [
                'report' => $report,
                'formatted_message' => $formattedMessage,
            ];
        });
    }

    /**
     * Build standard WorkOS Markdown representation including table format.
     */
    private function buildReportMarkdown(
        string $projectName,
        string $companyName,
        string $formattedDate,
        string $title,
        string $summary,
        string $description,
        string $status
    ): string {
        $md = "# Daily Work Report: {$projectName} ({$formattedDate})\n\n";
        $md .= "**Company / Workspace:** {$companyName}\n";
        $md .= "**Reporting Period:** {$formattedDate}\n";
        $md .= "**Status:** {$status}\n\n";

        $md .= "## 📊 Work Deliverables Summary Table\n\n";
        $md .= "| # | Task / Deliverable | Project | Status | Time Spent | Notes |\n";
        $md .= "|---|--------------------|---------|--------|------------|-------|\n";
        $cleanTitle = str_replace('|', '/', $title);
        $cleanProject = str_replace('|', '/', $projectName);
        $md .= "| 1 | {$cleanTitle} | {$cleanProject} | {$status} | — | {$formattedDate} |\n\n";

        $md .= "## 1. Projects Worked On\n";
        $md .= "- **{$projectName}**\n\n";

        $md .= "## 2. Work Summary\n";
        $md .= "{$summary}\n\n";

        $md .= "## 3. Detailed Description\n";
        $md .= "{$description}\n";

        return $md;
    }

    /**
     * Inline keyboard with [View Report] and [Edit Report] buttons.
     *
     * @return array<string, mixed>
     */
    private function buildReportKeyboard(WorkReport $report): array
    {
        $frontendUrl = rtrim((string) config('services.github.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $viewUrl = "{$frontendUrl}/reports/public/{$report->share_token}";

        return [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '👁️ View Report', 'url' => $viewUrl],
                        ['text' => '✏️ Edit Report', 'callback_data' => 'edit_report:'.$report->id],
                    ],
                ],
            ],
        ];
    }

    /**
     * Fetch user's active projects.
     *
     * @return Collection<int, Project>
     */
    private function getUserProjects(User $user): Collection
    {
        return Project::query()
            ->where('user_id', $user->id)
            ->orWhereIn('workspace_id', $user->workspaces()->pluck('id'))
            ->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     */
    private function formatProjectsForPrompt(array $projects): string
    {
        if (empty($projects)) {
            return 'No registered projects found. Use generic project names if implied.';
        }

        $lines = [];
        foreach ($projects as $p) {
            $desc = ! empty($p['description']) ? ' - '.$p['description'] : '';
            $lines[] = "- [ID: {$p['id']}] \"{$p['name']}\"{$desc}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reports
     */
    private function formatRecentReportsForPrompt(array $reports): string
    {
        if (empty($reports)) {
            return 'No recent reports in past 7 days.';
        }

        $lines = [];
        foreach ($reports as $r) {
            $lines[] = "- Report ID #{$r['id']} (Date: {$r['date']}, Status: {$r['status']}): \"{$r['title']}\"";
        }

        return implode("\n", $lines);
    }
}
