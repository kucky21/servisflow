<?php

declare(strict_types=1);

namespace App\Forms;

use Nette\Forms\Container;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form;

/**
 * Opakovaně používaná pole formulářů.
 */
final class FieldHelper
{
    /**
     * Peněžní částka jako text (žádný float) – „1234,50“ nebo „1234.50“.
     */
    public static function addMoney(Container $container, string $name, string $label, bool $required = false): TextInput
    {
        $input = $container->addText($name, $label)
            ->setHtmlAttribute('inputmode', 'decimal')
            ->setHtmlAttribute('placeholder', '0,00')
            ->setHtmlAttribute('class', 'text-end')
            ->setMaxLength(14);

        if ($required) {
            $input->setRequired('Zadejte částku.');
        } else {
            $input->setNullable();
        }

        $input->addCondition(Form::Filled)
            ->addRule(Form::Pattern, 'Zadejte částku ve tvaru 1234,50.', '\s*\d{1,9}([.,]\d{1,2})?\s*');

        return $input;
    }

    public static function addPercent(Container $container, string $name, string $label): TextInput
    {
        $input = $container->addText($name, $label)
            ->setHtmlAttribute('inputmode', 'decimal')
            ->setHtmlAttribute('class', 'text-end')
            ->setMaxLength(6)
            ->setNullable();
        $input->addCondition(Form::Filled)
            ->addRule(Form::Pattern, 'Zadejte procenta (0–100).', '\s*(100([.,]0{1,2})?|\d{1,2}([.,]\d{1,2})?)\s*');

        return $input;
    }
}
