<?php

namespace App\Http\Requests;

use App\Services\UpcomingAssetTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Activity list (upcoming activities & map) read authorization.
 *
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 *
 * @mixin Request
 */
class ActivityListReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('activitylist.read'));
    }

    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'view' => ['sometimes', 'nullable', Rule::in(['list', 'table'])],
            'sort' => ['sometimes', 'nullable', Rule::in(UpcomingAssetTable::SORTS)],
            'direction' => ['sometimes', 'nullable', Rule::in(UpcomingAssetTable::DIRECTIONS)],
            'customer' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', 'integer'],
            'due' => ['sometimes', 'nullable', 'string', 'max:20'],
            'planning' => ['sometimes', 'nullable', Rule::in(UpcomingAssetTable::PLANNING)],
            'tickets' => ['sometimes', 'nullable', Rule::in(UpcomingAssetTable::TICKETS)],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * What the table is asked to show, with the defaults filled in: soonest due first, and
     * only the machines nobody has planned yet.
     *
     * @return array{
     *     sort: string, direction: string, planning: string,
     *     customer: ?int, type: ?int, due: ?string, tickets: ?string,
     * }
     */
    public function tableQuery(): array
    {
        return [
            'sort' => $this->input('sort') ?? 'date',
            'direction' => $this->input('direction') ?? 'asc',
            'customer' => $this->integer('customer') ?: null,
            'type' => $this->integer('type') ?: null,
            'due' => $this->input('due'),
            'planning' => $this->input('planning') ?? 'unplanned',
            'tickets' => $this->input('tickets'),
        ];
    }
}
