<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\User;
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
        DocumentLog::query()->delete(); // Also clear logs to ensure clean chains

        $recordsOfficer = User::where('email', 'records@dts.com')->first();

        if ($recordsOfficer) {
            $this->command->info('Creating 50 processing documents...');
            
            $now = Carbon::now();

            // Create 50 documents and loop through each one
            Document::factory()->count(50)->create()->each(function ($document) use ($recordsOfficer, &$now) {
                $route = $document->purpose->suggested_route ?: ['Accounting', 'Records'];
                
                // Increment time slightly for each document to ensure unique timestamps
                $now->addSeconds(1);

                $document->update([
                    'status' => 'processing',
                    'finalized_route' => $route,
                    'current_step' => 1,
                ]);

                // Manually replicate the SHA256 hashing logic for the seeder
                // NOTE: We do this manually because we need to control the timestamp
                $previousHash = 'genesis_hash'; // This is the first log for each document
                $action = 'Accepted and route finalized.';
                
                // Use the consistent timestamp for hashing and for the record itself
                $dataToHash = $document->id . $recordsOfficer->id . $action . $now->toIso8601String() . $previousHash;
                $newHash = hash('sha256', $dataToHash);

                DocumentLog::create([
                    'document_id' => $document->id,
                    'user_id' => $recordsOfficer->id,
                    'action' => $action,
                    'remarks' => 'Document has been accepted and routed for processing.',
                    'previous_hash' => $previousHash,
                    'hash' => $newHash,
                    'created_at' => $now,
                    'updated_at' => $now,
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