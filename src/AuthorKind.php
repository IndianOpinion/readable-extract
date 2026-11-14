<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

enum AuthorKind: string
{
    case Person = 'person';
    case Organisation = 'organisation';
    case Agency = 'agency';
}
