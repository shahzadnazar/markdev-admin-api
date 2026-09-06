<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RuleTemplate;
use App\Support\RuleBook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lets an admin reword the student Rules & Regulations page.
 *
 * Wording only. Rules cannot be added or removed here, because every sentence
 * on that page has code enforcing it and a free-text rule nothing enforces is
 * exactly what the page must not carry. What an admin can change is how a rule
 * is said — and reset it to what shipped if they liked that better.
 *
 * The numbers are never typed: they are {placeholders} filled from Settings
 * when the page is served, so rewording a rule cannot put a stale figure in
 * front of a student.
 */
class RuleTemplateController extends Controller
{
    public function index(Request $request): View
    {
        $context = RuleBook::context($request->user());

        return view('admin.rules.index', [
            'sections' => RuleBook::SECTIONS,
            'rules' => RuleTemplate::ordered()->get()->groupBy('section'),
            // Shown beside each field so an admin can see what a placeholder
            // will become before they save.
            'context' => $context,
            'lastUpdated' => RuleBook::lastUpdatedAt(),
        ]);
    }

    public function update(Request $request, RuleTemplate $rule): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            // Which box the text came from, so a refused save re-fills that
            // one rather than every textarea on the page.
            'rule_id' => ['nullable', 'integer'],
        ]);

        $body = trim($data['body']);
        $known = array_keys(RuleBook::context($request->user()));
        $unknown = array_diff(RuleTemplate::placeholdersIn($body), $known);

        // Refused rather than shipped: an unresolved placeholder would reach a
        // student as a literal {typo} in the middle of a sentence.
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => 'Unknown placeholder: {'.implode('}, {', $unknown).'}. Available: {'.implode('}, {', $known).'}.',
            ]);
        }

        $rule->update(['body' => $body]);

        return back()->with('success', 'Rule updated. Students see the new wording immediately.');
    }

    /** Put back what the current release ships for this rule. */
    public function reset(RuleTemplate $rule): RedirectResponse
    {
        $rule->update(['body' => $rule->default_body]);

        return back()->with('success', 'Rule reset to the default wording.');
    }
}
