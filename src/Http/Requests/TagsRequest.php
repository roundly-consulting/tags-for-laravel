<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tags\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use RoundlyConsulting\Tags\Http\Access;
use RoundlyConsulting\Tags\Support\Messages;
use RoundlyConsulting\Tags\Support\MorphAliases;

/**
 * The base of the API's requests: every endpoint takes `?scope=alias:key` (an alias from
 * `tags.scopes`, never a class name) and `?scope_only=1`.
 *
 * @internal
 */
abstract class TagsRequest extends FormRequest
{
    private const SCOPE = 'tags.scope';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', 'max:330'],
            'scope_only' => ['nullable', 'boolean'],
            ...$this->endpointRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (['name', 'description', 'slug', 'color', 'icon', 'parent', 'position', 'order', 'children', 'q', 'limit', 'on'] as $field) {
            $attributes[$field] = Messages::get('validation.attributes.'.$field);
        }

        return $attributes;
    }

    /**
     * The scope the request targets: reads see it plus the global tags, writes go to it. Null
     * for global tags. Requires the `tags.scope` ability on the scope model.
     */
    public function scopeModel(): ?Model
    {
        if ($this->attributes->has(self::SCOPE)) {
            $memo = $this->attributes->get(self::SCOPE);

            return $memo instanceof Model ? $memo : null;
        }

        $reference = $this->validated('scope');
        $scope = is_string($reference) && $reference !== '' ? MorphAliases::scope($reference) : null;

        if ($scope !== null) {
            Access::authorizeScope($this, $scope);
        }

        $this->attributes->set(self::SCOPE, $scope);

        return $scope;
    }

    public function includeGlobal(): bool
    {
        return ! filter_var($this->validated('scope_only', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<string, mixed>
     */
    protected function endpointRules(): array
    {
        return [];
    }
}
