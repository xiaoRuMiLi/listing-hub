<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** 人类登录（发 Sanctum Token） */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['邮箱或密码不正确']]);
        }

        $token = $user->createToken('spa', ['*'])->plainTextToken;

        return ['ok' => true, 'data' => [
            'token' => $token,
            'abilities' => ['*'],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]];
    }

    public function me(Request $request)
    {
        $u = $request->user();

        return ['ok' => true, 'data' => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'abilities' => $u->currentAccessToken()?->abilities ?? [],
        ]];
    }

    /** 改密码（自助：需已登录 + 验证旧密码；只改自己） */
    public function changePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['当前密码不正确']]);
        }

        $user->password = $data['new_password'];   // User 的 hashed cast 会自动加密
        $user->save();

        // 吊销其它 token（保留当前这个）
        if ($current = $user->currentAccessToken()) {
            $user->tokens()->where('id', '!=', $current->id)->delete();
        }

        return ['ok' => true, 'data' => ['message' => '密码已更新，其它登录已失效']];
    }

    /** 发 Agent Token（PAT + abilities） */
    public function tokens(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'abilities' => 'array',
            'expires_at' => 'nullable|date',
        ]);

        $new = $request->user()->createToken(
            $data['name'],
            $data['abilities'] ?? ['catalog:read', 'listings:read'],
            isset($data['expires_at']) ? new \DateTime($data['expires_at']) : null,
        );

        return ['ok' => true, 'data' => [
            'id' => $new->accessToken->id,
            'name' => $data['name'],
            'abilities' => $new->accessToken->abilities,
            'plain_text_token' => $new->plainTextToken,
        ]];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ['ok' => true];
    }
}
