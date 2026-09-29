<?php

namespace Tests\Concerns;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ProjectStatusPeriod;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatus;
use App\Models\TaskStatusPeriod;
use App\Models\Team;
use App\Models\TeamLeaveApplication;
use App\Models\TeamLeaveApplicationDay;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A team portal to test against, built the short way.
 *
 * Every date in these tests is anchored to a known MONDAY so a weekend
 * exclusion is a fact about the calendar rather than about the day the suite
 * happened to run.
 */
trait BuildsTeamPortal
{
    /** A Monday, far enough out that nothing else in the suite collides. */
    protected function monday(): Carbon
    {
        return Carbon::parse('2026-10-05')->startOfDay();
    }

    protected function freezeOnMonday(): Carbon
    {
        Carbon::setTestNow($this->monday());

        return $this->monday();
    }

    protected function roleUser(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /** @param  array<int, User>  $members */
    protected function makeTeam(string $name, ?User $lead = null, array $members = []): Team
    {
        $lead ??= User::factory()->create();
        $team = Team::create(['name' => $name, 'team_lead_id' => $lead->id, 'is_active' => true]);
        $team->members()->sync(collect($members ?: [$lead])->pluck('id')->all());

        return $team;
    }

    protected function taskStatus(string $behaviour): TaskStatus
    {
        return TaskStatus::where('behaviour', $behaviour)->active()->firstOrFail();
    }

    protected function projectStatus(string $behaviour): ProjectStatus
    {
        return ProjectStatus::where('behaviour', $behaviour)->active()->firstOrFail();
    }

    protected function makeProject(Team $team, ?Client $client = null, ?string $behaviour = 'running'): Project
    {
        $client ??= Client::create(['name' => 'Zephyrine Quartermain', 'company' => 'Bartleby Ironworks PLC', 'is_active' => true]);

        $project = Project::create([
            'name' => 'Website Redesign',
            'code' => 'PRJ-'.$team->id,
            'client_id' => $client->id,
            'team_id' => $team->id,
            'project_status_id' => $this->projectStatus($behaviour)->id,
            'contract_value' => '987654.32',
            'currency' => 'PKR',
        ]);

        ProjectStatusPeriod::create([
            'project_id' => $project->id,
            'project_status_id' => $project->project_status_id,
            'started_on' => ProjectStatusPeriod::dayKey($this->monday()->copy()->subMonth()),
        ]);

        return $project;
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeTask(Team $team, array $overrides = []): Task
    {
        $task = Task::create(array_merge([
            'team_id' => $team->id,
            'task_status_id' => $this->taskStatus('active')->id,
            'title' => 'Rebuild the checkout',
            'days_allowed' => 5,
        ], $overrides));

        TaskStatusPeriod::create([
            'task_id' => $task->id,
            'task_status_id' => $task->task_status_id,
            'started_on' => TaskStatusPeriod::dayKey($this->monday()),
        ]);

        return $task;
    }

    /** A stint written straight past the controller, for arithmetic tests. */
    protected function makeStint(Task $task, User $user, int $daysAllowed, mixed $from, mixed $to = null, ?string $outcome = null): TaskAssignment
    {
        return TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'days_allowed' => $daysAllowed,
            'started_on' => TaskAssignment::dayKey($from),
            'ended_on' => $to ? TaskAssignment::dayKey($to) : null,
            'outcome' => $outcome,
        ]);
    }

    /** Park a task on a blocked status for a stated span. */
    protected function blockTask(Task $task, mixed $from, mixed $to, string $reason = 'Waiting on the client'): TaskStatusPeriod
    {
        return TaskStatusPeriod::create([
            'task_id' => $task->id,
            'task_status_id' => $this->taskStatus('blocked')->id,
            'started_on' => TaskStatusPeriod::dayKey($from),
            'ended_on' => TaskStatusPeriod::dayKey($to),
            'reason' => $reason,
        ]);
    }

    /** An approved leave application covering a span of days. */
    protected function approveLeaveFor(User $user, mixed $from, mixed $to): TeamLeaveApplication
    {
        $leave = TeamLeaveApplication::create([
            'user_id' => $user->id,
            'from_date' => TeamLeaveApplication::dayKey($from),
            'to_date' => TeamLeaveApplication::dayKey($to),
            'reason' => 'Family matter',
            'status' => 'approved',
        ]);

        $leave->openDecisions();
        $leave->decisions()->update(['status' => TeamLeaveApplicationDay::APPROVED]);

        return $leave;
    }

    /** Put a project on hold for a stated span. */
    protected function pauseProject(Project $project, mixed $from, mixed $to): ProjectStatusPeriod
    {
        return ProjectStatusPeriod::create([
            'project_id' => $project->id,
            'project_status_id' => $this->projectStatus('paused')->id,
            'started_on' => ProjectStatusPeriod::dayKey($from),
            'ended_on' => ProjectStatusPeriod::dayKey($to),
        ]);
    }
}
