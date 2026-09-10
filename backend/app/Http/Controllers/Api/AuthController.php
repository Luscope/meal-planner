<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'household_name' => ['nullable', 'string', 'max:255'],
            'invite_code' => ['nullable', 'string'],
        ]);

        $household = null;

        if (! empty($validated['invite_code'])) {
            $household = Household::where('invite_code', strtoupper($validated['invite_code']))->first();

            if (! $household) {
                throw ValidationException::withMessages([
                    'invite_code' => 'Dieser Einladungscode ist ungültig.',
                ]);
            }
        }

        $user = DB::transaction(function () use ($validated, $household) {
            $household ??= Household::create([
                'name' => $validated['household_name'] ?? "{$validated['name']}s Familie",
            ]);

            $user = User::create([
                'household_id' => $household->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            FamilyMember::create([
                'household_id' => $household->id,
                'user_id' => $user->id,
                'name' => $validated['name'],
            ]);

            return $user;
        });

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('household'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Die angegebenen Zugangsdaten sind ungültig.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('household'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        // Always respond the same way whether or not the address is registered,
        // so the endpoint can't be used to check which emails have an account.
        PasswordBroker::sendResetLink(['email' => $validated['email']]);

        return response()->json([
            'message' => 'Falls zu dieser E-Mail-Adresse ein Account existiert, wurde ein Link zum Zurücksetzen des Passworts verschickt.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'Dieser Link zum Zurücksetzen des Passworts ist ungültig oder abgelaufen.',
            ]);
        }

        return response()->json([
            'message' => 'Dein Passwort wurde geändert. Bitte melde dich neu an.',
        ]);
    }
}
