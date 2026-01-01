<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Spatie\Backup\Helpers\Format;

class BackupManagerController extends Controller
{
    /**
     * Display a listing of the resource, or return as JSON for AJAX requests.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $diskName = config('backup.backup.destination.disks')[0];
        $disk = Storage::disk($diskName);
        $appName = config('backup.backup.name');
        
        $files = $disk->allFiles($appName);

        $backups = collect($files)
            ->filter(function ($file) {
                return pathinfo($file, PATHINFO_EXTENSION) === 'zip';
            })
            ->map(function ($file) use ($disk) {
                return [
                    'file_path' => $file,
                    'file_name' => basename($file),
                    'file_size' => Format::humanReadableSize($disk->size($file)),
                    'last_modified' => Carbon::createFromTimestamp($disk->lastModified($file)),
                ];
            })
            ->reverse()
            ->values(); // Reset keys after reversing

        if ($request->ajax()) {
            return response()->json($backups);
        }

        return view('admin.backups', [
            'backups' => $backups,
        ]);
    }

    /**
     * Dispatch a job to create a new backup.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function create()
    {
        try {
            Artisan::queue('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => true,
            ]);

            return back()->with('success', 'A new database backup has been queued. The list will refresh automatically when it is complete.');
        } catch (\Exception $e) {
            report($e);
            return back()->with('error', 'The backup could not be started. Please check the logs.');
        }
    }

    /**
     * Download a specific backup file.
     *
     * @param  string  $fileName
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\RedirectResponse
     */
    public function download($fileName)
    {
        $diskName = config('backup.backup.destination.disks')[0];
        $appName = config('backup.backup.name');
        $filePath = $appName . '/' . $fileName;

        if (Storage::disk($diskName)->exists($filePath)) {
            return Storage::disk($diskName)->download($filePath);
        }

        return back()->with('error', 'The requested backup file could not be found.');
    }

    /**
     * Delete a specific backup file.
     *
     * @param  string  $fileName
     * @return \Illuminate\Http\RedirectResponse
     */
    public function delete($fileName)
    {
        $diskName = config('backup.backup.destination.disks')[0];
        $appName = config('backup.backup.name');
        $filePath = $appName . '/' . $fileName;

        if (Storage::disk($diskName)->exists($filePath)) {
            Storage::disk($diskName)->delete($filePath);
            return back()->with('success', "Backup '{$fileName}' was deleted successfully.");
        }

        return back()->with('error', 'The requested backup file could not be found for deletion.');
    }

    /**
     * Queue a job to restore the database from a specific backup.
     *
     * @param  string  $fileName
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore($fileName)
    {
        try {
            // Queue the restore command to run in the background
            Artisan::queue('db:restore', [
                '--file' => $fileName,
            ]);

            return redirect()->route('system.backups.index')->with('success', "A restore from '{$fileName}' has been queued. The application may go into maintenance mode briefly.");

        } catch (\Exception $e) {
            report($e);
            return back()->with('error', 'The restore job could not be started. Please check the logs.');
        }
    }
}
