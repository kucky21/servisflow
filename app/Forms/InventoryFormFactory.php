<?php

declare(strict_types=1);

namespace App\Forms;

use Nette\Application\UI\Form;

final class InventoryFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    public function createItemForm(bool $isNew): Form
    {
        $form = $this->factory->create();
        $form->addText('sku', 'SKU (kód)')
            ->setRequired('Zadejte SKU.')
            ->setMaxLength(40)
            ->setHtmlAttribute('class', 'text-uppercase')
            ->addRule(Form::Pattern, 'SKU smí obsahovat písmena, číslice, pomlčky a tečky.', '\s*[A-Za-z0-9][A-Za-z0-9\-_.]{1,39}\s*');
        $form->addText('name', 'Název')->setRequired('Zadejte název.')->setMaxLength(200);
        $form->addTextArea('description', 'Popis', null, 2)->setMaxLength(2000)->setNullable();
        $form->addText('unit', 'Jednotka')->setDefaultValue('ks')->setRequired()->setMaxLength(10);
        FieldHelper::addMoney($form, 'purchase_price', 'Nákupní cena (bez DPH)', required: true);
        FieldHelper::addMoney($form, 'sale_price', 'Prodejní cena (bez DPH)', required: true);
        $form->addInteger('min_quantity', 'Minimální zásoba')
            ->setDefaultValue(2)
            ->setRequired()
            ->addRule(Form::Min, 'Minimální zásoba nemůže být záporná.', 0);
        if ($isNew) {
            $form->addInteger('initial_quantity', 'Počáteční stav')
                ->setDefaultValue(0)
                ->addRule(Form::Range, 'Zadejte %d–%d.', [0, 100000]);
        }
        $form->addCheckbox('is_active', 'Aktivní položka (lze vydávat)')->setDefaultValue(true);
        $form->addSubmit('send', $isNew ? 'Založit položku' : 'Uložit změny');

        return $form;
    }

    public function createReceiptForm(): Form
    {
        $form = $this->factory->create();
        $form->addHidden('item_id');
        $form->addInteger('quantity', 'Přijaté množství')
            ->setRequired('Zadejte množství.')
            ->addRule(Form::Range, 'Zadejte %d–%d.', [1, 100000]);
        FieldHelper::addMoney($form, 'unit_price', 'Nákupní cena / j. (volitelně)')
            ->setOption('description', 'Vyplněná cena aktualizuje nákupní cenu položky.');
        $form->addText('note', 'Poznámka / doklad')->setMaxLength(500)->setNullable()
            ->setHtmlAttribute('placeholder', 'Např. faktura dodavatele 2026-118');
        $form->addSubmit('send', 'Přijmout na sklad');

        return $form;
    }

    public function createAdjustmentForm(): Form
    {
        $form = $this->factory->create();
        $form->addInteger('quantity', 'Skutečný stav')
            ->setRequired('Zadejte skutečný stav.')
            ->addRule(Form::Min, 'Stav nemůže být záporný.', 0);
        $form->addText('note', 'Důvod úpravy')
            ->setRequired('Uveďte důvod inventurní úpravy.')
            ->setMaxLength(500);
        $form->addSubmit('send', 'Provést úpravu');

        return $form;
    }
}
