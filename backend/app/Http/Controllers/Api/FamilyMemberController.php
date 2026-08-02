<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FamilyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FamilyMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $familyMembers = FamilyMember::query()
            ->where('household_id', $request->user()->household_id)
            ->orderBy('name')
            ->get(['id', 'name', 'daily_calorie_target', 'daily_protein_target_g']);

        return response()->json($familyMembers);
    }
}
