<?php

namespace App\Enums;

enum ProteinSource: string
{
    case HuhnGefluegel = 'huhn_gefluegel';
    case Rind = 'rind';
    case Schwein = 'schwein';
    case FischMeeresfruechte = 'fisch_meeresfruechte';
    case TofuSeitan = 'tofu_seitan';
    case Huelsenfruechte = 'huelsenfruechte';
    case Ei = 'ei';
    case MilchprodukteKaese = 'milchprodukte_kaese';
    case KeinHauptprotein = 'kein_hauptprotein';
}
