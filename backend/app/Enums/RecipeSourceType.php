<?php

namespace App\Enums;

enum RecipeSourceType: string
{
    case Link = 'link';
    case Text = 'text';
    case Photo = 'photo';
}
