<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have a clean slate on re-seed
        Document::query()->delete();

        // --- Create 5 documents with 'pending' status ---
        Document::factory()->count(5)->create();


        // --- Create 10 documents with 'processing' status ---
        $recordsOfficer = User::where('email', 'records@dts.com')->first();

        if ($recordsOfficer) {
            Document::factory()->count(10)->create()->each(function ($document) use ($recordsOfficer) {
                $route = $document->purpose->suggested_route ?: ['Accounting', 'Records'];

                $document->update([
                    'status' => 'processing',
                    'finalized_route' => $route,
                    'current_step' => 1,
                ]);

                // --- Manually replicate the hashing logic for the seeder ---
                // This is necessary because the main DatabaseSeeder disables model events.
                
                // 1. Find the last log for this document (will be null in this case).
                $lastLog = DocumentLog::where('document_id', $document->id)->orderBy('id', 'desc')->first();

                // 2. Determine previous hash.
                $previousHash = $lastLog ? $lastLog->hash : 'genesis_hash';

                // 3. Define the data to be hashed.
                $action = 'Accepted and route finalized.';
                $timestamp = Carbon::now()->toIso8601String();
                $dataToHash = $document->id . $recordsOfficer->id . $action . $timestamp . $previousHash;

                // 4. Calculate the new hash.
                $newHash = Hash::make($dataToHash);

                // 5. Create the log with the manually calculated hashes.
                DocumentLog::create([
                    'document_id' => $document->id,
                    'user_id' => $recordsOfficer->id,
                    'action' => $action,
                    'remarks' => 'Document has been accepted and routed for processing.',
                    'previous_hash' => $previousHash,
                    'hash' => $newHash,
                ]);
            });
        }
    }
}
