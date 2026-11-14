<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

enum AuthorRole: string
{
    case Author = 'author';
    case Editor = 'editor';
}
