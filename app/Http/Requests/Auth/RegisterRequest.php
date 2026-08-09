<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

// 注册输入校验(allowlist)
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 用户名 3-20:字母数字下划线或中文
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', 'unique:users,username'],
            'email'    => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'phone'    => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{6,20}$/'],
        ];
    }
}
