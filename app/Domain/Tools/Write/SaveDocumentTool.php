<?php

namespace App\Domain\Tools\Write;

use App\Actions\Documents\AttachDocumentAction;
use App\Domain\Assistant\ConversationFiles;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Read\Concerns\OffersAChoice;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\StorageQuota;
use Illuminate\Http\File;

/**
 * Files a document that was dropped into the chat on a werkbon.
 *
 * A file in the chat is borrowed for the conversation and thrown away after
 * it. That is right for a datasheet somebody wanted read, and wrong for the
 * signed offerte a werkbon was just made from — that one belongs on the
 * werkbon. So the assistant asks, and only a click files it, in a category
 * somebody chose: a document without one is a document nobody finds again.
 */
class SaveDocumentTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use OffersAChoice;

    public static function name(): string
    {
        return 'save_document';
    }

    public function description(): string
    {
        return 'Bewaart een bestand dat in dit gesprek is meegestuurd (bijvoorbeeld een getekende offerte) '
            . 'als document bij een werkbon. Doe dit alleen als de gebruiker ja heeft gezegd '
            . 'op de vraag of het bestand bewaard moet worden. Geef de categorie op met de naam zoals die '
            . 'in het systeem staat; weet je die niet, laat hem dan weg en je krijgt de categorieën als '
            . 'knoppen terug. Roep de tool aan zodra je dat hebt: er wordt nog niets opgeslagen, het '
            . 'systeem legt de gebruiker een bevestigingsknop voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'service_order_id' => [
                    'type' => 'integer',
                    'description' => 'De werkbon waar het document bij komt.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Naam van de documentcategorie.',
                ],
                'file_name' => [
                    'type' => 'string',
                    'description' => 'Welk meegestuurd bestand. Mag weg als er maar één is.',
                ],
            ],
            'required' => ['service_order_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Document::class);
    }

    /** Werkbon en categorie liggen al vast als dit aan de orde komt; het is invullen. */
    public static function difficulty(): int
    {
        return 3;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function previewOf(ToolCall $call): string
    {
        $file = $this->fileOf($call);
        $record = $this->recordOf($call);
        $category = $this->categoryOf($call);

        return 'Document bewaren: ' . ($file['name'] ?? 'bestand')
            . ($record ? ' bij ' . $this->describe($record) : '')
            . ($category ? ' in categorie ' . $category->name : ' zonder categorie');
    }

    public function refusalBeforeConfirming(ToolCall $call): ?ToolResult
    {
        return $this->problemWith($call);
    }

    public function execute(ToolCall $call): ToolResult
    {
        $problem = $this->problemWith($call);

        if ($problem !== null) {
            return $problem;
        }

        $file = $this->fileOf($call);
        $record = $this->recordOf($call);
        $category = $this->categoryOf($call);

        $document = app(AttachDocumentAction::class)->execute(
            $record,
            new File($file['path']),
            $file['name'],
            $category?->id,
            $call->user->id,
        );

        return ToolResult::ok(
            [
                'document_id' => $document->id,
                'name' => $document->name,
                'category' => $category?->name,
                'service_order_id' => $record->id,
                'link' => '/serviceorders/' . $record->id,
                'what' => $document->name . ' bij ' . $this->describe($record),
            ],
            'Document ' . $document->name . ' bewaard bij ' . $this->describe($record) . '.',
        );
    }

    /**
     * Everything that would make the click fail, found before it is offered
     * and found again when it runs: the file may have been pruned in between.
     */
    private function problemWith(ToolCall $call): ?ToolResult
    {
        if (blank($call->conversation)) {
            return ToolResult::failed('Er hoort geen bestand bij dit gesprek.');
        }

        $file = $this->fileOf($call);

        if ($file === null) {
            return ToolResult::failed(app(ConversationFiles::class)->whichOne($call->conversation));
        }

        if ($this->recordOf($call) === null) {
            return ToolResult::notFound('Werkbon #' . ($call->integerArgument('service_order_id') ?? '?'));
        }

        $wanted = $call->stringArgument('category');
        $categories = DocumentCategory::forPicker()->pluck('name');

        if ($categories->isNotEmpty() && $this->categoryOf($call) === null) {
            $said = blank($wanted)
                ? 'In welke categorie moet het document?'
                : '"' . $wanted . '" is geen documentcategorie. Welke dan?';

            $content = ['error' => $said, 'categories' => $categories->all()];
            $choice = $this->choiceOfValues($categories, $said, 'documentcategorie');

            if ($choice !== null) {
                $content['choice'] = $choice;
                $content['note'] = 'De gebruiker krijgt de categorieën als knoppen; som ze niet nog eens op.';
            }

            return ToolResult::failedWith($content, $said);
        }

        if (tenancy()->initialized && !(new StorageQuota)->hasRoomFor((int) filesize($file['path']))) {
            return ToolResult::failed('De opslaglimiet is bereikt; het document kan niet bewaard worden.');
        }

        return null;
    }

    /** @return array{path: string, name: string}|null */
    private function fileOf(ToolCall $call): ?array
    {
        if (blank($call->conversation)) {
            return null;
        }

        return app(ConversationFiles::class)->named($call->conversation, $call->stringArgument('file_name'));
    }

    private function recordOf(ToolCall $call): ?ServiceOrder
    {
        $order_id = $call->integerArgument('service_order_id');

        return $order_id === null
            ? null
            : ServiceOrder::visibleTo($call->user)->with('customer:id,name')->find($order_id);
    }

    private function categoryOf(ToolCall $call): ?DocumentCategory
    {
        $wanted = $call->stringArgument('category');

        if (blank($wanted)) {
            return null;
        }

        return DocumentCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($wanted)])->first();
    }

    private function describe(ServiceOrder $order): string
    {
        return 'werkbon #' . $order->id . ($order->customer ? ' (' . $order->customer->name . ')' : '');
    }
}
