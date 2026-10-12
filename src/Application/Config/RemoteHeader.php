<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * A header sent with the fetch of every remote document under an https prefix (spec F3 §2). Its value is a template:
 * `${NAME}` stands for an environment variable, read only when a document is fetched, so no secret lives in the config.
 */
final class RemoteHeader
{
    private const VARIABLE = '#\$\{([A-Za-z_][A-Za-z0-9_]*)\}#';

    private const OWN = ['host', 'connection', 'content-length', 'transfer-encoding'];

    private string $prefix;

    private string $name;

    private string $template;

    /**
     * @param string $prefix a normalized https URL
     *
     * @throws InvalidArgumentException for a header the generator cannot send
     */
    public function __construct(string $prefix, string $name, string $template)
    {
        if (strncmp($prefix, 'https://', 8) !== 0 || !$this->isNormalized($prefix)) {
            throw new InvalidArgumentException(sprintf('A header is sent only over https, not to "%s".', $prefix));
        }

        if (preg_match('#^[!\#$%&\'*+.^_`|~0-9A-Za-z-]+\z#', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('A header name must be an HTTP token, not "%s".', $name));
        }

        $lower = strtolower($name);
        if (in_array($lower, self::OWN, true) || strncmp($lower, 'proxy-', 6) === 0) {
            throw new InvalidArgumentException(sprintf('The header %s is written by the generator itself.', $name));
        }

        if (strpos((string) preg_replace(self::VARIABLE, '', $template), '${') !== false) {
            throw new InvalidArgumentException(sprintf('The value of the header %s has a "${" that does not start a variable such as ${NAME}.', $name));
        }

        if (self::hasControlCharacters($template)) {
            throw new InvalidArgumentException(sprintf('The value of the header %s must not hold control characters.', $name));
        }

        $this->prefix = $prefix;
        $this->name = $name;
        $this->template = $template;
    }

    /**
     * A tab is allowed in a field value (RFC 9110 §5.5), line breaks would end it.
     */
    public static function hasControlCharacters(string $value): bool
    {
        return preg_match('#[\x00-\x08\x0A-\x1F\x7F]#', $value) === 1;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string> the environment variables the value names, each once
     */
    public function variables(): array
    {
        preg_match_all(self::VARIABLE, $this->template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<string, string> $variables
     *
     * @throws InvalidArgumentException when a variable the value names is missing
     */
    public function render(array $variables): string
    {
        return (string) preg_replace_callback(self::VARIABLE, function (array $match) use ($variables): string {
            if (!isset($variables[$match[1]])) {
                throw new InvalidArgumentException(sprintf('The header %s needs the variable %s.', $this->name, $match[1]));
            }

            return $variables[$match[1]];
        }, $this->template);
    }

    private function isNormalized(string $url): bool
    {
        try {
            return Url::normalize($url) === $url;
        } catch (InvalidModel $exception) {
            return false;
        }
    }
}
