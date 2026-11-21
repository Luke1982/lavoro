<?php

namespace App\Http\Controllers;

use App\Domain\Signals\CustomFields\CustomFieldValuesChanged;
use App\Domain\Signals\Signals;
use App\Enums\CustomFieldTypes;
use App\Http\Requests\CustomFieldDestroyRequest;
use App\Http\Requests\CustomFieldReadRequest;
use App\Http\Requests\CustomFieldSaveValuesRequest;
use App\Http\Requests\CustomFieldStoreRequest;
use App\Http\Requests\CustomFieldUpdateRequest;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CustomFieldController extends Controller
{
    public function index(CustomFieldReadRequest $request)
    {
        $data = $request->validated();
        $search = $data['search'] ?? null;

        $query = CustomField::query();

        if ($search) {
            $words = explode(' ', $search);
            foreach ($words as $word) {
                $query->where('name', 'like', '%' . strtolower($word) . '%');
            }
        }

        return inertia('CustomFields/IndexPage', [
            'customFields' => $query->orderBy('sort_order')->orderBy('name')->paginate(20),
            'fieldTypes' => CustomFieldTypes::comboArray(),
            'targetModels' => CustomField::targetModelOptions(),
            'search' => $search,
        ]);
    }

    public function store(CustomFieldStoreRequest $request)
    {
        $data = $request->validated();

        if (($data['field_type'] ?? '') !== 'select') {
            $data['options'] = null;
        }

        CustomField::create($data);

        return redirect()->route('customfields.index');
    }

    public function update(CustomFieldUpdateRequest $request, CustomField $customfield)
    {
        $data = $request->validated();

        if (($data['field_type'] ?? '') !== 'select') {
            $data['options'] = null;
        }

        $customfield->update($data);

        return redirect()->back();
    }

    public function destroy(CustomFieldDestroyRequest $request, CustomField $customfield)
    {
        $customfield->delete();

        return redirect()->back();
    }

    public function saveValues(CustomFieldSaveValuesRequest $request)
    {
        $data = $request->validated();
        $model = $request->modelClass()::findOrFail($data['model_id']);
        $custom_fields = $request->resolveCustomFields();

        $sync_data = [];
        $saved_name = 'Extra veld';
        foreach ($data['values'] as $field_id => $value) {
            $sync_data[$field_id] = ['value' => $value];
            if ($custom_fields->has($field_id)) {
                $saved_name = $custom_fields->get($field_id)->name;
            }
        }

        DB::transaction(function () use ($model, $sync_data, $custom_fields) {
            $field_ids = array_keys($sync_data);
            $before = $this->storedValues($model, $field_ids);

            $model->customFields()->syncWithoutDetaching($sync_data);

            Signals::dispatch(new CustomFieldValuesChanged(
                $model,
                $custom_fields->only($field_ids)
                    ->mapWithKeys(fn (CustomField $field) => [
                        $field->id => ['name' => $field->name, 'field_type' => $field->field_type],
                    ])
                    ->all(),
                $before,
                $this->storedValues($model, $field_ids),
            ));
        });

        return redirect()->back()->with('success', "{$saved_name} opgeslagen.");
    }

    /**
     * @param  array<int, int|string>  $field_ids
     * @return array<int, ?string>
     */
    private function storedValues(Model $model, array $field_ids): array
    {
        return $model->customFields()
            ->whereIn('custom_fields.id', $field_ids)
            ->get()
            ->mapWithKeys(fn (CustomField $field) => [$field->id => $field->pivot->value])
            ->all();
    }
}
