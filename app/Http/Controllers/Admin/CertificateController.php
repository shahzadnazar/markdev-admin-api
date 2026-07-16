<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CertificateController extends Controller
{
    public function index(Request $request): View
    {
        $certificates = Certificate::query()
            ->with(['user', 'course'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim($request->string('search')).'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('certificate_number', 'like', $term)
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term))
                        ->orWhereHas('course', fn ($course) => $course->where('title', 'like', $term));
                });
            })
            ->latest('issued_at')
            ->paginate(12)
            ->withQueryString();

        return view('admin.certificates.index', ['certificates' => $certificates]);
    }

    public function create(): View
    {
        return view('admin.certificates.create', [
            'students' => User::role('student')->orderBy('name')->get(['id', 'name', 'email']),
            'courses' => Course::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'course_id' => ['required', Rule::exists('courses', 'id')],
        ]);

        $enrolled = Enrollment::where('user_id', $data['user_id'])
            ->where('course_id', $data['course_id'])
            ->exists();

        if (! $enrolled) {
            throw ValidationException::withMessages([
                'course_id' => 'This student is not enrolled in that course — enroll them first.',
            ]);
        }

        if (Certificate::where('user_id', $data['user_id'])->where('course_id', $data['course_id'])->exists()) {
            throw ValidationException::withMessages([
                'course_id' => 'A certificate for this student and course already exists.',
            ]);
        }

        Certificate::create([
            ...$data,
            'certificate_number' => 'MD-'.now()->year.'-'.Str::upper(Str::random(8)),
            'issued_at' => now(),
        ]);

        return redirect()->route('admin.certificates.index')->with('success', 'Certificate issued.');
    }

    public function destroy(Certificate $certificate): RedirectResponse
    {
        $certificate->delete();

        return redirect()->route('admin.certificates.index')->with('success', 'Certificate revoked.');
    }
}
