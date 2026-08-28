<?php

namespace App\Http\Resources;

use App\Services\Authorization\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $roleAccess = RoleAccess::detailsForRole($this->role);

        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'department' => $this->department,
            'role' => $this->role,
            'quotation_signing_authorized' => (bool) $this->quotation_signing_authorized,
            ...$roleAccess,
        ];
    }
}
