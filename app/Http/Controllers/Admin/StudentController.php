<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\InstallmentPlanService;
use App\Support\AuditLogger;
use App\Support\BillingConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Student management — admissions per the MarkDev registration form,
 * the student directory, and per-student profiles with documents.
 */
class StudentController extends Controller
{
    /** Validated keys that do not map to student_profiles columns. */
    protected const NON_PROFILE_FIELDS = [
        'name', 'email', 'password', 'contact_number', 'is_active',
        'photo', 'cnic_doc', 'degree_doc',
        'course_id', 'create_plan', 'months', 'due_day', 'fine_per_day', 'first_amount', 'terms',
    ];

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? $request->query('status')
            : null;

        $students = User::role('student')
            ->with(['studentProfile', 'enrollments.course:id,title'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term))
                    ->orWhereHas('studentProfile', fn ($profile) => $profile
                        ->where('reg_no', 'like', $term)
                        ->orWhere('cnic', 'like', $term)
                        ->orWhere('father_name', 'like', $term));
            })
            ->when($request->filled('course'), fn ($query) => $query
                ->whereHas('enrollments', fn ($inner) => $inner->where('course_id', $request->integer('course'))))
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('admin.students.index', [
            'students' => $students,
            'status' => $status,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'totals' => [
                'students' => User::role('student')->count(),
                'active' => User::role('student')->where('is_active', true)->count(),
                'new_month' => User::role('student')->where('created_at', '>=', now()->startOfMonth())->count(),
                'enrollments' => Enrollment::count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.students.form', [
            'student' => null,
            'profile' => null,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'nextRegNo' => StudentProfile::nextRegNo(),
            'defaultFinePerDay' => BillingConfig::finePerDay(),
            'defaultRegistrationFee' => BillingConfig::registrationFee(),
        ]);
    }

    public function store(Request $request, InstallmentPlanService $installments): RedirectResponse
    {
        $data = $this->validated($request);

        $password = $request->filled('password') ? $request->string('password')->toString() : Str::random(10);

        $student = DB::transaction(function () use ($request, $data, $password) {
            $student = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $password,
                'phone' => $data['contact_number'],
                'is_active' => true,
            ]);
            $student->assignRole('student');

            $profile = new StudentProfile([
                ...collect($data)->except(self::NON_PROFILE_FIELDS)->all(),
                'reg_no' => StudentProfile::nextRegNo(),
                'terms_accepted_at' => now(),
            ]);
            $profile->user_id = $student->id;
            $profile->registered_by = $request->user()->id;

            $this->storeDocuments($request, $student, $profile);
            $profile->save();

            if (! empty($data['course_id'])) {
                Enrollment::create([
                    'user_id' => $student->id,
                    'course_id' => $data['course_id'],
                    'enrolled_at' => now(),
                    'progress_percent' => 0,
                ]);
            }

            return $student;
        });

        // Installment plan reuses the billing engine (outside the transaction:
        // it creates its own invoice rows and never partially fails silently).
        if (! empty($data['course_id']) && $request->boolean('create_plan')) {
            $course = Course::findOrFail($data['course_id']);
            $months = (int) $data['months'];
            $installments->create(
                student: $student,
                course: $course,
                title: $course->title,
                totalFee: (float) $data['total_fee'],
                months: $months,
                dueDay: (int) $data['due_day'],
                finePerDay: $request->filled('fine_per_day') ? (float) $data['fine_per_day'] : null,
                currency: 'PKR',
                advance: true,
                firstAmount: ($months > 1 && $request->filled('first_amount')) ? (float) $data['first_amount'] : null,
                registrationFee: $request->filled('registration_fee') ? (float) $data['registration_fee'] : 0.0,
            );
        }

        AuditLogger::log('registered', 'students', $student->id, null, [
            'reg_no' => $student->studentProfile->reg_no,
            'name' => $student->name,
            'applied_course' => $data['applied_course'] ?? null,
        ]);

        $message = "Student registered — {$student->studentProfile->reg_no}.";
        if (! $request->filled('password')) {
            $message .= " Portal password: {$password} (share it once; the student can change it).";
        }

        return redirect()->route('admin.students.show', $student)->with('success', $message);
    }

    public function show(User $student): View
    {
        abort_unless($student->hasRole('student'), 404);

        $student->load(['studentProfile.registrar:id,name', 'enrollments.course:id,title,level']);

        $invoices = Invoice::where('user_id', $student->id)->get(['id', 'amount', 'fine_amount', 'status']);

        return view('admin.students.show', [
            'student' => $student,
            'profile' => $student->studentProfile,
            'fees' => [
                'paid' => $invoices->where('status', 'paid')->sum(fn ($invoice) => (float) $invoice->amount + (float) $invoice->fine_amount),
                'outstanding' => $invoices->whereIn('status', ['open', 'pending', 'past_due'])
                    ->sum(fn ($invoice) => (float) $invoice->amount + (float) $invoice->fine_amount),
                'invoices' => $invoices->count(),
            ],
        ]);
    }

    public function edit(User $student): View
    {
        abort_unless($student->hasRole('student'), 404);

        return view('admin.students.form', [
            'student' => $student,
            'profile' => $student->studentProfile,
            'courses' => Course::orderBy('title')->get(['id', 'title']),
            'nextRegNo' => $student->studentProfile?->reg_no ?? StudentProfile::nextRegNo(),
            'defaultFinePerDay' => BillingConfig::finePerDay(),
            'defaultRegistrationFee' => BillingConfig::registrationFee(),
        ]);
    }

    public function update(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->hasRole('student'), 404);

        $data = $this->validated($request, $student);

        $student->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['contact_number'],
            'is_active' => $request->boolean('is_active', true),
        ]);

        $profile = $student->studentProfile ?? new StudentProfile([
            'user_id' => $student->id,
            'reg_no' => StudentProfile::nextRegNo(),
        ]);
        $profile->fill(
            collect($data)->except(self::NON_PROFILE_FIELDS)->all()
        );
        $profile->user_id = $student->id;

        $this->storeDocuments($request, $student, $profile);
        $profile->save();

        return redirect()->route('admin.students.show', $student)->with('success', "Student \"{$student->name}\" updated.");
    }

    public function destroy(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->hasRole('student'), 404);

        $name = $student->name;
        $student->delete();

        AuditLogger::log('deleted', 'students', $student->id, null, ['name' => $name]);

        return redirect()->route('admin.students.index')->with('success', "Student \"{$name}\" moved to trash.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?User $student = null): array
    {
        $creating = $student === null;
        $withPlan = $request->boolean('create_plan');

        return $request->validate([
            // Personal information
            'name' => ['required', 'string', 'max:255'],
            'father_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'address' => ['required', 'string', 'max:500'],
            'contact_number' => ['required', 'string', 'max:30'],
            'cnic' => ['required', 'string', 'max:20', Rule::unique('student_profiles', 'cnic')->ignore($student?->studentProfile?->id)],
            'guardian_contact' => ['nullable', 'string', 'max:30'],
            'current_qualification' => ['required', 'string', 'max:255'],
            'applied_course' => ['required', 'string', 'max:255'],

            // Portal account
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student?->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],

            // Emergency contact
            'emergency_name' => ['required', 'string', 'max:255'],
            'emergency_contact' => ['required', 'string', 'max:30'],
            'emergency_relation' => ['required', 'string', 'max:50'],
            'emergency_residence' => ['nullable', 'string', 'max:255'],

            // Documents — each capped at 1 MB
            'photo' => [$creating ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'cnic_doc' => [$creating ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:1024'],
            'degree_doc' => [$creating ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:1024'],

            // Office use only
            'date_of_joining' => ['required', 'date'],
            'course_id' => ['nullable', Rule::exists('courses', 'id')],
            'reference' => ['nullable', 'string', 'max:255'],
            'total_fee' => [$withPlan ? 'required' : 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'submitted_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'registration_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],

            // Installments (optional, only meaningful with a course)
            'create_plan' => ['nullable', 'boolean'],
            'months' => [$withPlan ? 'required' : 'nullable', 'integer', 'min:1', 'max:36'],
            'first_amount' => ['nullable', 'numeric', 'min:1', 'lt:total_fee'],
            'due_day' => [$withPlan ? 'required' : 'nullable', 'integer', 'min:1', 'max:28'],
            'fine_per_day' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            // Terms & conditions
            'terms' => [$creating ? 'accepted' : 'nullable'],

            'is_active' => ['nullable', 'boolean'],
        ], [
            'terms.accepted' => 'The student must accept the terms and conditions.',
            'photo.max' => 'The profile picture must not be larger than 1 MB.',
            'cnic_doc.max' => 'The CNIC document must not be larger than 1 MB.',
            'degree_doc.max' => 'The degree document must not be larger than 1 MB.',
        ]);
    }

    /** Store any uploaded documents, replacing (and deleting) old files. */
    protected function storeDocuments(Request $request, User $student, StudentProfile $profile): void
    {
        if ($request->hasFile('photo')) {
            $this->deleteDocument($profile->photo_path);
            $profile->photo_path = $request->file('photo')->store('students/photos', 'public');

            // The same photo drives the avatar across the panel and portal.
            $student->update(['avatar_path' => $profile->photo_path]);
        }

        if ($request->hasFile('cnic_doc')) {
            $this->deleteDocument($profile->cnic_doc_path);
            $profile->cnic_doc_path = $request->file('cnic_doc')->store('students/documents', 'public');
        }

        if ($request->hasFile('degree_doc')) {
            $this->deleteDocument($profile->degree_doc_path);
            $profile->degree_doc_path = $request->file('degree_doc')->store('students/documents', 'public');
        }
    }

    protected function deleteDocument(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
