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

        $recordsOfficer = User::where('email', 'records@dts.com')->first();

        if ($recordsOfficer) {
            $this->command->info('Creating 50 processing documents...');
            
            // Create 50 documents and loop through each one
            Document::factory()->count(50)->create()->each(function ($document) use ($recordsOfficer) {
                $route = $document->purpose->suggested_route ?: ['Accounting', 'Records'];

                $document->update([
                    'status' => 'processing',
                    'finalized_route' => $route,
                    'current_step' => 1,
                ]);

                // Manually replicate the hashing logic for the seeder
                $lastLog = DocumentLog::where('document_id', $document->id)->orderBy('id', 'desc')->first();
                $previousHash = $lastLog ? $lastLog->hash : 'genesis_hash';
                $action = 'Accepted and route finalized.';
                $timestamp = Carbon::now()->toIso8601String();
                $dataToHash = $document->id . $recordsOfficer->id . $action . $timestamp . $previousHash;
                $newHash = Hash::make($dataToHash);

                DocumentLog::create([
                    'document_id' => $document->id,
                    'user_id' => $recordsOfficer->id,
                    'action' => $action,
                    'remarks' => 'Document has been accepted and routed for processing.',
                    'previous_hash' => $previousHash,
                    'hash' => $newHash,
                ]);

                // Output the tracking code to the console
                $this->command->line('  - Created document: ' . $document->tracking_code);
            });

            $this->command->info('Document seeding complete.');
        } else {
            $this->command->error('Records Officer not found. Skipping document seeding.');
        }
    }
}