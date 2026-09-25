<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Mailtrap\DTO;

/**
 * How an email-log filter criterion compares.
 *
 * `Empty` and `NotEmpty` take NO value — {@see FilterCriterion::withoutValue()}.
 * Sending one anyway is a 422, so the two constructors are separate rather than
 * a nullable argument.
 */
enum EmailLogsOperator: string
{
    case CiEqual         = 'ci_equal';
    case CiNotEqual      = 'ci_not_equal';
    case CiContain       = 'ci_contain';
    case CiNotContain    = 'ci_not_contain';
    case IsEmpty         = 'empty';
    case IsNotEmpty      = 'not_empty';
    case Equal           = 'equal';
    case NotEqual        = 'not_equal';
    case IncludeEvent    = 'include_event';
    case NotIncludeEvent = 'not_include_event';
    case GreaterThan     = 'greater_than';
    case LessThan        = 'less_than';
    case Contain         = 'contain';
    case NotContain      = 'not_contain';
}
