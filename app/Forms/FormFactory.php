<?php

declare(strict_types=1);

namespace App\Forms;

use App\Exceptions\ValidationException;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\CsrfProtection;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\UploadControl;
use Nette\Forms\Form as BaseForm;
use Nette\Forms\Validator;

/**
 * Základ všech formulářů: CSRF ochrana, české chybové hlášky, sjednocené chování.
 */
final class FormFactory
{
    private static bool $messagesInitialized = false;

    public function create(): Form
    {
        self::initMessages();

        $form = new Form();
        $form->addProtection('Platnost formuláře vypršela. Odešlete jej prosím znovu.');

        return $form;
    }

    /**
     * Přenese chyby validace ze servisní vrstvy na konkrétní pole formuláře.
     */
    public static function applyErrors(BaseForm $form, ValidationException $e): void
    {
        $fieldErrors = $e->getFieldErrors();
        foreach ($fieldErrors as $field => $message) {
            $control = $form->getComponent($field, false);
            if ($control instanceof BaseControl) {
                $control->addError($message);
            } else {
                $form->addError($message);
            }
        }
        if ($fieldErrors === []) {
            $form->addError($e->getMessage());
        }
    }

    private static function initMessages(): void
    {
        if (self::$messagesInitialized) {
            return;
        }
        self::$messagesInitialized = true;

        Validator::$messages = [
            CsrfProtection::Protection => 'Platnost formuláře vypršela. Odešlete jej prosím znovu.',
            BaseForm::Equal => 'Zadejte %s.',
            BaseForm::NotEqual => 'Tato hodnota není povolena.',
            BaseForm::Filled => 'Toto pole je povinné.',
            BaseForm::Blank => 'Toto pole musí zůstat prázdné.',
            BaseForm::MinLength => 'Zadejte alespoň %d znaků.',
            BaseForm::MaxLength => 'Zadejte nejvýše %d znaků.',
            BaseForm::Length => 'Zadejte hodnotu dlouhou %d až %d znaků.',
            BaseForm::Email => 'Zadejte platný e-mail.',
            BaseForm::URL => 'Zadejte platnou URL adresu.',
            BaseForm::Enum => 'Vyberte platnou možnost.',
            BaseForm::Integer => 'Zadejte celé číslo.',
            BaseForm::Numeric => 'Zadejte nezáporné celé číslo.',
            BaseForm::Float => 'Zadejte číslo.',
            BaseForm::Min => 'Zadejte hodnotu větší nebo rovnou %d.',
            BaseForm::Max => 'Zadejte hodnotu menší nebo rovnou %d.',
            BaseForm::Range => 'Zadejte hodnotu mezi %d a %d.',
            BaseForm::MaxFileSize => 'Soubor může mít nejvýše %d bajtů.',
            BaseForm::MaxPostSize => 'Odeslaná data překročila limit %d bajtů.',
            BaseForm::MimeType => 'Nahraný soubor nemá povolený formát.',
            BaseForm::Image => 'Nahraný soubor musí být obrázek.',
            SelectBox::Valid => 'Vyberte platnou možnost.',
            UploadControl::Valid => 'Při nahrávání souboru došlo k chybě.',
        ];
    }
}
