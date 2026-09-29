<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProjectStatus;

/** The states a client project moves through. Behaviour is in the base class. */
class ProjectStatusController extends WorkflowStatusController
{
    protected function modelClass(): string
    {
        return ProjectStatus::class;
    }

    protected function routeName(): string
    {
        return 'project-statuses';
    }

    protected function noun(): string
    {
        return 'project status';
    }

    protected function description(): string
    {
        return 'What a client project can be. The two closed behaviours are separate on purpose: delivered and abandoned both end a project, and every count of what MarkDev shipped has to tell them apart.';
    }
}
