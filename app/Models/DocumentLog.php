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
            // Find the most recent log for this document to chain the hash
            $lastLog = self::where('document_id', $documentLog->document_id)
                                ->orderBy('id', 'desc')
                                ->first();

            // Determine the previous hash, using a genesis hash for the first entry
            $previousHash = $lastLog ? $lastLog->hash : 'genesis_hash';
            $documentLog->previous_hash = $previousHash;

            // Create the data string for the new hash. Using a consistent timestamp is crucial.
            $timestamp = Carbon::now()->toIso8601String();
            $dataToHash = $documentLog->document_id . $documentLog->user_id . $documentLog->action . $timestamp . $previousHash;

            // Calculate and set the new hash
            $documentLog->hash = Hash::make($dataToHash);
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
