<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DocumentRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected $officer;
    protected $staff;
    protected $recordsDept;
    protected $cashDept;
    protected $purpose;
    protected $testPin = '123456';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutExceptionHandling();
        
        // 1. Intercept background jobs
        Queue::fake();

        // 2. Disable CSRF for all tests in this file
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        
        // 3. Setup Baseline Database State
        $this->recordsDept = Department::create(['name' => 'Records Unit']);
        $this->cashDept = Department::create(['name' => 'Cash Unit']);
        
        $this->purpose = Purpose::create([
            'name' => 'General Inquiry',
            'requirements' => ['Valid ID'],
            'suggested_route' => ['Cash Unit'],
            'is_official' => true,
        ]);

        $this->officer = User::factory()->create([
            'role' => 'officer',
            'department_id' => $this->recordsDept->id,
            'email' => 'officer_routes@test.com'
        ]);

        $this->staff = User::factory()->create([
            'role' => 'staff',
            'department_id' => $this->cashDept->id,
            'email' => 'staff_routes@test.com'
        ]);

        // Initialize digital signatures for testing
        $this->initializeUserSignature($this->officer);
        $this->initializeUserSignature($this->staff);
    }

    protected function initializeUserSignature(User $user)
    {
        $keypair = sodium_crypto_sign_keypair();
        $salt = substr(hash('sha256', $user->email, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $encryptionKey = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $this->testPin, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encryptedPrivateKey = sodium_crypto_secretbox(sodium_crypto_sign_secretkey($keypair), $nonce, $encryptionKey);
        
        $user->update([
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'private_key' => base64_encode($nonce . $encryptedPrivateKey),
            'security_key_set_at' => now(),
        ]);
    }

    /**
     * Helper to create a document with all required fields and a genesis log.
     */
    protected function createSeededDocument($overrides = [])
    {
        $document = Document::create(array_merge([
            'tracking_code' => 'DEPED-' . strtoupper(bin2hex(random_bytes(4))),
            'title' => 'Test Document',
            'guest_info' => ['name' => 'John', 'email' => 'john@test.com'],
            'status' => 'pending',
            'purpose_id' => $this->purpose->id,
            'district' => 'Test District',
            'department' => 'Records Unit',
        ], $overrides));

        DocumentLog::create([
            'document_id' => $document->id,
            'action' => 'Submitted',
            'remarks' => 'Genesis',
        ]);

        return $document;
    }

    /**
     * ROUTE TEST 1: Guest Submission
     */
    public function test_guest_submission_route_creates_document()
    {
        $payload = [
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'guest_phone' => '09123456789',
            'district' => 'East I District',
            'department' => 'Records Unit',
            'title' => 'Test Document',
            'purpose_id' => $this->purpose->id,
        ];

        $response = $this->post(route('document.store'), $payload);

        $this->assertDatabaseHas('documents', [
            'title' => 'Test Document',
            'status' => 'pending'
        ]);

        $document = Document::first();

        $response->assertRedirect(route('success', [
            'tracking_code' => $document->tracking_code, 
            'document_id' => $document->id
        ]));
    }

    /**
     * ROUTE TEST 2: Officer Finalizes Intake
     */
    public function test_officer_intake_route_accepts_and_routes_document()
    {
        $document = $this->createSeededDocument(['status' => 'pending']);

        $response = $this->actingAs($this->officer)->post(route('documents.finalize', $document), [
            'final_route' => json_encode(['Cash Unit']),
            'pin' => $this->testPin,
        ]);

        $response->assertRedirect(route('intake'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'in_transit',
            'current_step' => 1,
        ]);
    }

    /**
     * ROUTE TEST 3: Staff Scans/Receives Document
     */
    public function test_staff_scan_route_receives_document()
    {
        $document = $this->createSeededDocument([
            'status' => 'in_transit',
            'current_step' => 1,
            'finalized_route' => [['name' => 'Cash Unit', 'type' => 'initial']],
        ]);

        $response = $this->actingAs($this->staff)->post(route('documents.scan'), [
            'tracking_code' => $document->tracking_code,
        ]);

        $response->assertRedirect(route('staff.tasks'));
        
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'processing',
            'current_department_id' => $this->cashDept->id,
        ]);
    }

    /**
     * ROUTE TEST 4: Security Rule (Unauthorized Scan)
     */
    public function test_staff_cannot_scan_document_intended_for_another_department()
    {
        $document = $this->createSeededDocument([
            'status' => 'in_transit',
            'current_step' => 1,
            'finalized_route' => [['name' => 'Records Unit', 'type' => 'initial']],
        ]);

        $response = $this->actingAs($this->staff)->post(route('documents.scan'), [
            'tracking_code' => $document->tracking_code,
        ]);

        $response->assertSessionHas('error');
        
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'in_transit',
        ]);
    }

    /**
     * ROUTE TEST 5: Staff Completes Task
     */
    public function test_staff_complete_task_route_moves_to_next_step()
    {
        $document = $this->createSeededDocument([
            'status' => 'processing',
            'current_step' => 1,
            'current_department_id' => $this->cashDept->id,
            'finalized_route' => [['name' => 'Cash Unit', 'type' => 'initial']],
        ]);

        $response = $this->actingAs($this->staff)->post(route('staff.tasks.complete', $document), [
            'pin' => $this->testPin,
        ]);

        $response->assertRedirect(route('staff.tasks'));
        
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'ready_for_release',
        ]);
    }

    /**
     * ROUTE TEST 6: Officer Requests Return
     */
    public function test_return_request_route_injects_return_step()
    {
        $document = $this->createSeededDocument([
            'status' => 'processing',
            'current_step' => 1,
            'current_department_id' => $this->cashDept->id,
            'finalized_route' => [['name' => 'Cash Unit', 'type' => 'initial']],
        ]);

        $response = $this->actingAs($this->officer)->post(route('return-requests.store'), [
            'tracking_code' => $document->tracking_code,
            'reason' => 'Need more info',
        ]);

        $response->assertRedirect();
        
        $document->refresh();
        $this->assertEquals('in_transit', $document->status);
        $this->assertCount(2, $document->finalized_route);
    }

    /**
     * ROUTE TEST 7: Final Releasing
     */
    public function test_releasing_route_completes_document()
    {
        $document = $this->createSeededDocument(['status' => 'ready_for_release']);

        $response = $this->actingAs($this->officer)->post(route('releasing.complete', $document), [
            'pin' => $this->testPin,
        ]);

        $response->assertRedirect(route('releasing'));
        
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'status' => 'completed',
            'released_by_user_id' => $this->officer->id,
        ]);
    }
}
