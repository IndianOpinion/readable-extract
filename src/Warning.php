<?php

declare(strict_types=1);

namespace IndianOpinion\ReadableExtract;

/**
 * Every warning the extractor can attach to an Article. Values are stable
 * strings so callers can store and compare them.
 */
enum Warning: string
{
    case EmptyInput = 'empty_input';
    case InputTruncated = 'input_truncated';
    case WrapperUrlNotResolved = 'wrapper_url_not_resolved';
    case InvalidEncoding = 'invalid_encoding';
    case CharsetMismatch = 'charset_mismatch';
    case UnknownCharset = 'unknown_charset';
    case NoNormalizer = 'no_normalizer';
    case NotAnArticle = 'not_an_article';
    case NeedsJavascript = 'needs_javascript';
    case VisibleBodyShort = 'visible_body_short';
    case BiographyOnly = 'biography_only';
    case BodyDisagreement = 'body_disagreement';
    case NoLeadImage = 'no_lead_image';
    case BadAuthor = 'bad_author';
    case AssumedTimezone = 'assumed_timezone';
    case RelativeDate = 'relative_date';
    case ImplausibleDate = 'implausible_date';
    case ModifiedBeforePublished = 'modified_before_published';
    case CanonicalIgnored = 'canonical_ignored';
    case OwnHost = 'own_host';
    case LanguageMismatch = 'language_mismatch';
    case Paywalled = 'paywalled';
    case RobotsMeta = 'robots_meta';
    case BodyTruncated = 'body_truncated';
}
