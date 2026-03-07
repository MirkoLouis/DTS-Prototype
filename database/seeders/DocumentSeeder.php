<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\User;
use App\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have a clean slate on re-seed
        Document::query()->delete();
        DocumentLog::query()->delete();
        DB::table('database_metrics')->delete();

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

        $documentsToCreate = 10000;

        // Stage 1: Create base document records
        $this->command->info('');
        $this->command->info('Stage 1 of 3: Creating ' . $documentsToCreate . ' base document records...');
        $documents = Document::factory()->count($documentsToCreate)->create();
        $this->command->info('Stage 1 complete.');
        $this->command->info('');

        // Stage 2: Generate detailed document history
        $this->command->info('Stage 2 of 3: Generating detailed document history and performance metrics...');
        $progressBar = $this->command->getOutput()->createProgressBar($documentsToCreate);
        $progressBar->start();
        
        $metricsToInsert = [];
        $maxTotalDays = 14;
        $maxStepDays = 3;

        $documents->each(function (Document $document) use ($recordsOfficer, $processingUsers, $departments, $maxTotalDays, $maxStepDays, $progressBar, &$metricsToInsert) {
            
            $currentTimestamp = Carbon::now()->subYears(rand(0, 5))->subDays(rand(0, 365))->setHour(rand(8, 16))->setMinutes(rand(0, 59))->setSeconds(rand(0, 59));
            if ($currentTimestamp->isWeekend()) {
                $currentTimestamp->next(Carbon::MONDAY)->setTime(rand(8, 16), rand(0, 59), rand(0, 59));
            }

            $intakeTimestamp = $currentTimestamp->copy();

            $aimForReleased = (mt_rand() / mt_getrandmax()) < 0.95;

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

            $willHaveReturn = count($routeNames) > 2 && (mt_rand() / mt_getrandmax()) < 0.10; // 10% chance
            $returnTriggerStep = -1;
            $requestingDeptIndex = -1;
            if ($willHaveReturn) {
                $returnTriggerStep = rand(1, count($routeNames) - 1);
                $requestingDeptIndex = rand(0, $returnTriggerStep - 1);
                $aimForReleased = false; 
            }

            $document->created_at = $intakeTimestamp;
            $document->updated_at = $intakeTimestamp;
            $document->finalized_route = $finalizedRoute;
            $document->status = 'processing';
            $document->current_step = 1;

            // ===== METRICS HELPER =====
            $generateMetrics = function($timestamp, $isPeak = false) use (&$metricsToInsert) {
                $isBusinessHours = $timestamp->hour >= 8 && $timestamp->hour < 17 && !$timestamp->isWeekend();
                // Realistic connection numbers
                $baseConnections = $isBusinessHours ? rand(10, 50) : rand(2, 10);
                $connections = $isPeak ? $baseConnections + rand(20, 50) : $baseConnections;
                
                // Realistic query times in milliseconds
                $avg_query_time_ms = $isPeak ? rand(50, 200) / 10 : rand(5, 50) / 10; // Peak: 5-20ms, Base: 0.5-5ms
                
                // Slow queries are rare, but more likely on peak.
                $slow_queries = $isPeak ? rand(0, 3) : (rand(0, 100) < 2 ? 1 : 0);

                $metricsToInsert[] = [
                    'connections' => $connections,
                    'avg_query_time_ms' => $avg_query_time_ms,
                    'slow_queries' => $slow_queries,
                    'created_at' => $timestamp,
                ];
            };

            // ===== START DETAILED LOGGING =====
            $previousHash = 'genesis_hash';

            // 1. Submitted Log (by System/Guest)
            $action = 'Submitted';
            $stateHash = DocumentLog::calculateStateHash($document);
            $signature = 'signed_by_guest';
            $dataToHash = $document->id . ($document->user_id ?? '') . $action . $intakeTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
            $newHash = hash('sha256', $dataToHash);
            DocumentLog::create(['document_id' => $document->id, 'user_id' => null, 'action' => $action, 'remarks' => 'Document submitted by guest via the public portal.', 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $intakeTimestamp, 'updated_at' => $intakeTimestamp]);
            $previousHash = $newHash;
            $generateMetrics($currentTimestamp);
            $currentTimestamp->addMinutes(rand(1, 5));

            // --- START: Decline Simulation (1% chance) ---
            if ((mt_rand() / mt_getrandmax()) < 0.01) { // 1% chance of being declined
                $declineReasons = [
                    "Incomplete or missing required documents.",
                    "Submitted to the wrong office/department.",
                    "Form is an outdated or incorrect version.",
                    "Information provided is unreadable or illegible.",
                    "Purpose of request is unclear or not specified."
                ];
                $reason = $declineReasons[array_rand($declineReasons)];

                $currentTimestamp->addMinutes(rand(5, 30)); // Time for officer to review and decline
                $action = 'Declined';
                
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create([
                    'document_id' => $document->id, 
                    'user_id' => $recordsOfficer->id, 
                    'action' => $action, 
                    'remarks' => $reason, 
                    'previous_hash' => $previousHash, 
                    'hash' => $newHash, 
                    'document_state_hash' => $stateHash,
                    'signature' => $signature,
                    'created_at' => $currentTimestamp, 
                    'updated_at' => $currentTimestamp
                ]);

                $document->status = 'declined';
                $document->decline_reason = $reason;
                $document->declined_at = $currentTimestamp;
                $document->updated_at = $currentTimestamp;
                $generateMetrics($currentTimestamp, true); // Spike metrics for decline
                $document->save();
                $progressBar->advance();
                return; // Skip to the next document in the loop
            }
            // --- END: Decline Simulation ---

            // 2. Accepted Log (by Records Officer)
            $action = 'Accepted and Document Routing finalized';
            $remarks = 'Route finalized. In transit to ' . $routeNames[0] . '.';
            $stateHash = DocumentLog::calculateStateHash($document);
            $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
            $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
            $newHash = hash('sha256', $dataToHash);
            DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
            $previousHash = $newHash;
            $generateMetrics($currentTimestamp);

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
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = $stepUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                $dataToHash = $document->id . $stepUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create(['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                $previousHash = $newHash;
                $generateMetrics($currentTimestamp);

                // b. Processing Complete Log
                $currentTimestamp->addMinutes(rand(5, $maxStepDays * 120)); // Time for processing
                $action = 'Processing Complete';
                $isFinalStep = ($i === count($routeNames) - 1);
                $remarks = $isFinalStep 
                    ? 'Final step processed by ' . $stepDepartment->name . '. In transit to Records Unit for releasing.'
                    : 'Step processed by ' . $stepDepartment->name . '. In transit to ' . ($routeNames[$i+1] ?? 'Records Unit') . '.';
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = $stepUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                $dataToHash = $document->id . $stepUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create(['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                $previousHash = $newHash;
                $generateMetrics($currentTimestamp);

                // ---- START: Return Request Simulation ----
                if ($i === $returnTriggerStep) {
                    $requestingDepartment = $routeDepartments->get($requestingDeptIndex);
                    $requestingUser = $processingUsers->where('department_id', $requestingDepartment->id)->random();

                    // a. Create "Return Requested" log
                    $currentTimestamp->addMinutes(rand(10, 60)); // Add some delay for the request
                    $action = 'Return Requested';
                    $remarks = 'Staff member requested document be returned for corrections.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $requestingUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $requestingUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    DocumentLog::create(['document_id' => $document->id, 'user_id' => $requestingUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp, true); // Spike for return request
                    
                    // b. Create "Return Approved" log by Records Officer
                    $currentTimestamp->addMinutes(rand(10, 120)); // Add delay for approval
                    $action = 'Return Approved & Rerouted';
                    $remarks = 'Return request approved by Records Unit. Document rerouted back to ' . $requestingDepartment->name . '.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp, true); // Spike for return approval

                    // c. Modify the route for subsequent loop iterations
                    $reroutedStep = ['name' => $requestingDepartment->name, 'type' => 'rerouted'];
                    array_splice($finalizedRoute, $i + 1, 0, [$reroutedStep]);
                    $routeDepartments->splice($i + 1, 0, [$requestingDepartment]);

                    // Update dependent variables
                    $routeNames = $routeDepartments->pluck('name')->toArray();
                    $stepsToSimulate = count($routeNames);
                    $document->finalized_route = $finalizedRoute;

                    // Prevent this from running again
                    $returnTriggerStep = -1;
                }
                // ---- END: Return Request Simulation ----
                
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
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                $newHash = hash('sha256', $dataToHash);
                DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                $previousHash = $newHash;
                $document->status = 'ready_for_release';
                $generateMetrics($currentTimestamp);

                if ($aimForReleased) {
                    $currentTimestamp->addMinutes(rand(5, 120));
                    
                    // b. Document Released Log
                    $action = 'Document Released';
                    $remarks = 'The document has been released to the client.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    DocumentLog::create(['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp]);
                    $document->status = 'completed';
                    $generateMetrics($currentTimestamp);
                }
            }

            $document->updated_at = $currentTimestamp;
            $document->save();
            $progressBar->advance();
        });

        $progressBar->finish();
        $this->command->info('');
        $this->command->info('Stage 2 complete.');

        // Stage 3: Insert all collected database metrics
        $this->command->info('');
        $this->command->info('Stage 3 of 3: Inserting generated performance metrics...');
        
        $metricChunks = array_chunk($metricsToInsert, 500);
        $metricProgressBar = $this->command->getOutput()->createProgressBar(count($metricChunks));
        
        foreach ($metricChunks as $chunk) {
            DB::table('database_metrics')->insert($chunk);
            $metricProgressBar->advance();
        }
        
        $metricProgressBar->finish();
        $this->command->info('');
        $this->command->info('Seeding complete.');
    }
}
