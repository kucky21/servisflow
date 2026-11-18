<?php

declare(strict_types=1);

namespace App\DTO\Input;

use App\Model\Enum\CustomerType;

final readonly class CustomerData
{
    public function __construct(
        public CustomerType $type,
        public string $name,
        public ?string $contactPerson,
        public ?string $email,
        public ?string $phone,
        public ?string $street,
        public ?string $city,
        public ?string $zip,
        public ?string $ico,
        public ?string $dic,
        public ?string $note,
    ) {
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public static function fromArray(iterable $values): self
    {
        $v = Normalizer::toArray($values);

        return new self(
            type: CustomerType::tryFrom((string) ($v['type'] ?? '')) ?? CustomerType::Person,
            name: Normalizer::string($v['name'] ?? ''),
            contactPerson: Normalizer::nullableString($v['contact_person'] ?? null),
            email: ($email = Normalizer::nullableString($v['email'] ?? null)) === null ? null : mb_strtolower($email),
            phone: Normalizer::nullableString($v['phone'] ?? null),
            street: Normalizer::nullableString($v['street'] ?? null),
            city: Normalizer::nullableString($v['city'] ?? null),
            zip: Normalizer::nullableString($v['zip'] ?? null),
            ico: ($ico = Normalizer::nullableString($v['ico'] ?? null)) === null ? null : preg_replace('/\s+/', '', $ico),
            dic: ($dic = Normalizer::nullableString($v['dic'] ?? null)) === null ? null : strtoupper(str_replace(' ', '', $dic)),
            note: Normalizer::nullableString($v['note'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'contact_person' => $this->contactPerson,
            'email' => $this->email,
            'phone' => $this->phone,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'ico' => $this->ico,
            'dic' => $this->dic,
            'note' => $this->note,
        ];
    }
}
