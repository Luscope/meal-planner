<?php

use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('lists family members scoped to the requesting household, ordered by name', function () {
    $household = Household::factory()->create();
    $user = User::factory()->for($household)->create();
    Sanctum::actingAs($user);

    FamilyMember::factory()->for($household)->create(['name' => 'Zoe', 'daily_calorie_target' => 1800]);
    FamilyMember::factory()->for($household)->create(['name' => 'Anna', 'daily_calorie_target' => 2000]);

    $otherHousehold = Household::factory()->create();
    FamilyMember::factory()->for($otherHousehold)->create(['name' => 'Fremd']);

    $response = $this->getJson('/api/family-members');

    $response->assertOk()->assertJsonCount(2);

    expect($response->json('0.name'))->toBe('Anna')
        ->and($response->json('1.name'))->toBe('Zoe');
});
