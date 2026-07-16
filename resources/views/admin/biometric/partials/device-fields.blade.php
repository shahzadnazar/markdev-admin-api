{{-- Shared device form fields. Expects: $device (nullable), $courses. --}}
<x-form.input label="Name" name="name" :value="$device?->name" required placeholder="Main lab terminal" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-form.input label="Vendor" name="vendor" :value="$device?->vendor" placeholder="ZKTeco, ESSL…" />
    <x-form.input label="Serial number" name="serial_number" :value="$device?->serial_number" required />
</div>
<x-form.input label="Location" name="location" :value="$device?->location" placeholder="Lab 2, main campus" />
<x-form.select label="Marks attendance for" name="course_id" hint="Punches create records for this course.">
    <option value="">— no course yet —</option>
    @foreach ($courses as $course)
        <option value="{{ $course->id }}" @selected(old('course_id', $device?->course_id) == $course->id)>{{ $course->title }}</option>
    @endforeach
</x-form.select>
<div class="grid gap-4 sm:grid-cols-2">
    <x-form.input type="time" label="Session start" name="session_start"
        :value="$device?->session_start ? \Illuminate\Support\Str::of($device->session_start)->substr(0, 5) : null"
        hint="Blank = every punch counts as present." />
    <x-form.input type="number" label="Late after (minutes)" name="late_after_minutes"
        :value="$device?->late_after_minutes ?? 15" required min="0" max="240" />
</div>
<x-form.toggle label="Device is active" name="is_active" :checked="(bool) old('is_active', $device?->is_active ?? true)"
    hint="Inactive devices are rejected at the door." />
