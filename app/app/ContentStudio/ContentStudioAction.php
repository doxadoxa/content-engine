<?php

declare(strict_types=1);

namespace App\ContentStudio;

enum ContentStudioAction: string
{
    case Proposal = 'proposal';
    case Refine = 'refine';
    case Generate = 'generate_week';
}
