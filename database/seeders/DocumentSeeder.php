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
        
        $maxTotalDays = 14;
        $maxStepDays = 3;

        // Process in chunks to avoid memory issues and long transactions
        Document::chunk(200, function ($documents) use ($recordsOfficer, $processingUsers, $departments, $maxTotalDays, $maxStepDays, $progressBar) {
            $metricsToInsert = [];
            $logsToInsert = [];

            foreach ($documents as $document) {
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
                    $baseConnections = $isBusinessHours ? rand(10, 50) : rand(2, 10);
                    $connections = $isPeak ? $baseConnections + rand(20, 50) : $baseConnections;
                    $avg_query_time_ms = $isPeak ? rand(50, 200) / 10 : rand(5, 50) / 10;
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

                // 1. Submitted Log
                $action = 'Submitted';
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = 'signed_by_guest';
                $dataToHash = $document->id . ($document->user_id ?? '') . $action . $intakeTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                $newHash = hash('sha256', $dataToHash);
                $logsToInsert[] = ['document_id' => $document->id, 'user_id' => null, 'action' => $action, 'remarks' => 'Document submitted by guest via the public portal.', 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $intakeTimestamp, 'updated_at' => $intakeTimestamp];
                $previousHash = $newHash;
                $generateMetrics($currentTimestamp);
                $currentTimestamp->addMinutes(rand(1, 5));

                // --- START: Decline Simulation (1% chance) ---
                if ((mt_rand() / mt_getrandmax()) < 0.01) {
                    $declineReasons = [
                        "Incomplete or missing required documents.",
                        "Submitted to the wrong office/department.",
                        "Form is an outdated or incorrect version.",
                        "Information provided is unreadable or illegible.",
                        "Purpose of request is unclear or not specified."
                    ];
                    $reason = $declineReasons[array_rand($declineReasons)];
                    $currentTimestamp->addMinutes(rand(5, 30));
                    $action = 'Declined';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = [
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
                    ];
                    $document->status = 'declined';
                    $document->decline_reason = $reason;
                    $document->declined_at = $currentTimestamp;
                    $document->updated_at = $currentTimestamp;
                    $generateMetrics($currentTimestamp, true);
                    $document->save();
                    $progressBar->advance();
                    continue; 
                }
                // --- END: Decline Simulation ---

                // 2. Accepted Log
                $action = 'Accepted and Document Routing finalized';
                $remarks = 'Route finalized. In transit to ' . $routeNames[0] . '.';
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                $newHash = hash('sha256', $dataToHash);
                $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                $previousHash = $newHash;
                $generateMetrics($currentTimestamp);

                $stepsToSimulate = $aimForReleased ? count($routeNames) : rand(0, count($routeNames));
                $actualStepsProcessed = 0;

                for ($j = 0; $j < $stepsToSimulate; $j++) {
                    $stepDepartment = $routeDepartments->get($j);
                    $stepUser = $processingUsers->where('department_id', $stepDepartment->id)->random();

                    // a. Received Log
                    $currentTimestamp->addMinutes(rand(5, $maxStepDays * 60));
                    $action = 'Received';
                    $remarks = 'Document received by ' . $stepDepartment->name . '.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $stepUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $stepUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp);

                    // b. Processing Complete Log
                    $currentTimestamp->addMinutes(rand(5, $maxStepDays * 120));
                    $action = 'Processing Complete';
                    $isFinalStep = ($j === count($routeNames) - 1);
                    $remarks = $isFinalStep 
                        ? 'Final step processed by ' . $stepDepartment->name . '. In transit to Records Unit for releasing.'
                        : 'Step processed by ' . $stepDepartment->name . '. In transit to ' . ($routeNames[$j+1] ?? 'Records Unit') . '.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $stepUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $stepUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp);

                    if ($j === $returnTriggerStep) {
                        $requestingDepartment = $routeDepartments->get($requestingDeptIndex);
                        $requestingUser = $processingUsers->where('department_id', $requestingDepartment->id)->random();
                        $currentTimestamp->addMinutes(rand(10, 60));
                        $action = 'Return Requested';
                        $remarks = 'Staff member requested document be returned for corrections.';
                        $stateHash = DocumentLog::calculateStateHash($document);
                        $signature = $requestingUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                        $dataToHash = $document->id . $requestingUser->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                        $newHash = hash('sha256', $dataToHash);
                        $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $requestingUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                        $previousHash = $newHash;
                        $generateMetrics($currentTimestamp, true);
                        
                        $currentTimestamp->addMinutes(rand(10, 120));
                        $action = 'Return Approved & Rerouted';
                        $remarks = 'Return request approved by Records Unit. Document rerouted back to ' . $requestingDepartment->name . '.';
                        $stateHash = DocumentLog::calculateStateHash($document);
                        $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                        $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                        $newHash = hash('sha256', $dataToHash);
                        $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                        $previousHash = $newHash;
                        $generateMetrics($currentTimestamp, true);

                        $reroutedStep = ['name' => $requestingDepartment->name, 'type' => 'rerouted'];
                        array_splice($finalizedRoute, $j + 1, 0, [$reroutedStep]);
                        $routeDepartments->splice($j + 1, 0, [$requestingDepartment]);
                        $routeNames = $routeDepartments->pluck('name')->toArray();
                        $stepsToSimulate = count($routeNames);
                        $document->finalized_route = $finalizedRoute;
                        $returnTriggerStep = -1;
                    }
                    
                    $actualStepsProcessed++;
                    $document->current_step = $j + 2;

                    if ($intakeTimestamp->diffInDays($currentTimestamp) > $maxTotalDays) {
                        break;
                    }
                }

                if ($actualStepsProcessed === count($routeNames)) {
                    $currentTimestamp->addMinutes(rand(5, 60));
                    $action = 'Ready for Releasing';
                    $document->status = 'ready_for_release';
                    $remarks = 'All processing steps completed. Document received by Records Unit for final releasing.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp);

                    if ($aimForReleased) {
                        $currentTimestamp->addMinutes(rand(5, 120));
                        $action = 'Document Released';
                        $document->status = 'completed';
                        $remarks = 'The document has been released to the client.';
                        $stateHash = DocumentLog::calculateStateHash($document);
                        $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                        $dataToHash = $document->id . $recordsOfficer->id . $action . $currentTimestamp->toIso8601String() . $previousHash . $stateHash . $signature;
                        $newHash = hash('sha256', $dataToHash);
                        $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp, 'updated_at' => $currentTimestamp];
                        $generateMetrics($currentTimestamp);
                    }
                }

                $document->updated_at = $currentTimestamp;
                $document->save();
                $progressBar->advance();
            }

            // Flush logs and metrics for this chunk
            DocumentLog::insert($logsToInsert);
            DB::table('database_metrics')->insert($metricsToInsert);
        });

        $progressBar->finish();
        $this->command->info('');
        $this->command->info('Stage 2 complete.');

        // Stage 3: Already integrated into Stage 2 for efficiency
        $this->command->info('');
        $this->command->info('Stage 3 of 3: Finalizing metrics and cleanup...');
        $this->command->info('Seeding complete.');
    }
}
