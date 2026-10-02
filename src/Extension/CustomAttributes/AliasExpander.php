<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Extension\CustomAttributes;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use UnexpectedValueException;

/**
 * Fills an `attributeAliases` template with the value of its `x-` key (spec §7.2): `{value}` alone in a string is the
 * whole value with its type, `{value.key}` one field of it; inside longer text both become text.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class AliasExpander
{
    private const PLACEHOLDER = '/\{value(?:\.([^{}]+))?\}/';

    /**
     * @param JsonValue $template
     * @param JsonValue $value
     *
     * @return JsonValue
     *
     * @throws UnexpectedValueException with the reason when the value does not fit the template
     */
    public function expand($template, $value)
    {
        if (is_array($template)) {
            $expanded = [];
            foreach ($template as $key => $item) {
                $expanded[$key] = $this->expand(Json::value($item), $value);
            }

            return $expanded;
        }

        if (!is_string($template)) {
            return $template;
        }

        if (preg_match('/\A' . trim(self::PLACEHOLDER, '/') . '\z/', $template, $match) === 1) {
            return $this->field($value, $match[1] ?? null);
        }

        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($value): string {
            $field = $this->field($value, $match[1] ?? null);
            if (is_array($field) || $field === null) {
                throw new UnexpectedValueException('puts {value} inside text, so the value must be a string, a number or a boolean');
            }

            return is_bool($field) ? ($field ? 'true' : 'false') : (string) $field;
        }, $template);
    }

    /**
     * @param JsonValue $value
     *
     * @return JsonValue
     */
    private function field($value, ?string $key)
    {
        if ($key === null) {
            return $value;
        }

        if (!is_array($value) || !array_key_exists($key, $value)) {
            throw new UnexpectedValueException(sprintf('needs "%s" in its value', $key));
        }

        return Json::value($value[$key]);
    }
}
