<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error5xx;

use Nette\Application\Attributes\Requires;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\CallbackResponse;
use Nette\Application\Responses\JsonResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tracy\ILogger;

/**
 * Obsluha neočekávaných chyb (5xx): chybu zaloguje a zobrazí obecnou stránku bez detailů.
 */
#[Requires(forward: true)]
final class Error5xxPresenter implements IPresenter
{
    public function __construct(
        private readonly ILogger $logger,
        private readonly IRequest $httpRequest,
    ) {
    }

    public function run(Request $request): Response
    {
        $exception = $request->getParameter('exception');
        if ($exception instanceof \Throwable) {
            $this->logger->log($exception, ILogger::EXCEPTION);
        }

        if ($this->httpRequest->isAjax()) {
            return new JsonResponse([
                'toasts' => [['type' => 'danger', 'message' => 'Došlo k neočekávané chybě. Byla zaznamenána a budeme ji řešit.']],
            ]);
        }

        return new CallbackResponse(function (IRequest $httpRequest, IResponse $httpResponse): void {
            if (preg_match('#^text/html(?:;|$)#', (string) $httpResponse->getHeader('Content-Type')) === 1) {
                require __DIR__ . '/500.phtml';
            }
        });
    }
}
