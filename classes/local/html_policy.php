<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ltiaccountmerge\local;

/**
 * Deliberately small HTML allow-list for admin-configurable texts (AR-P01).
 *
 * Validation REJECTS (it does not silently repair) anything outside the allow-list, so an editor sees why their
 * input was refused. Output is additionally passed through format_text() (HTML Purifier) as defence in depth.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class html_policy {
    /** @var string[][] Allowed elements and their allowed attributes. */
    protected const ELEMENTS = [
        'p' => ['lang', 'dir'],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h3' => [],
        'h4' => [],
        'span' => ['lang', 'dir'],
        'code' => [],
        'blockquote' => [],
        'a' => ['href', 'title'],
    ];
    /** @var string[] Allowed URL schemes for links. */
    protected const SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Find everything that violates the policy.
     *
     * @param string $html The HTML fragment.
     * @return string[] Human-readable (English, technical) problems; empty when acceptable.
     */
    public static function problems(string $html): array {
        if (trim($html) === '') {
            return [];
        }
        $problems = [];
        // Parser-independent pre-check on the raw text: every tag name must be on the allow-list and no comment,
        // doctype, processing instruction or CDATA may appear. HTML parsers disagree about where unexpected tags
        // such as <body> or <meta> end up, so the DOM walk below alone is not enough.
        if (preg_match_all('~<\s*/?\s*([a-z][a-z0-9:-]*)~i', $html, $tags)) {
            foreach ($tags[1] as $name) {
                if (!isset(self::ELEMENTS[strtolower($name)])) {
                    $problems[] = 'element:' . strtolower($name);
                }
            }
        }
        if (preg_match('~<\s*[!?]~', $html)) {
            $problems[] = 'node:markup-declaration';
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body><div id="ltiam-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('ltiam-root');
        if (!$root) {
            return ['unparsable'];
        }
        self::walk($root, $problems);
        return array_values(array_unique($problems));
    }

    /**
     * Recursive check.
     *
     * @param \DOMNode $node Node whose children are checked.
     * @param string[] $problems Collected problems.
     */
    protected static function walk(\DOMNode $node, array &$problems): void {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                $problems[] = 'node:' . $child->nodeName;
                continue;
            }
            $name = strtolower($child->nodeName);
            if (!isset(self::ELEMENTS[$name])) {
                $problems[] = 'element:' . $name;
                continue;
            }
            foreach ($child->attributes as $attr) {
                $attrname = strtolower($attr->nodeName);
                if (!in_array($attrname, self::ELEMENTS[$name], true)) {
                    $problems[] = 'attribute:' . $name . '/' . $attrname;
                } else if ($attrname === 'href' && !self::url_allowed($attr->nodeValue)) {
                    $problems[] = 'url:' . $name;
                }
            }
            self::walk($child, $problems);
        }
    }

    /**
     * Whether a link target is acceptable: http(s), mailto, or a relative/fragment reference.
     *
     * @param string $url The attribute value.
     * @return bool
     */
    protected static function url_allowed(string $url): bool {
        // Browsers ignore whitespace/control characters inside the scheme ("java\tscript:").
        $compact = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($compact === '') {
            return false;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $compact, $m)) {
            return in_array(strtolower($m[1]), self::SCHEMES, true);
        }
        // Relative: no protocol-relative URLs.
        return strpos($compact, '//') !== 0;
    }

    /**
     * Render an accepted text for output.
     *
     * @param string $html Stored HTML.
     * @return string Safe HTML.
     */
    public static function render(string $html): string {
        return format_text($html, FORMAT_HTML, ['context' => \context_system::instance(), 'noclean' => false,
            'para' => false]);
    }
}
