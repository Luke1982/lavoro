<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Enums\CustomFieldTypes;
use App\Http\Requests\CustomFieldReadRequest;
use App\Http\Requests\CustomFieldStoreRequest;
use App\Http\Requests\CustomFieldUpdateRequest;
use App\Http\Requests\CustomFieldDestroyRequest;
use App\Http\Requests\CustomFieldSaveValuesRequest;

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

        $model->customFields()->syncWithoutDetaching($sync_data);

        return redirect()->back()->with('success', "{$saved_name} opgeslagen.");
    }
}
