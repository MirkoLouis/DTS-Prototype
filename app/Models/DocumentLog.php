<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class DocumentLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'document_id',
        'user_id',
        'action',
        'remarks',
        'previous_hash',
        'hash',
    ];

    /**
     * The "booted" method of the model.
     * This ensures the hash is calculated every time a log is created,
     * making it more robust than an observer which can be disabled.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($documentLog) {
            // Do not recalculate if a hash is already being set (e.g., during seeding)
            if ($documentLog->hash) {
                return;
            }

            // Find the most recent log for this document to chain the hash
            $lastLog = self::where('document_id', $documentLog->document_id)
                                ->orderBy('id', 'desc')
                                ->first();

            $previousHash = $lastLog ? $lastLog->hash : 'genesis_hash';
            $documentLog->previous_hash = $previousHash;

            // Ensure created_at is a Carbon instance if it's not already
            $createdAt = $documentLog->created_at ? Carbon::parse($documentLog->created_at) : Carbon::now();

            // The 'created_at' timestamp must be in a consistent format for hashing.
            // ISO-8601 with microseconds provides the necessary precision.
            $timestampForHashing = $createdAt->toIso8601String();
            
            $dataToHash = $documentLog->document_id . $documentLog->user_id . $documentLog->action . $timestampForHashing . $previousHash;

            // Use a simple SHA256 hash, not bcrypt, to ensure it can be re-calculated for verification.
            $documentLog->hash = hash('sha256', $dataToHash);
        });
    }


    /**
     * Get the document that the log belongs to.
     */
    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the user that performed the action.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
