<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderSignatureRemoved extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?string $previous_signed_by,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.signature_removed';
    }

    public static function label(): string
    {
        return 'Handtekening werkbon gewist';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public static function coveredFields(): array
    {
        return ['signed_by', 'signature_base64'];
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        if (blank($this->previous_signed_by)) {
            return 'Handtekening van de werkbon gewist';
        }

        return 'Handtekening van ' . $this->previous_signed_by . ' gewist';
    }

    public function changes(): array
    {
        return [[
            'field' => 'signed_by',
            'label' => 'Ondertekend door',
            'old_value' => $this->previous_signed_by,
            'new_value' => null,
            'old_label' => $this->previous_signed_by,
            'new_label' => null,
        ]];
    }
}
