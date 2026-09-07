<?php

namespace App\Enums;

enum RecipeCategory: string
{
    case Appetizer = 'appetizer';
    case MainCourse = 'main_course';
    case SideDish = 'side_dish';
    case Dessert = 'dessert';
    case Snack = 'snack';
    case Drink = 'drink';
}
