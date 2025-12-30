<?php

namespace App\Jobs;

use App\Models\Department;
use App\Models\PredictionKeyword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class UpdateKeywordWeights implements ShouldQueue
{
    use Queueable;

    protected $purposeText;
    protected $finalizedRoute;

    /**
     * Create a new job instance.
     *
     * @param string $purposeText
     * @param array $finalizedRoute
     */
    public function __construct(string $purposeText, array $finalizedRoute)
    {
        $this->purposeText = $purposeText;
        $this->finalizedRoute = $finalizedRoute;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Tokenize the purpose text
        $tokens = preg_split('/[\s,.;]+/', strtolower($this->purposeText), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($tokens) || empty($this->finalizedRoute)) {
            return;
        }

        // Get the department models for the finalized route
        $departments = Department::whereIn('name', $this->finalizedRoute)->pluck('id', 'name');

        foreach ($this->finalizedRoute as $departmentName) {
            if (isset($departments[$departmentName])) {
                $departmentId = $departments[$departmentName];

                foreach ($tokens as $token) {
                    // Find or create the keyword entry and increment its weight
                    $keyword = PredictionKeyword::firstOrCreate(
                        [
                            'keyword' => $token,
                            'department_id' => $departmentId,
                        ]
                    );
                    $keyword->increment('weight');
                }
            }
        }
    }
}
