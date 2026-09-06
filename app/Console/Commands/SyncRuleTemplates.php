<?php

namespace App\Console\Commands;

use App\Models\RuleTemplate;
use App\Support\RuleBook;
use Illuminate\Console\Command;

/**
 * Brings the rule table in line with the rules this release ships.
 *
 * The wording lives in a table so an admin can reword it, which means a
 * release that improves a sentence has no way in — a migration only runs once.
 * This is that way in, and it is safe to run on every deploy: a rule an admin
 * has reworded keeps their wording and only has its "reset to default" text
 * refreshed, while an untouched rule takes the new sentence.
 */
class SyncRuleTemplates extends Command
{
    protected $signature = 'rules:sync {--dry-run : Report what would change without writing}';

    protected $description = 'Apply the rule wording this release ships, keeping admin edits';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $added = 0;
        $updated = 0;
        $kept = 0;

        foreach (RuleBook::defaults() as $position => $rule) {
            $existing = RuleTemplate::where('key', $rule['key'])->first();

            if ($existing === null) {
                $added++;
                $this->line('  new: '.$rule['key']);

                if (! $dryRun) {
                    RuleTemplate::create([
                        'key' => $rule['key'],
                        'section' => $rule['section'],
                        'position' => $position,
                        'body' => $rule['body'],
                        'default_body' => $rule['body'],
                    ]);
                }

                continue;
            }

            if ($existing->isEdited()) {
                $kept++;
            } elseif ($existing->body !== $rule['body']) {
                $updated++;
                $this->line('  reworded: '.$rule['key']);
            }

            if (! $dryRun) {
                $existing->update([
                    'section' => $rule['section'],
                    'position' => $position,
                    'body' => $existing->isEdited() ? $existing->body : $rule['body'],
                    'default_body' => $rule['body'],
                ]);
            }
        }

        $this->info($dryRun
            ? "Dry run — {$added} would be added, {$updated} reworded, {$kept} left as the admin wrote them."
            : "{$added} added, {$updated} reworded, {$kept} left as the admin wrote them.");

        return self::SUCCESS;
    }
}
