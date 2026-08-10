<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditRequestDocument extends Model
{
    protected $table = 'credit_request_documents';

    protected $primaryKey = 'doc_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['file_bytes'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }
}
