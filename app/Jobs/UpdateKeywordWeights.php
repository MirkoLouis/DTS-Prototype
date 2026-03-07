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
        // Tokenize and clean the purpose text
        $rawTokens = preg_split('/[\s,.;]+/', strtolower($this->purposeText), -1, PREG_SPLIT_NO_EMPTY);
        
        // Define stopwords and placeholders to ignore
        $stopWords = ['the', 'and', 'for', 'with', 'n/a', 'na', 'not', 'applicable', 'this', 'that'];
        
        $tokens = array_filter($rawTokens, function($token) use ($stopWords) {
            return strlen($token) > 2 && !in_array($token, $stopWords);
        });

        if (empty($tokens) || empty($this->finalizedRoute)) {
            return;
        }

        // Get the department models for the finalized route
        $departments = Department::whereIn('name', $this->finalizedRoute)->pluck('id', 'name');

        // We only increment document_count once per keyword per handle() call
        // to avoid double-counting if the same department appears multiple times in a route
        $processedKeywordsPerDept = [];

        foreach ($this->finalizedRoute as $departmentName) {
            if (isset($departments[$departmentName])) {
                $departmentId = $departments[$departmentName];

                if (!isset($processedKeywordsPerDept[$departmentId])) {
                    $processedKeywordsPerDept[$departmentId] = [];
                }

                foreach ($tokens as $token) {
                    // Find or create the keyword entry
                    $keyword = PredictionKeyword::firstOrCreate(
                        [
                            'keyword' => $token,
                            'department_id' => $departmentId,
                        ]
                    );
                    
                    // Increment absolute weight (frequency of correction)
                    $keyword->increment('weight');

                    // Increment document_count (for IDF) only once per document per department
                    if (!in_array($token, $processedKeywordsPerDept[$departmentId])) {
                        $keyword->increment('document_count');
                        $processedKeywordsPerDept[$departmentId][] = $token;
                    }
                }
            }
        }
    }
}
