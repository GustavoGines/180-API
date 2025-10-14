<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // la ruta tendrá 'can:manage-users', así que acá true
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required','string','max:100'],
            'email'    => ['required','email','max:150','unique:users,email'],
            'password' => ['required','string','min:8','confirmed'], // requiere password_confirmation
            'role'     => ['required','in:admin,staff'],
        ];
    }
}
