<?php

namespace App\Actions\Fortify;

use App\Models\Office;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'nrp' => [
                'required',
                'string',
                'max:50',
                Rule::unique(User::class),
            ],
            'phone' => [
                'required',
                'string',
                'min:10',
                'max:13',
                'regex:/^08[0-9]{8,11}$/',
                Rule::unique(User::class),
            ],
            'office_id' => [
                'nullable',
                'integer',
                Rule::exists(Office::class, 'id'),
            ],
            'password' => $this->passwordRules(),
        ], [
            'phone.regex' => 'Phone number must start with 08 and contain 10-13 digits.',
            'phone.unique' => 'This phone number is already registered.',
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'] ?? null,
            'nrp' => $input['nrp'],
            'phone' => $input['phone'],
            'office_id' => $input['office_id'],
            'password' => $input['password'],
            'is_approved' => false, // New users need approval
        ]);
    }
}
