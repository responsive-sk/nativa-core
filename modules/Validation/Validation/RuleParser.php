<?php

declare(strict_types = 1);

namespace Modules\Validation\Validation;

use InvalidArgumentException;
use Modules\Validation\Contracts\RuleInterface;
use Modules\Validation\Rules\ArrayType;
use Modules\Validation\Rules\Between;
use Modules\Validation\Rules\Boolean;
use Modules\Validation\Rules\Confirmed;
use Modules\Validation\Rules\Date;
use Modules\Validation\Rules\Different;
use Modules\Validation\Rules\Email;
use Modules\Validation\Rules\In;
use Modules\Validation\Rules\Integer;
use Modules\Validation\Rules\Max;
use Modules\Validation\Rules\Min;
use Modules\Validation\Rules\NotIn;
use Modules\Validation\Rules\Nullable;
use Modules\Validation\Rules\Numeric;
use Modules\Validation\Rules\Regex;
use Modules\Validation\Rules\Required;
use Modules\Validation\Rules\Same;
use Modules\Validation\Rules\StringType;
use Modules\Validation\Rules\Url;

/**
 * Parses the string DSL (`'required|email|max:255'`) and rule lists into
 * rule objects. Size rules compare by string length unless the field is
 * declared `numeric`/`integer` — then Min/Max/Between flip into numeric
 * mode, so `string|min:8` never accepts the password `"9"` by value.
 *
 * One parsing caveat, documented rather than hidden: `regex:` consumes the
 * rest of the rule string, so a regex alternation (`/a|b/`) survives the
 * `|` split — but it must therefore come LAST in the string.
 */
final class RuleParser
{
    /**
     * @param string|RuleInterface|list<string|RuleInterface> $rules
     *
     * @return list<RuleInterface>
     */
    public function parse(mixed $rules): array
    {
        if ($rules instanceof RuleInterface) {
            return [$rules];
        }

        if (is_string($rules)) {
            return $this->applyNumericMode($this->parseString($rules));
        }

        if (is_array($rules)) {
            return $this->applyNumericMode($this->parseArray($rules));
        }

        return [];
    }

    /**
     * The DSL token for a rule object — what `field.rule` message keys use.
     * Custom rules fall back to their lowercased short class name.
     */
    public static function tokenFor(RuleInterface $rule): string
    {
        return match ($rule::class) {
            Required::class   => 'required',
            Nullable::class   => 'nullable',
            Email::class      => 'email',
            StringType::class => 'string',
            Integer::class    => 'integer',
            Numeric::class    => 'numeric',
            Min::class        => 'min',
            Max::class        => 'max',
            Between::class    => 'between',
            Confirmed::class  => 'confirmed',
            Same::class       => 'same',
            Different::class  => 'different',
            In::class         => 'in',
            NotIn::class      => 'not_in',
            ArrayType::class  => 'array',
            Boolean::class    => 'boolean',
            Date::class       => 'date',
            Url::class        => 'url',
            Regex::class      => 'regex',
            default           => strtolower((string) (new \ReflectionClass($rule))->getShortName()),
        };
    }

    /**
     * @return list<RuleInterface>
     */
    private function parseString(string $rules): array
    {
        $parsed = [];

        foreach ($this->split($rules) as $token) {
            $parsed[] = $this->parseToken($token);
        }

        return $parsed;
    }

    /**
     * Split on `|`, except a `regex:` token consumes the rest of the string
     * (its pattern may itself contain `|`).
     *
     * @return list<string>
     */
    private function split(string $rules): array
    {
        $segments = explode('|', $rules);
        $tokens = [];

        foreach ($segments as $i => $segment) {
            if (str_starts_with(trim($segment), 'regex:')) {
                $tokens[] = implode('|', array_slice($segments, $i));

                break;
            }

            $tokens[] = $segment;
        }

        return $tokens;
    }

    /**
     * @param list<string|RuleInterface> $rules
     *
     * @return list<RuleInterface>
     */
    private function parseArray(array $rules): array
    {
        $parsed = [];

        foreach ($rules as $rule) {
            if ($rule instanceof RuleInterface) {
                $parsed[] = $rule;
            } elseif (is_string($rule)) {
                foreach ($this->split($rule) as $token) {
                    $parsed[] = $this->parseToken($token);
                }
            }
        }

        return $parsed;
    }

    // A rule-DSL token (`required|email` split at `:`), not a credential — the
    // name is the parser's vocabulary, not a secret.
    // @mago-expect lint:sensitive-parameter
    private function parseToken(string $token): RuleInterface
    {
        $token = trim($token);
        $parts = explode(':', $token, 2);
        $name = strtolower(trim($parts[0]));
        $args = $parts[1] ?? null;

        return match ($name) {
            'required'  => new Required(),
            'nullable'  => new Nullable(),
            'email'     => new Email(),
            'string'    => new StringType(),
            'integer'   => new Integer(),
            'numeric'   => new Numeric(),
            'array'     => new ArrayType(),
            'boolean'   => new Boolean(),
            'date'      => new Date(),
            'url'       => new Url(),
            'confirmed' => new Confirmed(),
            'min'       => new Min($this->number($name, $args)),
            'max'       => new Max($this->number($name, $args)),
            'between'   => $this->between($args),
            'same'      => new Same($this->requiredArg($name, $args)),
            'different' => new Different($this->requiredArg($name, $args)),
            'in'        => new In(...$this->list($name, $args)),
            'not_in'    => new NotIn(...$this->list($name, $args)),
            'regex'     => new Regex($this->requiredArg($name, $args)),
            default     => throw new InvalidArgumentException("Unknown validation rule: {$name}"),
        };
    }

    private function number(string $name, ?string $args): int|float
    {
        $args = null !== $args ? trim($args) : '';

        if (!is_numeric($args)) {
            throw new InvalidArgumentException("Rule {$name} needs a numeric argument, got: {$args}");
        }

        // The addition IS the cast: a numeric string becomes the `int|float`
        // this method promises, while `(float)` would turn `max:5` into 5.0 in
        // every rule message that prints it.
        // @mago-expect lint:no-redundant-math
        return $args + 0;
    }

    private function between(?string $args): Between
    {
        $parts = null !== $args ? explode(',', $args, 2) : [];

        if (2 !== count($parts)) {
            throw new InvalidArgumentException('Rule between needs two numeric arguments, got: ' . ($args ?? ''));
        }

        return new Between($this->number('between', $parts[0]), $this->number('between', $parts[1]));
    }

    private function requiredArg(string $name, ?string $args): string
    {
        $args = null !== $args ? trim($args) : '';

        if ('' === $args) {
            throw new InvalidArgumentException("Rule {$name} needs an argument.");
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function list(string $name, ?string $args): array
    {
        $args = null !== $args ? trim($args) : '';

        if ('' === $args) {
            throw new InvalidArgumentException("Rule {$name} needs a value list.");
        }

        return array_map(trim(...), explode(',', $args));
    }

    /**
     * @param list<RuleInterface> $rules
     *
     * @return list<RuleInterface>
     */
    private function applyNumericMode(array $rules): array
    {
        $numeric = false;

        foreach ($rules as $rule) {
            if ($rule instanceof Numeric || $rule instanceof Integer) {
                $numeric = true;

                break;
            }
        }

        if (!$numeric) {
            return $rules;
        }

        return array_map(
            static fn (RuleInterface $rule): RuleInterface => match (true) {
                $rule instanceof Min     => $rule->asNumeric(),
                $rule instanceof Max     => $rule->asNumeric(),
                $rule instanceof Between => $rule->asNumeric(),
                default                  => $rule,
            },
            $rules,
        );
    }
}
