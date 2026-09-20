<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** 用户账号管理（列表 / 新增 / 删除） */
class UserController extends Controller
{
    public function index()
    {
        return ['ok' => true, 'data' => User::orderBy('id')->get(['id', 'name', 'email', 'created_at'])];
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'name' => 'required|string|max:64',
            'email' => 'required|email|max:191|unique:users,email',
            'password' => 'required|string|min:8',
        ]);
        $u = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return ['ok' => true, 'data' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]];
    }

    public function destroy(Request $r, $id)
    {
        if (String($r->user()->id) === String($id)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => '不能删除自己']], 400);
        }
        User::findOrFail($id)->delete();

        return ['ok' => true];
    }
}
