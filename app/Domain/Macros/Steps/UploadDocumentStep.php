<?php

namespace App\Domain\Macros\Steps;

use App\Actions\Documents\AttachDocumentAction;
use App\Domain\Macros\Asked;
use App\Domain\Macros\MacroRun;
use App\Domain\Macros\MacroStep;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\ServiceOrder;
use App\Models\User;

/** Files a document on the werkbon; the file itself is always asked when the macro runs. */
class UploadDocumentStep implements MacroStep
{
    public function __construct(private readonly AttachDocumentAction $attach) {}

    public static function type(): string
    {
        return 'upload_document';
    }

    public function rules(): array
    {
        return [
            'document_category_id' => ['nullable', 'integer', 'exists:document_categories,id'],
            'prompt' => ['required', 'string', 'max:255'],
            'internal' => ['boolean'],
        ];
    }

    public function askable(): array
    {
        return ['file' => 'file'];
    }

    public function describe(array $step): string
    {
        $category = DocumentCategory::find($step['document_category_id'] ?? null);

        return 'Document uploaden' . ($category ? ' in categorie "' . $category->name . '"' : '')
            . (!empty($step['internal']) ? ' (intern)' : '')
            . ': ' . Asked::quoted(['ask' => $step['prompt'] ?? '']) . '.';
    }

    public function allows(User $user): bool
    {
        return $user->can('create', Document::class);
    }

    public function run(ServiceOrder $order, array $step, MacroRun $run): void
    {
        $file = $run->file($step['id']);

        $document = $this->attach->execute(
            $order,
            $file,
            $file->getClientOriginalName(),
            $step['document_category_id'] ?? null,
            $run->user->id,
            !empty($step['internal']),
        );

        $run->stored($document->path);
    }
}
