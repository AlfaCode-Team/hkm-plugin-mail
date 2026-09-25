<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/** Whether a sending domain's company info describes a business or a person. */
enum CompanyInfoLevel: string
{
    case Business   = 'business';
    case Individual = 'individual';
}
