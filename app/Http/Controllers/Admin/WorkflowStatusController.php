<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The shared CRUD behind the two configurable status lists.
 *
 * Task statuses and project statuses are the same screen over two tables with
 * two behaviour sets, so the screen is written once. The subclasses say which
 * table and what to call it; everything that could go wrong is here, once.
 *
 * ## The rule that makes this worth a base class
 *
 * Every behaviour must keep at least one ACTIVE status. Four paths can break
 * that — creating, editing, deactivating and deleting — and a check bolted to
 * each of them is a check somebody will add a fifth path without. So every
 * write happens inside a transaction and the rule is asserted AFTER it: the
 * write is made, the table is asked whether it is still coherent, and an answer
 * it does not like rolls the write back. A future path gets the rule for free by
 * using the same wrapper.
 *
 * ## No implicit route binding
 *
 * The route parameter arrives as an id and is resolved through the subclass's
 * own model. An `abstract` method cannot be type-hinted with two different
 * classes, and the alternative — five one-line overrides per subclass purely to
 * name a type — is more code to keep in step for no behaviour. findOrFail gives
 * the same 404 the binding would.
 */
abstract class WorkflowStatusController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** The route-name segment, e.g. `task-statuses`. */
    abstract protected function routeName(): string;

    /** What one row is called in a sentence, e.g. `task status`. */
    abstract protected function noun(): string;

    /** The sentence under the page title. */
    abstract protected function description(): string;

    /* -------------------------------- Screens ------------------------------- */

    public function index(): View
    {
        return view('admin.workflow-statuses.index', $this->shared([
            'statuses' => $this->modelClass()::ordered()->get(),
        ]));
    }

    public function create(): View
    {
        return view('admin.workflow-statuses.form', $this->shared([
            'status' => null,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $status = $this->write(function () use ($data) {
            return $this->modelClass()::create($data);
        });

        return redirect()->route('admin.'.$this->routeName().'.index')
            ->with('success', ucfirst($this->noun())." \"{$status->label}\" added.");
    }

    public function edit(int $id): View
    {
        return view('admin.workflow-statuses.form', $this->shared([
            'status' => $this->find($id),
        ]));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $status = $this->find($id);
        $data = $this->validated($request, $status);

        $this->write(fn () => $status->update($data));

        return redirect()->route('admin.'.$this->routeName().'.index')
            ->with('success', ucfirst($this->noun())." \"{$status->label}\" updated.");
    }

    /**
     * Retire a status, or offer it again.
     *
     * Rows already on a retired status keep it — that is the difference between
     * this and delete, and the reason a status list can be changed at all
     * without rewriting history.
     */
    public function toggle(int $id): RedirectResponse
    {
        $status = $this->find($id);

        $this->write(fn () => $status->update(['is_active' => ! $status->is_active]));

        return back()->with('success', $status->is_active
            ? "\"{$status->label}\" is on offer again."
            : "\"{$status->label}\" is retired. Anything already on it keeps it.");
    }

    /** Reorder by swapping with the neighbour, as slots, modules and lessons do. */
    public function move(Request $request, int $id): RedirectResponse
    {
        $status = $this->find($id);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        $neighbour = $this->modelClass()::query()
            ->when($data['direction'] === 'up',
                fn ($query) => $query->where('sort_order', '<', $status->sort_order)->orderByDesc('sort_order'),
                fn ($query) => $query->where('sort_order', '>', $status->sort_order)->orderBy('sort_order'))
            ->first();

        if ($neighbour) {
            [$status->sort_order, $neighbour->sort_order] = [$neighbour->sort_order, $status->sort_order];
            $status->save();
            $neighbour->save();
        }

        return back();
    }

    /**
     * Delete a status — REFUSED, not reassigned, when anything is on it.
     *
     * Reassigning would be a silent edit to other people's records: a task
     * somebody put on "Blocked" would come back from lunch saying "To Do", with
     * no trace of who decided that. The admin is told the count and retires the
     * status instead, which is what they almost always meant — the rows keep
     * their history and the status stops being offered.
     */
    public function destroy(int $id): RedirectResponse
    {
        $status = $this->find($id);
        $inUse = $status->usageCount();

        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'behaviour' => sprintf(
                    '"%s" is on %d record(s) and cannot be deleted. Retire it instead — they keep it and it stops being offered.',
                    $status->label,
                    $inUse,
                ),
            ]);
        }

        $label = $status->label;

        $this->write(fn () => $status->delete());

        return redirect()->route('admin.'.$this->routeName().'.index')
            ->with('success', ucfirst($this->noun())." \"{$label}\" deleted.");
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function find(int $id): Model
    {
        return $this->modelClass()::findOrFail($id);
    }

    /**
     * Make a change, then refuse it if it left a behaviour unreachable.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $change
     * @return TReturn
     */
    protected function write(\Closure $change): mixed
    {
        return DB::transaction(function () use ($change) {
            $result = $change();

            $this->assertEveryBehaviourIsReachable();

            return $result;
        });
    }

    /**
     * Every behaviour keeps at least one active status.
     *
     * A behaviour with nothing active is a board column nothing can be moved
     * into, and a question later phases ask — "is this blocked?", "did this
     * close?" — that nothing can ever answer. Named in the message, because
     * "that is not allowed" leaves the admin toggling five rows to find out
     * which one mattered. The same shape as the refusal when every academy
     * working day is unticked.
     */
    protected function assertEveryBehaviourIsReachable(): void
    {
        $missing = $this->modelClass()::behavioursWithoutActive();

        if ($missing === []) {
            return;
        }

        $behaviours = $this->modelClass()::BEHAVIOURS;

        throw ValidationException::withMessages([
            'behaviour' => sprintf(
                'Keep at least one active %s for %s: %s. The code branches on the behaviour, not on the label, so nothing could reach that state.',
                $this->noun(),
                count($missing) === 1 ? 'this behaviour' : 'these behaviours',
                collect($missing)
                    ->map(fn (string $behaviour) => '"'.($behaviours[$behaviour] ?? $behaviour).'"')
                    ->implode(', '),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function shared(array $extra = []): array
    {
        return array_merge([
            'routeName' => $this->routeName(),
            'noun' => $this->noun(),
            'heading' => ucfirst($this->noun()).'es',
            'description' => $this->description(),
            'behaviours' => $this->modelClass()::BEHAVIOURS,
        ], $extra);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Model $status = null): array
    {
        $request->merge(['label' => trim((string) $request->input('label'))]);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            // An admin PICKS a behaviour; they never invent one. Adding to the
            // set means writing the code that branches on it.
            'behaviour' => ['required', Rule::in(array_keys($this->modelClass()::BEHAVIOURS))],
            // As <input type="color"> posts it.
            'colour' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'behaviour.required' => 'Pick what this status means to the system.',
            'behaviour.in' => 'Pick what this status means to the system.',
            'colour.regex' => 'Pick a colour.',
        ]);

        return [
            'label' => $data['label'],
            'behaviour' => $data['behaviour'],
            'colour' => strtoupper($data['colour']),
            'is_active' => (bool) ($data['is_active'] ?? false),
            // New statuses land at the end of the list; editing keeps its place.
            'sort_order' => $status?->sort_order ?? ((int) $this->modelClass()::max('sort_order') + 1),
        ];
    }
}
