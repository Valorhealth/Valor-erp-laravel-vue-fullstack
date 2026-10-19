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
        $roleAccess = RoleAccess::detailsForUser($this->resource);
        $baseAccess = RoleAccess::detailsForRole($this->role);

        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'representative_address' => $this->representative_address,
            'national_id' => $this->national_id,
            'national_id_issued_at' => $this->national_id_issued_at?->format('Y-m-d'),
            'national_id_expires_at' => $this->national_id_expires_at?->format('Y-m-d'),
            'department' => $this->department,
            'salesman_id' => $this->salesman_id,
            'salesman' => $this->whenLoaded('salesman', fn (): ?array => $this->salesman ? [
                'id' => $this->salesman->id,
                'salesman_code' => $this->salesman->salesman_code,
                'salesman_name' => $this->salesman->salesman_name,
            ] : null),
            'role' => $this->role,
            'quotation_signing_authorized' => (bool) $this->quotation_signing_authorized,
            'has_signature' => (bool) ($this->has_signature ?? false),
            'permission_overrides' => (object) ($this->permission_overrides ?? []),
            'preferences' => (object) ($this->preferences ?? []),
            'base_abilities' => $baseAccess['abilities'],
            ...$roleAccess,
        ];
    }
}
