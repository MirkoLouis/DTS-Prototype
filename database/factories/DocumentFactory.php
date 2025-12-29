<?php

namespace Database\Factories;

use App\Models\Purpose;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $guestName = fake()->name();
        $guestEmail = fake()->safeEmail();

        // Use the same tracking code algorithm as the GuestController for consistency
        $dataForHash = time() . $guestName . $guestEmail . rand(); // Add rand for uniqueness in loops
        $trackingCode = 'DEPED-' . strtoupper(substr(sha1($dataForHash), 0, 10));

        return [
            'tracking_code' => $trackingCode,
            'guest_info' => [
                'name' => $guestName,
                'email' => $guestEmail,
            ],
            // Select a random official purpose for the document
            'purpose_id' => Purpose::where('is_official', true)->inRandomOrder()->first()->id,
            'status' => 'pending', // Default status
        ];
    }
}