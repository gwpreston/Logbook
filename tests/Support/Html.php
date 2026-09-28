<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Assert;

/**
 * Reads rendered pages with PHP's HTML5 parser, for tests that look at
 * forms and markup rather than strings.
 */
final class Html
{
    public static function document(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    public static function element(HTMLDocument $document, string $selector): Element
    {
        $element = $document->querySelector($selector);
        Assert::assertNotNull($element, 'no element matches ' . $selector);

        return $element;
    }

    /**
     * What a browser would submit for a form as it stands (buttons aside).
     *
     * @return array<string, string>
     */
    public static function formValues(Element $form): array
    {
        $values = [];
        foreach ($form->querySelectorAll('input[name], select[name], textarea[name]') as $control) {
            $name = (string) $control->getAttribute('name');
            $type = strtolower((string) $control->getAttribute('type'));
            if ($control->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file'], true)) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true)) {
                if ($control->hasAttribute('checked')) {
                    $values[$name] = (string) $control->getAttribute('value');
                }
                continue;
            }
            if ($control->localName === 'select') {
                $selected = $control->querySelector('option[selected]') ?? $control->querySelector('option');
                $values[$name] = (string) $selected?->getAttribute('value');
                continue;
            }
            $values[$name] = $control->localName === 'textarea'
                ? (string) $control->textContent
                : (string) $control->getAttribute('value');
        }

        return $values;
    }
}
