<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Document;
use App\Models\Purpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    // Note: Parallel testing in Laravel creates multiple databases.
    // Here we test if multiple submissions generate unique codes.
    use RefreshDatabase;

    protected $purpose;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        Department::create(['name' => 'Records Unit']);
        $this->purpose = Purpose::create([
            'name' => 'General Inquiry',
            'requirements' => [],
            'suggested_route' => [],
        ]);
    }

    /**
     * Test that rapid submissions generate unique tracking codes.
     */
    public function test_concurrent_submissions_have_unique_tracking_codes()
    {
        $codes = [];
        $iterations = 20; // Simulated rapid submissions

        for ($i = 0; $i < $iterations; $i++) {
            $response = $this->post(route('document.store'), [
                'guest_name' => "User $i",
                'guest_email' => "user$i@test.com",
                'district' => 'Test',
                'department' => 'Records Unit',
                'title' => "Document $i",
                'purpose_id' => $this->purpose->id,
            ]);

            $document = Document::latest('id')->first();
            $codes[] = $document->tracking_code;
        }

        // Assert all generated codes are unique
        $this->assertEquals(count($codes), count(array_unique($codes)));
    }
}
