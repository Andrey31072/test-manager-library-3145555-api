<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiAuth
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Token requerido o invalido.', 401);
        }

        $claims = Jwt::verify(substr($header, 7));
        if ($claims === null) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Token requerido o invalido.', 401);
        }

        $user = DB::table('library.user')->where('id', $claims['sub'])->first();
        if (! $user) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Token requerido o invalido.', 401);
        }

        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            return ApiResponse::error('FORBIDDEN_ROLE', 'No tiene permisos para realizar esta accion.', 403);
        }

        $request->attributes->set('auth_user', $user);

        return $next($request);
    }
}
