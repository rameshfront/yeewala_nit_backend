<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadSession extends Model
{
    protected $table = 'upload_sessions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_chunks' => 'array',
            'total_size_bytes' => 'integer',
            'chunk_size_bytes' => 'integer',
            'total_chunks'     => 'integer',
            'expires_at'       => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'video_id');
    }
}
