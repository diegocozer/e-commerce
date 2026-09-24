<?php

declare(strict_types=1);

namespace App\Shared\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizes the limited rich text accepted in product/category descriptions
 * (ADR-024): p, br, strong, em, ul, ol, li, h2, h3, a[href], basic tables.
 * Everything else (scripts, styles, event handlers, images, iframes, classes)
 * is removed. Links only allow http(s)/mailto and get rel="noopener noreferrer".
 */
final class HtmlSanitizer
{
    public const string ALLOWED = 'p,br,strong,em,ul,ol,li,h2,h3,a[href],table,thead,tbody,tr,th,td';

    private ?HTMLPurifier $purifier = null;

    public function __construct(private readonly ?string $cachePath = null) {}

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = trim($this->purifier()->purify($html));

        return $clean === '' ? null : $clean;
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('HTML.DefinitionID', 'cv-rich-text');
        $config->set('HTML.DefinitionRev', 1);

        if ($this->cachePath !== null && (is_dir($this->cachePath) || @mkdir($this->cachePath, 0775, true))) {
            $config->set('Cache.SerializerPath', $this->cachePath);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        return $this->purifier = new HTMLPurifier($config);
    }
}
