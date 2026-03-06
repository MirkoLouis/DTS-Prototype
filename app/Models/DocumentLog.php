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
     * Verify the current live state of a document against its latest log entry.
     * This provides a "pre-action" integrity check.
     *
     * @param  \App\Models\Document  $document
     * @return bool
     */
    public static function verifyCurrentState(Document $document)
    {
        $latestLog = $document->logs()->orderBy('id', 'desc')->first();
        
        if (!$latestLog) {
            return true; // No logs yet (pending intake)
        }

        $currentStateHash = self::calculateStateHash($document);
        
        return $currentStateHash === $latestLog->document_state_hash;
    }

    /**
     * Generate an Ed25519 signature for the given data using the user's PIN.
     * This ensures non-repudiation: only the person with the PIN can authorize the action.
     *
     * @param  \App\Models\User  $user
     * @param  string  $pin
     * @param  string  $dataToSign
     * @return string|null
     */
    public static function signAction(User $user, string $pin, string $dataToSign)
    {
        if (!$user->private_key) {
            return null;
        }

        try {
            // 1. Derive the encryption key from the PIN
            $salt = substr(hash('sha256', $user->email, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $encryptionKey = sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                $pin,
                $salt,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
            );

            // 2. Decrypt the private key
            $encryptedData = base64_decode($user->private_key);
            $nonce = substr($encryptedData, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($encryptedData, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            
            $privateKey = sodium_crypto_secretbox_open($ciphertext, $nonce, $encryptionKey);

            if ($privateKey === false) {
                return false; // Wrong PIN
            }

            // 3. Sign the data (using the decrypted Ed25519 secret key)
            $signature = sodium_crypto_sign_detached($dataToSign, $privateKey);
            
            return base64_encode($signature);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Signing error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify an Ed25519 signature against the data and the user's public key.
     *
     * @param  string  $signatureBase64
     * @param  string  $data
     * @param  string  $publicKeyBase64
     * @return bool
     */
    public static function verifySignature(string $signatureBase64, string $data, string $publicKeyBase64)
    {
        try {
            $signature = base64_decode($signatureBase64);
            $publicKey = base64_decode($publicKeyBase64);
            
            return sodium_crypto_sign_verify_detached($signature, $data, $publicKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * The "booted" method of the model.
     * This ensures the hash is calculated every time a log is created.
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
            // Note: For real Ed25519 signatures, the 'signature' should be provided 
            // BEFORE calling create() in the controller. If not provided, we fall back.
            if (!$documentLog->signature) {
                if ($documentLog->user_id) {
                    $user = User::find($documentLog->user_id);
                    if ($user && $user->public_key) {
                        // If user has a key initialized but no signature was passed,
                        // it means the controller didn't use signAction().
                        // For the prototype, we use the public key as a fallback string 
                        // if a cryptographic signature wasn't provided yet.
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
            $timestampForHashing = $createdAt->toIso8601String();
            
            // We now include document_state_hash and the department's signature in the chain hash
            $dataToHash = $documentLog->document_id . 
                         $documentLog->user_id . 
                         $documentLog->action . 
                         $timestampForHashing . 
                         $previousHash . 
                         $documentLog->document_state_hash .
                         $documentLog->signature;

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
