<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use Nette\Application\Attributes\Requires;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Presenter;

/**
 * Chybové stránky 4xx (nenalezeno, zakázáno…).
 */
#[Requires(methods: '*', forward: true)]
final class Error4xxPresenter extends Presenter
{
    public function renderDefault(BadRequestException $exception): void
    {
        $code = $exception->getCode();

        $title = match ($code) {
            403 => 'Přístup odepřen',
            404 => 'Stránka nenalezena',
            405 => 'Nepovolená metoda',
            410 => 'Stránka již neexistuje',
            default => 'Chyba požadavku',
        };
        $message = match ($code) {
            403 => 'K této části systému nemáte oprávnění. Pokud jde o omyl, kontaktujte administrátora.',
            404 => 'Požadovaná stránka nebo záznam neexistuje. Možná byl smazán nebo je adresa chybná.',
            default => 'Požadavek nelze zpracovat.',
        };

        if ($this->isAjax()) {
            $this->payload->toasts = [['type' => 'danger', 'message' => $message]];
            $this->sendPayload();
        }

        $this->template->httpCode = $code;
        $this->template->title = $title;
        $this->template->message = $message;
        $this->template->isLoggedIn = $this->getUser()->isLoggedIn();
    }
}
