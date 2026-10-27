<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

/**
 * The `format` values the generator understands. Specs write the same format many ways (`dateTime`, `ISO 8601
 * date-time`, `phone_number`): normalize() gives the one name the generator and its extensions act on.
 *
 * @api
 */
final class Formats
{
    /** @var list<non-empty-string> the formats of a string read as a date class */
    public const DATES = ['date-time', 'date', 'date-time-rfc-2822'];

    /** @var list<non-empty-string> the formats of whole numbers, which make a number an int */
    public const INTEGERS = ['int8', 'int16', 'int32', 'int64', 'uint8', 'uint16', 'uint32', 'uint64', 'integer'];

    /** @var list<non-empty-string> the formats of a string that holds a number */
    public const NUMERIC_STRINGS = [...self::INTEGERS, 'decimal', 'digits'];

    /** @var list<non-empty-string> */
    private const STRINGS = [
        ...self::NUMERIC_STRINGS,
        'date-time', 'date', 'time', 'partial-time', 'duration', 'date-time-rfc-2822', 'email', 'idn-email', 'hostname',
        'idn-hostname', 'ipv4', 'ipv6', 'ip', 'cidr', 'ipv4-cidr', 'ipv6-cidr', 'ip-or-cidr', 'mac-address', 'uri',
        'uri-reference', 'iri', 'iri-reference', 'uri-template', 'uuid', 'ulid', 'byte', 'base64url', 'binary',
        'password', 'regex', 'json-pointer', 'relative-json-pointer', 'json', 'phone', 'currency', 'country',
        'language', 'locale', 'timezone', 'color', 'hex-color', 'iban', 'bic', 'isbn', 'issn', 'isin', 'http-method',
        'mm/yyyy', 'string',
    ];

    /** @var array{string: list<non-empty-string>, integer: list<non-empty-string>, number: list<non-empty-string>} */
    private const BY_TYPE = [
        'string' => self::STRINGS,
        'integer' => [...self::INTEGERS, 'unix-time'],
        // A number of whole numbers is an int (TypeMapper).
        'number' => [...self::INTEGERS, 'float', 'double', 'decimal', 'number', 'unix-time'],
    ];

    /**
     * Other spellings, lower-cased with runs of spaces and underscores as one hyphen.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private const ALIASES = [
        'datetime' => 'date-time',
        'iso-8601-date-time' => 'date-time',
        'iso8601-date-time' => 'date-time',
        'iso-date-time' => 'date-time',
        'iso-8601' => 'date-time',
        'iso8601' => 'date-time',
        'rfc3339' => 'date-time',
        'rfc-3339' => 'date-time',
        'date-time-rfc3339' => 'date-time',
        'date-time-rfc-3339' => 'date-time',
        'date-time-iso8601' => 'date-time',
        'full-date' => 'date',
        'iso-date' => 'date',
        'full-time' => 'time',
        'rfc2822' => 'date-time-rfc-2822',
        'rfc-2822' => 'date-time-rfc-2822',
        'date-time-rfc2822' => 'date-time-rfc-2822',
        'unixtime' => 'unix-time',
        'unix-timestamp' => 'unix-time',
        'unix-epoch' => 'unix-time',
        'epoch' => 'unix-time',
        'epoch-seconds' => 'unix-time',
        'timestamp' => 'unix-time',
        'e-mail' => 'email',
        'email-address' => 'email',
        'host-name' => 'hostname',
        'ip-address' => 'ip',
        'ipaddress' => 'ip',
        'ip-v4' => 'ipv4',
        'ipv4-address' => 'ipv4',
        'ipv4|ipv6' => 'ip',
        'ip-v6' => 'ipv6',
        'ipv6-address' => 'ipv6',
        'ip/netmask' => 'cidr',
        'ip/prefix-length' => 'cidr',
        'ipv4/netmask' => 'ipv4-cidr',
        'ipv4/prefix-length' => 'ipv4-cidr',
        'ipv6/prefix-length' => 'ipv6-cidr',
        'ipv6/128' => 'ipv6-cidr',
        // Not cidr: Linode's examples of it have no prefix.
        'ipv4|ipv6/prefix-length' => 'ip-or-cidr',
        'mac' => 'mac-address',
        'macaddress' => 'mac-address',
        'guid' => 'uuid',
        'base64' => 'byte',
        // Specs write "url" for relative paths and bare host names too (Linode), which `uri` would reject.
        'url' => 'uri-reference',
        'json-string' => 'json',
        'phone-number' => 'phone',
        'phonenumber' => 'phone',
        'telephone' => 'phone',
        'tel' => 'phone',
        'e164' => 'phone',
        'e.164' => 'phone',
        'currency-code' => 'currency',
        'iso-4217' => 'currency',
        'iso4217' => 'currency',
        'country-code' => 'country',
        'iso-country-code' => 'country',
        'iso-3166' => 'country',
        'iso3166' => 'country',
        'iso-3166-1' => 'country',
        'iso-3166-1-alpha-2' => 'country',
        'language-code' => 'language',
        'iso-639' => 'language',
        'iso-639-1' => 'language',
        'bcp47' => 'locale',
        'bcp-47' => 'locale',
        'language-tag' => 'locale',
        'time-zone' => 'timezone',
        'iana-timezone' => 'timezone',
        'tz' => 'timezone',
        'hexcolor' => 'hex-color',
        'colour' => 'color',
        'css-color' => 'color',
        'swift' => 'bic',
        'swift-code' => 'bic',
        'numeric' => 'decimal',
        'decimal-string' => 'decimal',
        'http-verb' => 'http-method',
        'int' => 'integer',
        'long' => 'int64',
        'short' => 'int16',
    ];

    private function __construct()
    {
    }

    /**
     * The name the generator knows the format by: lower case, spaces and underscores as hyphens, another spelling as
     * the name it stands for.
     */
    public static function normalize(string $format): string
    {
        $key = strtolower((string) preg_replace('/[\s_]+/', '-', trim($format)));

        return self::ALIASES[$key] ?? $key;
    }

    /**
     * @param 'string'|'integer'|'number' $type
     */
    public static function isKnown(string $normalized, string $type): bool
    {
        return in_array($normalized, self::BY_TYPE[$type], true);
    }
}
