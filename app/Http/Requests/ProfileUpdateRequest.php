<?php

namespace App\Http\Requests;

use App\Support\IdentityScope;
use App\Support\ProfileFieldRules;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                IdentityScope::uniqueEmail(IdentityScope::ofUser($this->user()->fresh()), $this->user()->id),
            ],
            'phone' => [...ProfileFieldRules::phone(), IdentityScope::uniquePhone(IdentityScope::ofUser($this->user()->fresh()), $this->user()->id)],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'username' => [
                'nullable',
                'string',
                'max:100',
                IdentityScope::uniqueUsername(IdentityScope::ofUser($this->user()->fresh()), $this->user()->id),
            ],
        ];
    }
}
