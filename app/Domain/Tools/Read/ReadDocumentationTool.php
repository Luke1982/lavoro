<?php

namespace App\Domain\Tools\Read;

use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\ProductDocumentation;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Product;
use App\Models\User;

/**
 * Lists the documentation filed against a product or a machine.
 *
 * It reports what is there rather than what is in it. The files themselves travel
 * with the question — a datasheet is a table of figures and a diagram, and
 * describing one back in prose would be exactly the sort of confident summary
 * this application spent so long teaching the assistant not to produce.
 */
class ReadDocumentationTool implements Tool
{
    public static function name(): string
    {
        return 'read_documentation';
    }

    public function description(): string
    {
        return 'Kijkt of er documentatie bij een product of machine is opgeslagen — handleidingen, '
            . 'datasheets, technische specificaties — en zet die erbij zodat je vragen erover kunt '
            . 'beantwoorden. Gebruik dit bij technische vragen over een apparaat in plaats van uit '
            . 'je hoofd te antwoorden.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Het product waarvan je de documentatie wilt.',
                ],
                'asset_id' => [
                    'type' => 'integer',
                    'description' => 'Een machine. Je krijgt de documentatie van die machine '
                        . 'én die van het product waar hij van is.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    /** Uitzoeken welk apparaat bedoeld wordt en of er documentatie bij hoort. */
    public static function difficulty(): int
    {
        return 3;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function execute(ToolCall $call): ToolResult
    {
        $documentation = app(ProductDocumentation::class);
        $asset_id = $call->integerArgument('asset_id');
        $product_id = $call->integerArgument('product_id');

        if ($asset_id === null && $product_id === null) {
            return ToolResult::failed('Geef een product_id of een asset_id op.');
        }

        if ($asset_id !== null) {
            $asset = Asset::visibleTo($call->user)->with('product')->whereKey($asset_id)->first();

            if ($asset === null) {
                return ToolResult::notFound('Machine #' . $asset_id);
            }

            $files = $documentation->forAsset($asset, $call->user);
            $about = 'machine #' . $asset->id;
        } else {
            $product = Product::visibleTo($call->user)->whereKey($product_id)->first();

            if ($product === null) {
                return ToolResult::notFound('Product #' . $product_id);
            }

            $files = $documentation->for($product, $call->user);
            $about = 'product #' . $product->id;
        }

        if ($files->isEmpty()) {
            return ToolResult::ok(
                ['documents' => [], 'note' => 'Er is geen documentatie opgeslagen bij ' . $about
                    . '. Antwoord dus niet uit je hoofd over technische details: zeg dat je het niet weet '
                    . 'en dat er geen documentatie in het systeem staat.'],
                'Geen documentatie gevonden.',
            );
        }

        /**
         * A supplier that cannot read a file is told about, not worked around.
         * Answering anyway would mean answering from memory about a datasheet
         * sitting unread on the disk.
         */
        if (!app(TalksToModel::class)->readsDocuments()) {
            return ToolResult::ok(
                [
                    'documents' => $files->map(fn ($file) => $file->name)->all(),
                    'note' => 'Deze AI-aanbieder kan documenten niet lezen, dus de inhoud is niet '
                        . 'meegestuurd. Noem welke documentatie er is en waar die te vinden is, '
                        . 'maar beantwoord de technische vraag niet.',
                ],
                $files->count() . ' document(en) gevonden, niet leesbaar door deze aanbieder.',
            );
        }

        return ToolResult::ok(
            [
                'documents' => $files->map(fn ($file) => $file->name)->all(),
                'note' => 'Deze documenten zijn bij dit gesprek gevoegd. Beantwoord de vraag op basis '
                    . 'daarvan en noem waar je het uit hebt. Staat het antwoord er niet in, zeg dat dan.',
            ],
            $files->count() . ' document(en) meegestuurd.',
        )->showing($files->all());
    }
}
