<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CertificateController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $certificates = Certificate::where('user_id', $request->user()->id)
            ->with('course')
            ->orderByDesc('issued_at')
            ->get();

        return CertificateResource::collection($certificates);
    }

    /**
     * Streams the rendered PDF. Reached through a signed URL (validated by the
     * `signed` middleware) so the portal can link to it directly without an
     * Authorization header — the owner is encoded in the certificate itself.
     */
    public function download(Certificate $certificate): Response
    {
        $certificate->load(['user', 'course']);

        $pdf = Pdf::loadHTML($this->certificateHtml($certificate))->setPaper('a4', 'landscape');

        return $pdf->download("certificate-{$certificate->certificate_number}.pdf");
    }

    protected function certificateHtml(Certificate $certificate): string
    {
        $student = e($certificate->user?->name ?? 'Student');
        $course = e($certificate->course?->title ?? 'Course');
        $number = e($certificate->certificate_number);
        $date = e($certificate->issued_at?->format('F j, Y') ?? '');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                @page { margin: 0; }
                body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #1f2937; }
                .certificate {
                    box-sizing: border-box; width: 100%; height: 100%;
                    padding: 60px 80px; border: 14px solid #4f46e5; text-align: center;
                }
                .wordmark { font-size: 26px; font-weight: bold; letter-spacing: 4px; color: #4f46e5; }
                .wordmark span { color: #111827; }
                .heading { font-size: 16px; letter-spacing: 6px; text-transform: uppercase; margin-top: 42px; color: #6b7280; }
                .student { font-size: 40px; font-weight: bold; margin-top: 28px; }
                .lede { margin-top: 22px; font-size: 15px; color: #6b7280; }
                .course { font-size: 26px; font-weight: bold; margin-top: 12px; }
                .meta { margin-top: 56px; font-size: 12px; color: #6b7280; }
                .meta strong { color: #1f2937; }
            </style>
        </head>
        <body>
            <div class="certificate">
                <div class="wordmark">MARK<span>DEV</span></div>
                <div class="heading">Certificate of Completion</div>
                <div class="student">{$student}</div>
                <div class="lede">has successfully completed the course</div>
                <div class="course">{$course}</div>
                <div class="meta">
                    Certificate No. <strong>{$number}</strong> &nbsp;&middot;&nbsp; Issued on <strong>{$date}</strong>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}
