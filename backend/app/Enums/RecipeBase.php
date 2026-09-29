<?php

namespace App\Enums;

enum RecipeBase: string
{
    case Pasta = 'pasta';
    case Reis = 'reis';
    case Kartoffeln = 'kartoffeln';
    case Huelsenfruechte = 'huelsenfruechte';
    case BrotGebaeck = 'brot_gebaeck';
    case GetreideSonstiges = 'getreide_sonstiges';
    case Gemuese = 'gemuese';
    case Sonstiges = 'sonstiges';
}
