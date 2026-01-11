<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    public function register(array $data)
    {
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => bcrypt($data['password']),
        ]);

        $token = auth('api')->login($user);

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    public function login(array $credentials)
    {
        if (! $token = auth('api')->attempt($credentials)) {
            return null;
        }

        return [
            'token'       => $token,
            'token_type'  => 'bearer',
            'expires_in'  => auth('api')->factory()->getTTL() * 60,
        ];
    }


    public function profile()
    {
        return auth()->user();
    }

    public function logout()
    {
        auth()->logout();
        return true;
    }
}
