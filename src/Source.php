<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * Where a field's value came from.
 */
enum Source: string
{
    case JsonLd = 'json_ld';
    case Microdata = 'microdata';
    case Meta = 'meta';
    case Visible = 'visible';
    case None = 'none';
}
