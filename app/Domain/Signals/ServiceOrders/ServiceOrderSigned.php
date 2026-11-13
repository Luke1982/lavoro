<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderSigned extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?string $previous_signed_by,
        public ?string $signed_by,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.signed';
    }

    public static function label(): string
    {
        return 'Werkbon ondertekend';
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
        $verb = blank($this->previous_signed_by) ? 'ondertekend' : 'opnieuw ondertekend';

        return 'Werkbon ' . $verb . ' door ' . ($this->signed_by ?? 'onbekend');
    }

    public function changes(): array
    {
        return [[
            'field' => 'signed_by',
            'label' => 'Ondertekend door',
            'old_value' => $this->previous_signed_by,
            'new_value' => $this->signed_by,
            'old_label' => $this->previous_signed_by,
            'new_label' => $this->signed_by,
        ]];
    }
}
