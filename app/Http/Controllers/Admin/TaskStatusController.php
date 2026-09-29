<?php

namespace App\Http\Controllers\Admin;

use App\Models\TaskStatus;

/** The task board's columns. All the behaviour is in the base class. */
class TaskStatusController extends WorkflowStatusController
{
    protected function modelClass(): string
    {
        return TaskStatus::class;
    }

    protected function routeName(): string
    {
        return 'task-statuses';
    }

    protected function noun(): string
    {
        return 'task status';
    }

    protected function description(): string
    {
        return 'What a task can be. Rename these to whatever the team says — the behaviour beside each one is what the system acts on, so "Waiting on client" behaving as Blocked keeps working after any rename.';
    }
}
