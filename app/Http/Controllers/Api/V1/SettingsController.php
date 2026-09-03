<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\UpdateSettingsRequest;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends ApiController
{
    /** @var array<string, bool> */
    protected const NOTIFICATION_DEFAULTS = [
        'email_announcements' => true,
        'email_assignment_graded' => true,
        'email_due_reminders' => true,
        'email_new_content' => false,
        'push_announcements' => true,
        'push_due_reminders' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user()->settings)]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $settings = UserSetting::firstOrNew(['user_id' => $request->user()->id]);

        if ($request->has('language')) {
            $settings->language = $request->string('language')->value();
        }

        if ($request->has('notifications')) {
            $current = array_merge(self::NOTIFICATION_DEFAULTS, $settings->notifications ?? []);
            $incoming = array_intersect_key(
                $request->validated('notifications', []),
                self::NOTIFICATION_DEFAULTS,
            );
            $settings->notifications = array_map(
                fn ($value) => (bool) $value,
                array_merge($current, $incoming),
            );
        }

        $settings->save();

        return response()->json(['data' => $this->present($settings)]);
    }

    /** @return array<string, mixed> */
    protected function present(?UserSetting $settings): array
    {
        return [
            'language' => $settings?->language ?? 'en',
            'notifications' => array_merge(self::NOTIFICATION_DEFAULTS, $settings?->notifications ?? []),
        ];
    }
}
