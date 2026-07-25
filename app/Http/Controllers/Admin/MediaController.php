<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MediaController extends Controller
{
    /** @var string[] Browsable folders on the public disk. */
    protected array $folders = ['courses', 'avatars', 'resources', 'attachments'];

    public function index(Request $request): View
    {
        $folder = in_array($request->query('folder'), $this->folders, true)
            ? $request->query('folder')
            : $this->folders[0];

        $disk = Storage::disk('public');

        $files = collect($disk->exists($folder) ? $disk->files($folder) : [])
            ->map(fn (string $path) => [
                'path' => $path,
                'name' => basename($path),
                'url' => $disk->url($path),
                'size' => rescue(fn () => $disk->size($path), 0, false),
                'modified' => rescue(fn () => \Illuminate\Support\Carbon::createFromTimestamp($disk->lastModified($path)), null, false),
                'is_image' => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true),
            ])
            ->sortByDesc('modified')
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 24;
        $files = new \Illuminate\Pagination\LengthAwarePaginator(
            $files->forPage($page, $perPage)->values(),
            $files->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $counts = collect($this->folders)->mapWithKeys(fn (string $dir) => [
            $dir => count($disk->exists($dir) ? $disk->files($dir) : []),
        ]);

        return view('admin.media.index', [
            'folders' => $this->folders,
            'folder' => $folder,
            'files' => $files,
            'counts' => $counts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'folder' => ['required', Rule::in($this->folders)],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:20480'],
        ]);

        foreach ($request->file('files', []) as $file) {
            $file->store($data['folder'], 'public');
        }

        AuditLogger::log('uploaded', 'media', null, null, [
            'folder' => $data['folder'],
            'files' => count($request->file('files', [])),
        ]);

        return redirect()
            ->route('admin.media.index', ['folder' => $data['folder']])
            ->with('success', count($request->file('files', [])).' file(s) uploaded.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:500']]);

        $path = str_replace('..', '', $data['path']);
        $folder = explode('/', $path)[0] ?? null;

        abort_unless(in_array($folder, $this->folders, true), 403);

        Storage::disk('public')->delete($path);

        AuditLogger::log('deleted', 'media', null, ['path' => $path], null);

        return redirect()
            ->route('admin.media.index', ['folder' => $folder])
            ->with('success', 'File deleted.');
    }
}
