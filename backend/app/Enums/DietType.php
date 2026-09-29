<?php

namespace App\Enums;

enum DietType: string
{
    case Omnivore = 'omnivore';
    case Pescetarian = 'pescetarian';
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';
}
