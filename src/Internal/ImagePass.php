<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract\Internal;

/**
 * Case 36: sets every body image's src to its best real source and removes
 * images that have none. Running it twice gives the same result as once.
 *
 * @internal
 */
final class ImagePass
{
    public const NOSCRIPT_ATTR = 'data-re-noscript';

    private const DROP_ATTRS = ['srcset', 'data-srcset', 'sizes', 'loading', 'decoding', self::NOSCRIPT_ATTR];

    /**
     * @param list<string> $lazyAttributes
     */
    public function __construct(private readonly ImageRules $rules, private readonly array $lazyAttributes)
    {
    }

    /**
     * @return array{kept: int, removed: int}
     */
    public function apply(\DOMElement $root, string $base): array
    {
        $kept = 0;
        $removed = 0;
        foreach (Dom::byTag($root, 'img') as $img) {
            $extra = [];
            $parent = $img->parentNode;
            if ($parent instanceof \DOMElement && Dom::name($parent) === 'picture') {
                foreach (Dom::byTag($parent, 'source') as $source) {
                    foreach (['data-srcset', 'srcset'] as $a) {
                        if ($source->hasAttribute($a)) {
                            $extra[] = $source->getAttribute($a);
                        }
                    }
                }
            }
            $choice = $this->rules->chooseSource($img, $base, $extra);
            $url = $choice['url'];
            if ($url === null && $choice['reason'] !== 'declared_size' && $img->hasAttribute(self::NOSCRIPT_ATTR)) {
                $candidate = Url::resolve($base, $img->getAttribute(self::NOSCRIPT_ATTR));
                if ($this->rules->rejectReason($candidate) === null) {
                    $url = $candidate;
                }
            }
            if ($url === null) {
                Dom::remove($img);
                $removed++;
                continue;
            }
            $img->setAttribute('src', $url);
            foreach (array_merge(self::DROP_ATTRS, $this->lazyAttributes) as $a) {
                if ($img->hasAttribute($a)) {
                    $img->removeAttribute($a);
                }
            }
            $kept++;
        }
        return ['kept' => $kept, 'removed' => $removed];
    }
}
