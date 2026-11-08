<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TesterProjectAccess extends Model
{
    protected $table = 'tester_project_access';

    protected $fillable = ['tester_invitation_id', 'project_id'];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(TesterInvitation::class, 'tester_invitation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
