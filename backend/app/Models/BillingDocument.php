<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['file_bytes'])]
class BillingDocument extends Model
{
    protected $table = 'billing_documents';
    protected $primaryKey = 'doc_id';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'superseded_at' => 'datetime', 'is_current' => 'boolean'];
    }
}
