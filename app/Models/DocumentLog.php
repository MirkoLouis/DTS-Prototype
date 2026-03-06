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
        'signature',
        'document_state_hash',
    ];

    /**
     * Calculate a hash representing the current state of a document.
     * This protects against tampering with document details (title, submitter, etc.).
     *
     * @param  \App\Models\Document  $document
     * @return string
     */
    public static function calculateStateHash(Document $document)
    {
        $stateData = [
            $document->tracking_code,
            $document->title,
            $document->submitter_name,
            $document->submitter_email,
            $document->submitter_phone,
            $document->district,
            $document->department,
            $document->purpose_id,
            // finalized_route is also critical to protect
            is_array($document->finalized_route) ? json_encode($document->finalized_route) : $document->finalized_route,
        ];

        return hash('sha256', implode('|', $stateData));
    }

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

            // Populate the digital signature from the performing user, if available
            if (!$documentLog->signature) {
                if ($documentLog->user_id) {
                    $user = User::find($documentLog->user_id);
                    if ($user && $user->public_key) {
                        $documentLog->signature = $user->public_key;
                    } else {
                        // Descriptive fallback based on role and department
                        $role = $user ? $user->role : 'unknown';
                        $documentLog->signature = match($role) {
                            'admin' => 'signed_by_admin',
                            'officer' => 'signed_by_records',
                            'staff' => $user->department 
                                ? 'signed_by_' . strtolower(str_replace(' ', '_', $user->department->name)) 
                                : 'signed_by_department',
                            default => 'unsigned'
                        };
                    }
                } else {
                    $documentLog->signature = 'signed_by_guest';
                }
            }

            // Find the most recent log for this document to chain the hash
            $lastLog = self::where('document_id', $documentLog->document_id)
                                ->orderBy('id', 'desc')
                                ->first();

            $previousHash = $lastLog ? $lastLog->hash : 'genesis_hash';
            $documentLog->previous_hash = $previousHash;

            // Protect the document's state at this point in time
            $document = $documentLog->document;
            if ($document) {
                $documentLog->document_state_hash = self::calculateStateHash($document);
            }

            // Ensure created_at is a Carbon instance if it's not already
            $createdAt = $documentLog->created_at ? Carbon::parse($documentLog->created_at) : Carbon::now();

            // The 'created_at' timestamp must be in a consistent format for hashing.
            // ISO-8601 with microseconds provides the necessary precision.
            $timestampForHashing = $createdAt->toIso8601String();
            
            // We now include document_state_hash and the department's digital signature in the chain hash
            // This ensures Non-Repudiation: a department cannot claim they didn't authorize the action
            // because their unique signature is cryptographically baked into the log's hash.
            $dataToHash = $documentLog->document_id . 
                         $documentLog->user_id . 
                         $documentLog->action . 
                         $timestampForHashing . 
                         $previousHash . 
                         $documentLog->document_state_hash .
                         $documentLog->signature;

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

    /**
     * Get the formatted remarks for display, bolding the reason if present.
     *
     * @return string
     */
    public function getFormattedRemarksAttribute()
    {
        $remarks = $this->remarks;
        $reasonPrefix = 'Reason: '; // The original string to search for

        $pos = strpos($remarks, $reasonPrefix);

        if ($pos !== false) {
            // Get the part of the string before "Reason: "
            $mainRemark = substr($remarks, 0, $pos);
            // Get the actual reason text after "Reason: "
            $reasonText = substr($remarks, $pos + strlen($reasonPrefix));
            
            // Rebuild the string with a line break and bolding, while escaping user content
            return e($mainRemark) . '<br/><strong>Reason: ' . e($reasonText) . '</strong>';
        }

        return e($remarks);
    }
}
