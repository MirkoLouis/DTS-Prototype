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
     * Buffer for aggregating hourly database metrics during simulation.
     */
    protected array $hourlyMetricsBuffer = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have a clean slate on re-seed
        Document::query()->delete();
        DocumentLog::query()->delete();
        DB::table('database_metrics')->delete();
        DB::table('purposes')->where('name', 'System Test: Full Route')->delete();

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
            $logsToInsert = [];

            // Calculate the most recent Friday to ensure we don't seed "future" data 
            // or data from an incomplete work week.
            $referenceDate = Carbon::now();
            if (!$referenceDate->isFriday()) {
                $referenceDate->previous(Carbon::FRIDAY);
            }
            $referenceDate->setTime(17, 0, 0); // End of work day

            foreach ($documents as $document) {
                // Generate a base date: 40% chance of being in the last 30 days, 
                // 60% chance of being in the last 5 years.
                $isRecent = (mt_rand() / mt_getrandmax()) < 0.40;

                if ($isRecent) {
                    $currentTimestamp = $referenceDate->copy()
                        ->subDays(rand(0, 30))
                        ->setHour(rand(8, 16))
                        ->setMinutes(rand(0, 59))
                        ->setSeconds(rand(0, 59));
                } else {
                    $currentTimestamp = $referenceDate->copy()
                        ->subYears(rand(0, 5))
                        ->subDays(rand(0, 365))
                        ->setHour(rand(8, 16))
                        ->setMinutes(rand(0, 59))
                        ->setSeconds(rand(0, 59));
                }

                // Ensure start date is NOT on a weekend
                $this->skipWeekend($currentTimestamp, 'backward');

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

                // Set initial department
                $firstDeptName = $routeNames[0];
                $firstDept = $departments->where('name', $firstDeptName)->first();
                $document->current_department_id = $firstDept ? $firstDept->id : null;

                // ===== METRICS HELPER =====
                $generateMetrics = function($timestamp, $isPeak = false) {
                    $hourKey = $timestamp->format('Y-m-d H:00:00');
                    
                    $isBusinessHours = $timestamp->hour >= 8 && $timestamp->hour < 17 && !$timestamp->isWeekend();
                    $baseConnections = $isBusinessHours ? rand(10, 50) : rand(2, 10);
                    $connections = $isPeak ? $baseConnections + rand(20, 50) : $baseConnections;
                    $avg_query_time_ms = $isPeak ? rand(50, 200) / 10 : rand(5, 50) / 10;
                    $slow_queries = $isPeak ? rand(0, 3) : (rand(0, 100) < 2 ? 1 : 0);

                    if (!isset($this->hourlyMetricsBuffer[$hourKey])) {
                        $this->hourlyMetricsBuffer[$hourKey] = [
                            'connections' => [],
                            'avg_query_time_ms' => [],
                            'slow_queries' => 0,
                        ];
                    }

                    $this->hourlyMetricsBuffer[$hourKey]['connections'][] = $connections;
                    $this->hourlyMetricsBuffer[$hourKey]['avg_query_time_ms'][] = $avg_query_time_ms;
                    $this->hourlyMetricsBuffer[$hourKey]['slow_queries'] += $slow_queries;
                };

                // ===== START DETAILED LOGGING =====
                $previousHash = 'genesis_hash';

                // 1. Submitted Log
                $action = 'Submitted';
                $stateHash = DocumentLog::calculateStateHash($document);
                $signature = 'signed_by_guest';
                $timestampForHashing = $intakeTimestamp->copy()->startOfSecond()->toIso8601String();
                $dataToHash = $document->id . '|' . null . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                $newHash = hash('sha256', $dataToHash);
                $logsToInsert[] = ['document_id' => $document->id, 'user_id' => null, 'action' => $action, 'remarks' => 'Document submitted by guest via the public portal.', 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $intakeTimestamp->copy(), 'updated_at' => $intakeTimestamp->copy()];
                $previousHash = $newHash;
                $generateMetrics($currentTimestamp);
                $currentTimestamp->addMinutes(rand(1, 5));
                $this->skipWeekend($currentTimestamp, 'forward');

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
                    $this->skipWeekend($currentTimestamp, 'forward');
                    $action = 'Declined';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                    $dataToHash = $document->id . '|' . $recordsOfficer->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
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
                        'created_at' => $currentTimestamp->copy(), 
                        'updated_at' => $currentTimestamp->copy()
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
                $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                $dataToHash = $document->id . '|' . $recordsOfficer->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                $newHash = hash('sha256', $dataToHash);
                $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
                $previousHash = $newHash;
                $generateMetrics($currentTimestamp);

                $stepsToSimulate = $aimForReleased ? count($routeNames) : rand(0, count($routeNames));
                $actualStepsProcessed = 0;

                for ($j = 0; $j < $stepsToSimulate; $j++) {
                    $stepDepartment = $routeDepartments->get($j);
                    $stepUser = $processingUsers->where('department_id', $stepDepartment->id)->random();

                    // a. Received Log
                    $currentTimestamp->addMinutes(rand(5, $maxStepDays * 60));
                    $this->skipWeekend($currentTimestamp, 'forward');
                    $action = 'Received';
                    $remarks = 'Document received by ' . $stepDepartment->name . '.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $stepUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                    $dataToHash = $document->id . '|' . $stepUser->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp);

                    // b. Processing Complete Log
                    $currentTimestamp->addMinutes(rand(5, $maxStepDays * 120));
                    $this->skipWeekend($currentTimestamp, 'forward');
                    $action = 'Processing Complete';
                    $isFinalStep = ($j === count($routeNames) - 1);
                    $remarks = $isFinalStep 
                        ? 'Final step processed by ' . $stepDepartment->name . '. In transit to Records Unit for releasing.'
                        : 'Step processed by ' . $stepDepartment->name . '. In transit to ' . ($routeNames[$j+1] ?? 'Records Unit') . '.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $stepUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                    $dataToHash = $document->id . '|' . $stepUser->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $stepUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp);

                    if ($j === $returnTriggerStep) {
                        $requestingDepartment = $routeDepartments->get($requestingDeptIndex);
                        $requestingUser = $processingUsers->where('department_id', $requestingDepartment->id)->random();
                        $currentTimestamp->addMinutes(rand(10, 60));
                        $this->skipWeekend($currentTimestamp, 'forward');
                        $action = 'Return Requested';
                        $remarks = 'Staff member requested document be returned for corrections.';
                        $stateHash = DocumentLog::calculateStateHash($document);
                        $signature = $requestingUser->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                        $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                        $dataToHash = $document->id . '|' . $requestingUser->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                        $newHash = hash('sha256', $dataToHash);
                        $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $requestingUser->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
                        $previousHash = $newHash;
                        $generateMetrics($currentTimestamp, true);
                        
                        $currentTimestamp->addMinutes(rand(10, 120));
                        $this->skipWeekend($currentTimestamp, 'forward');
                        $action = 'Return Approved & Rerouted';
                        $remarks = 'Return request approved by Records Unit. Document rerouted back to ' . $requestingDepartment->name . '.';
                        $stateHash = DocumentLog::calculateStateHash($document);
                        $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                        $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                        $dataToHash = $document->id . '|' . $recordsOfficer->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                        $newHash = hash('sha256', $dataToHash);
                        $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
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

                    // Update current_department_id for next step
                    if ($document->current_step <= count($routeNames)) {
                        $nextDeptName = $routeNames[$document->current_step - 1];
                        $nextDept = $departments->where('name', $nextDeptName)->first();
                        $document->current_department_id = $nextDept ? $nextDept->id : null;
                    } else {
                        // Heading to Records Unit
                        $recordsUnit = $departments->where('name', 'Records Unit')->first();
                        $document->current_department_id = $recordsUnit ? $recordsUnit->id : null;
                    }

                    if ($intakeTimestamp->diffInDays($currentTimestamp) > $maxTotalDays) {
                        break;
                    }
                }

                if ($actualStepsProcessed === count($routeNames)) {
                    $currentTimestamp->addMinutes(rand(5, 60));
                    $this->skipWeekend($currentTimestamp, 'forward');
                    $action = 'Ready for Releasing';
                    $document->status = 'ready_for_release';
                    $remarks = 'All processing steps completed. Document received by Records Unit for final releasing.';
                    $stateHash = DocumentLog::calculateStateHash($document);
                    $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                    $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                    $dataToHash = $document->id . '|' . $recordsOfficer->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                    $newHash = hash('sha256', $dataToHash);
                    $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
                    $previousHash = $newHash;
                    $generateMetrics($currentTimestamp);

                    if ($aimForReleased) {
                        $currentTimestamp->addMinutes(rand(5, 120));
                        $this->skipWeekend($currentTimestamp, 'forward');
                        $action = 'Document Released';
                        $document->status = 'completed';
                        $document->current_department_id = null;
                        $document->released_at = $currentTimestamp;
                        $document->released_by_user_id = $recordsOfficer->id;
                        $remarks = 'The document has been released to the client.';
                        $stateHash = DocumentLog::calculateStateHash($document);
                        $signature = $recordsOfficer->public_key ?? base64_encode("MOCK_SIG:" . $action . "|" . $stateHash);
                        $timestampForHashing = $currentTimestamp->copy()->startOfSecond()->toIso8601String();
                        $dataToHash = $document->id . '|' . $recordsOfficer->id . '|' . $action . '|' . $timestampForHashing . '|' . $previousHash . '|' . $stateHash . '|' . $signature;
                        $newHash = hash('sha256', $dataToHash);
                        $logsToInsert[] = ['document_id' => $document->id, 'user_id' => $recordsOfficer->id, 'action' => $action, 'remarks' => $remarks, 'previous_hash' => $previousHash, 'hash' => $newHash, 'document_state_hash' => $stateHash, 'signature' => $signature, 'created_at' => $currentTimestamp->copy(), 'updated_at' => $currentTimestamp->copy()];
                        $generateMetrics($currentTimestamp);
                    }
                }

                $document->updated_at = $currentTimestamp;
                $document->save();
                $progressBar->advance();
            }

            // Flush logs for this chunk
            DocumentLog::insert($logsToInsert);
        });

        $progressBar->finish();
        $this->command->info('');
        $this->command->info('Stage 2 complete.');

        // Stage 3: Finalizing metrics and cleanup
        $this->command->info('');
        $this->command->info('Stage 3 of 3: Finalizing averaged metrics and cleanup...');
        
        $this->flushHourlyMetrics();

        $this->command->info('Seeding complete.');
    }

    /**
     * Skip weekends by moving the timestamp to Friday or Monday.
     */
    protected function skipWeekend(Carbon $timestamp, string $direction = 'forward')
    {
        if ($timestamp->isWeekend()) {
            if ($direction === 'forward') {
                $timestamp->next(Carbon::MONDAY)->setHour(rand(8, 10));
            } else {
                $timestamp->previous(Carbon::FRIDAY)->setHour(rand(15, 17));
            }
        }
        return $timestamp;
    }

    /**
     * Aggregate and insert the buffered hourly metrics.
     */
    protected function flushHourlyMetrics()
    {
        $metricsToInsert = [];
        foreach ($this->hourlyMetricsBuffer as $hourKey => $data) {
            $metricsToInsert[] = [
                'connections' => count($data['connections']) > 0 ? array_sum($data['connections']) / count($data['connections']) : 0,
                'avg_query_time_ms' => count($data['avg_query_time_ms']) > 0 ? array_sum($data['avg_query_time_ms']) / count($data['avg_query_time_ms']) : 0,
                'slow_queries' => $data['slow_queries'],
                'created_at' => $hourKey,
            ];
        }

        // Insert in chunks to avoid large packet errors
        collect($metricsToInsert)->chunk(1000)->each(function ($chunk) {
            DB::table('database_metrics')->insert($chunk->toArray());
        });

        $this->hourlyMetricsBuffer = [];
    }
}
