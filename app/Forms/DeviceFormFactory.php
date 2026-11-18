<?php

declare(strict_types=1);

namespace App\Forms;

use App\Model\Enum\DeviceType;
use Nette\Application\UI\Form;

final class DeviceFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    /**
     * @param array<int, string> $customerOptions
     */
    public function create(array $customerOptions): Form
    {
        $form = $this->factory->create();

        $form->addSelect('customer_id', 'Zákazník', $customerOptions)
            ->setPrompt('— vyberte zákazníka —')
            ->setRequired('Vyberte zákazníka.');
        $form->addSelect('type', 'Typ zařízení', DeviceType::options())
            ->setDefaultValue(DeviceType::Laptop->value)
            ->setRequired();
        $form->addText('manufacturer', 'Výrobce')
            ->setRequired('Vyplňte výrobce.')
            ->setMaxLength(100)
            ->setHtmlAttribute('list', 'manufacturers');
        $form->addText('model', 'Model')
            ->setRequired('Vyplňte model.')
            ->setMaxLength(150);
        $form->addText('serial_number', 'Sériové číslo / IMEI')
            ->setMaxLength(100)
            ->setNullable();
        $form->addTextArea('description', 'Popis (konfigurace, barva, poškození…)', null, 3)
            ->setMaxLength(5000)
            ->setNullable();
        $form->addSubmit('send', 'Uložit zařízení');

        return $form;
    }
}
