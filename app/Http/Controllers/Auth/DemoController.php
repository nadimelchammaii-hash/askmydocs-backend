<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Demo\StartDemo;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoController extends Controller
{
    /**
     * "Try the demo": create a temporary demo account with the sample documents
     * and start an authenticated session for it.
     */
    public function __invoke(Request $request, StartDemo $startDemo): JsonResponse
    {
        $user = $startDemo->handle();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
