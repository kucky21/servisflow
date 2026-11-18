<?php

declare(strict_types=1);

namespace App\Forms;

use App\Model\Enum\OrderPriority;
use App\Model\Workflow\OrderTransition;
use Nette\Application\UI\Form;

/**
 * Formuláře servisní zakázky a jejího pracovního prostoru.
 */
final class OrderFormFactory
{
    public function __construct(
        private readonly FormFactory $factory,
    ) {
    }

    /**
     * @param array<int, string> $customerOptions
     * @param array<int, string> $deviceOptions
     * @param array<int, string> $technicianOptions
     */
    public function createOrderForm(bool $isNew, array $customerOptions, array $deviceOptions, array $technicianOptions): Form
    {
        $form = $this->factory->create();

        if ($isNew) {
            $form->addSelect('customer_id', 'Zákazník', $customerOptions)
                ->setPrompt('— vyberte zákazníka —')
                ->setRequired('Vyberte zákazníka.')
                ->setHtmlAttribute('data-device-source', 'customer');
            $form->addSelect('device_id', 'Zařízení', $deviceOptions)
                ->setPrompt($deviceOptions === [] ? '— nejprve vyberte zákazníka —' : '— vyberte zařízení —')
                ->setRequired('Vyberte zařízení.')
                ->checkDefaultValue(false);
        }

        $form->addSelect('priority', 'Priorita', OrderPriority::options())
            ->setDefaultValue(OrderPriority::Normal->value)
            ->setRequired();
        $form->addSelect('assigned_technician_id', 'Přiřazený technik', $technicianOptions)
            ->setPrompt('— nepřiřazeno —');
        $form->addDate('due_date', 'Termín dokončení')
            ->setOption('description', $isNew ? 'Nevyplníte-li, nastaví se dle výchozí lhůty.' : null);
        $form->addTextArea('problem_description', 'Popis závady', null, 4)
            ->setRequired('Popište závadu.')
            ->addRule(Form::MinLength, 'Popis závady musí mít alespoň %d znaků.', 5)
            ->setMaxLength(5000);
        $form->addTextArea('accessories', 'Příslušenství a stav při převzetí', null, 2)
            ->setMaxLength(2000)
            ->setNullable()
            ->setHtmlAttribute('placeholder', 'Např. nabíječka, obal; poškrábané víko…');
        $form->addTextArea('diagnosis', 'Diagnostika', null, 3)
            ->setMaxLength(5000)
            ->setNullable();
        FieldHelper::addMoney($form, 'estimated_price', 'Odhadovaná cena vč. DPH');
        FieldHelper::addMoney($form, 'hourly_rate', 'Hodinová sazba (bez DPH)')
            ->setOption('description', $isNew ? 'Prázdné = výchozí sazba z nastavení.' : null);
        FieldHelper::addPercent($form, 'discount_percent', 'Sleva %');
        $form->addTextArea('internal_note', 'Interní poznámka', null, 2)
            ->setMaxLength(5000)
            ->setNullable();

        $form->addSubmit('send', $isNew ? 'Přijmout zakázku' : 'Uložit změny');

        return $form;
    }

    /**
     * @param list<OrderTransition> $transitions
     */
    public function createStatusForm(array $transitions): Form
    {
        $form = $this->factory->create();
        $options = [];
        foreach ($transitions as $transition) {
            $options[$transition->to->value] = $transition->label;
        }
        $form->addSelect('status', 'Nový stav', $options)
            ->setRequired('Tento přechod není pro vaši roli nebo aktuální stav zakázky povolen.');
        $form->addTextArea('note', 'Poznámka', null, 2)->setMaxLength(2000)->setNullable();
        $form->addSubmit('send', 'Změnit stav');

        return $form;
    }

    /**
     * @param array<int, string> $technicianOptions
     */
    public function createAssignForm(array $technicianOptions): Form
    {
        $form = $this->factory->create();
        $form->addSelect('technician_id', 'Technik', $technicianOptions)->setPrompt('— nepřiřazeno —');
        $form->addSubmit('send', 'Přiřadit');

        return $form;
    }

    public function createDiagnosisForm(): Form
    {
        $form = $this->factory->create();
        $form->addTextArea('diagnosis', 'Diagnostika a postup opravy', null, 5)
            ->setMaxLength(5000)
            ->setNullable();
        FieldHelper::addMoney($form, 'estimated_price', 'Odhadovaná cena vč. DPH');
        $form->addSubmit('send', 'Uložit diagnostiku');

        return $form;
    }

    public function createWorkLogForm(): Form
    {
        $form = $this->factory->create();
        $form->addText('description', 'Popis úkonu')
            ->setRequired('Popište provedenou práci.')
            ->setMaxLength(500);
        $form->addText('hours', 'Čas (h)')
            ->setRequired('Zadejte odpracovaný čas.')
            ->setHtmlAttribute('inputmode', 'decimal')
            ->setHtmlAttribute('placeholder', '1,5')
            ->addRule(Form::Pattern, 'Zadejte čas v hodinách, např. 0,5 nebo 1,25.', '\s*\d{1,2}([.,]\d{1,2})?\s*');
        $form->addDate('work_date', 'Datum')
            ->setDefaultValue(new \DateTimeImmutable('today'))
            ->setRequired();
        $form->addSubmit('send', 'Vykázat práci');

        return $form;
    }

    /**
     * @param array<int, string> $itemOptions
     */
    public function createMaterialForm(array $itemOptions): Form
    {
        $form = $this->factory->create();
        $form->addSelect('item_id', 'Skladová položka', $itemOptions)
            ->setPrompt($itemOptions === [] ? '— sklad je prázdný —' : '— vyberte položku —')
            ->setRequired('Vyberte položku.');
        $form->addInteger('quantity', 'Množství')
            ->setDefaultValue(1)
            ->setRequired()
            ->addRule(Form::Range, 'Množství musí být %d–%d.', [1, 1000]);
        $form->addSubmit('send', 'Vydat ze skladu');

        return $form;
    }

    public function createCommentForm(): Form
    {
        $form = $this->factory->create();
        $form->addTextArea('body', 'Interní komentář', null, 2)
            ->setRequired('Napište komentář.')
            ->setMaxLength(5000)
            ->setHtmlAttribute('placeholder', 'Napište poznámku pro kolegy…');
        $form->addSubmit('send', 'Přidat komentář');

        return $form;
    }
}
