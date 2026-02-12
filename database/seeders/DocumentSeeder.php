<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\User;
use App\Models\Department;
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
        $processingUsers = User::whereIn('role', ['staff', 'officer'])->get();
        $departments = Department::all();

        if (!$recordsOfficer) {
            $this->command->error('Records Officer not found. Skipping document seeding.');
            return;
        }
        if ($processingUsers->isEmpty()) {
            $this->command->error('No staff or officer users found. Skipping document seeding.');
            return;
        }
        if ($departments->isEmpty()) {
            $this->command->error('No departments found for routing. Skipping document seeding.');
            return;
        }

        $this->command->info('Creating 1000 documents with complex, year-spanning history...');
        
        $maxTotalDays = 14;
        $maxStepDays = 3;

        Document::factory()->count(1000)->create()->each(function (Document $document) use ($recordsOfficer, $processingUsers, $departments, $maxTotalDays, $maxStepDays) {
            
            $currentTimestamp = Carbon::now()->subDays(rand(0, 365))->setHour(rand(8, 16))->setMinutes(rand(0, 59))->setSeconds(rand(0, 59));
            $intakeTimestamp = $currentTimestamp->copy();

            $aimForReleased = (mt_rand() / mt_getrandmax()) < 0.95;
            if ($intakeTimestamp->lt(Carbon::now()->subMonth())) {
                $aimForReleased = true;
            }

            $routeNames = $document->purpose->suggested_route;
            $routeDepartments = collect();
            if (empty($routeNames) || !is_array($routeNames)) {
                $routeDepartments = $departments->random(rand(2, min(4, $departments->count())));
            } else {
                $departmentsByName = $departments->keyBy('name');
                $routeDepartments = collect($routeNames)->map(fn($name) => $departmentsByName->get($name))->filter();
            }
            if ($routeDepartments->isEmpty()) {
                $routeDepartments = $departments->random(rand(2, 3));
            }
            $routeNames = $routeDepartments->pluck('name')->toArray();
            $finalizedRoute = array_map(fn($name) => ['name' => $name, 'type' => 'initial'], $routeNames);

            $document->created_at = $intakeTimestamp;
            $document->updated_at = $intakeTimestamp;
            $document->finalized_route = $finalizedRoute;
            $document->status = 'processing';
            $document->current_step = 1;

            // ===== START DETAILED LOGGING =====
            $previousHash = 'genesis_hash';

            // 1. Submitted Log (by System/Guest)
            $action = 'Submitted';
            $dataToHash = $document->id . ($document->user_id ?? '') . $action . $intakeTimestamp->toIso8601String() . $previousHash;
            $newHash = hash('sha256', $dataToHash);
            DocumentLog::create(['document_id' => $document->id, 'user_id' => null, 'action' => $action, 'remarks' => 'Document submitted by guest via the public portal.', 'previous_hash' => $previousHash, 'hash' => $newHash, 'created_at' => $intakeTimestamp, 'updated_at' => $intakeTimestamp]);
            $previousHash = $newHash;
            $currentTimestamp->addMinutes(rand(1, 5));

            // 2. Accepted Log (by Records Officer)
            $action = 'Accepted and Document Routing finalized';
            $remarks = 'Route finalized. In transit to ' . $routeNames[0] . '.';
            $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash;
            $newHash = hash('sha256', $dataToHash);
            DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
            $previousHash = $newHash;

            // 3. Simulate processing steps (Receive -> Processing Complete for each step)
            $stepsToSimulate = $aimForReleased ? count($routeNames) : rand(0, count($routeNames));
            $actualStepsProcessed = 0;

            for ($i = 0; $i < $stepsToSimulate; $i++) {
                $stepDepartment = $routeDepartments->get($i);
                $stepUser = $processingUsers->where('department_id', $stepDepartment->id)->random();

                // a. Received Log
                $currentTimestamp->addMinutes(rand(5, $maxStepDays * 60)); // Time for transit and receiving
                $action = 'Received';
                $remarks = 'Document received by ' . $stepDepartment->name . '.';
                $dataToHash = $document->id . $stepUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create(['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                $previousHash = $newHash;

                // b. Processing Complete Log
                $currentTimestamp->addMinutes(rand(5, $maxStepDays * 120)); // Time for processing
                $action = 'Processing Complete';
                $isFinalStep = ($i === count($routeNames) - 1);
                $remarks = $isFinalStep 
                    ? 'Final step processed by ' . $stepDepartment->name . '. In transit to Records Unit for releasing.'
                    : 'Step processed by ' . $stepDepartment->name . '. In transit to ' . $routeNames[$i+1] . '.';
                $dataToHash = $document->id . $stepUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create(['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                $previousHash = $newHash;
                
                $actualStepsProcessed++;
                $document->current_step = $i + 2;

                if ($intakeTimestamp->diffInDays($currentTimestamp) > $maxTotalDays) {
                    break;
                }
            }

            // 4. Final status updates
            if ($actualStepsProcessed === count($routeNames)) { // All steps were completed
                $currentTimestamp->addMinutes(rand(5, 60));
                
                // a. Ready for Releasing Log
                $action = 'Ready for Releasing';
                $remarks = 'All processing steps completed. Document received by Records Unit for final releasing.';
                $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                $previousHash = $newHash;
                $document->status = 'ready_for_release';

                if ($aimForReleased) {
                    $currentTimestamp->addMinutes(rand(5, 120));
                    
                    // b. Document Released Log
                    $action = 'Document Released';
                    $remarks = 'The document has been released to the client.';
                    $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash;
                    $newHash = hash('sha256', $dataToHash);
                    DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                    $document->status = 'completed';
                }
            }

            $document->updated_at = $currentTimestamp;
            $document->save();

            $this->command->line('  - Created document: ' . $document->tracking_code . ' (Date: ' . $intakeTimestamp->toDateString() . ', Status: ' . $document->status . ')');
        });

        $this->command->info('Complex document seeding complete.');
    }
}