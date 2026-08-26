<?php

return [
    /*
     | Share of a lesson video a student must actually play before the lesson
     | can be marked complete. Coverage is measured from merged playback
     | segments, so seeking past a section never counts toward it.
     */
    'video_required_percent' => (int) env('VIDEO_REQUIRED_PERCENT', 90),
];
