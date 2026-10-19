<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domaine extends Model
{
    protected $fillable = [
        'code',
        'nom',
        'self_registration_enabled',
        'active_identities_url',
        'email_validate_url',
        'mail_from',
        'mail_name',
        'test_account_domain_suffix',
    ];

    protected function casts(): array
    {
        return [
            'self_registration_enabled' => 'boolean',
        ];
    }
}
