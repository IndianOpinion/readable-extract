<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * The kind of page the extractor believes it is looking at.
 */
enum PageType: string
{
    case Article = 'article';
    case Liveblog = 'liveblog';
    case Video = 'video';
    case Gallery = 'gallery';
    case Index = 'index';
    case Paywalled = 'paywalled';
    case ScriptRendered = 'script_rendered';
    case Wrapper = 'wrapper';
    case Empty = 'empty';
    case Unknown = 'unknown';

    /** Page types whose visible text is never returned as a body. */
    public function isNonArticle(): bool
    {
        return $this === self::Video || $this === self::Gallery || $this === self::Index;
    }
}
