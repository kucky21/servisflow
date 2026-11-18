<?php

declare(strict_types=1);

namespace App\Presentation\Document;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\DomainException;
use App\Exceptions\EntityNotFoundException;
use App\Model\Enum\DocumentType;
use App\Presentation\BasePresenter;
use App\Security\RequirePermission;
use App\Services\Document\DocumentService;
use App\Services\Document\GeneratedDocument;
use Nette\Application\Responses\CallbackResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;

/**
 * PDF dokumenty zakázky. Oprávnění na konkrétní zakázku kontroluje DocumentService.
 */
#[RequirePermission('document', 'view')]
final class DocumentPresenter extends BasePresenter
{
    public function __construct(
        private readonly DocumentService $documentService,
    ) {
        parent::__construct();
    }

    public function actionIntake(int $id): void
    {
        $this->sendDocument(DocumentType::Intake, $id);
    }

    public function actionService(int $id): void
    {
        $this->sendDocument(DocumentType::ServiceReport, $id);
    }

    public function actionPricing(int $id): void
    {
        $this->sendDocument(DocumentType::PriceBreakdown, $id);
    }

    private function sendDocument(DocumentType $type, int $orderId): void
    {
        try {
            $document = $this->documentService->generate($type, $orderId, $this->getActor());
        } catch (EntityNotFoundException) {
            $this->error('Zakázka nebyla nalezena.');
        } catch (AccessDeniedException $e) {
            $this->error($e->getMessage(), IResponse::S403_Forbidden);
        } catch (DomainException $e) {
            $this->flashMessage($e->getMessage(), 'warning');
            $this->redirect('Order:detail', $orderId);
        }

        $this->sendResponse(new CallbackResponse(
            static function (IRequest $request, IResponse $response) use ($document): void {
                self::output($document, $response);
            },
        ));
    }

    private static function output(GeneratedDocument $document, IResponse $response): void
    {
        $response->setContentType($document->contentType);
        $response->setHeader('Content-Disposition', 'inline; filename="' . $document->fileName . '"');
        $response->setHeader('Content-Length', (string) strlen($document->content));
        $response->setHeader('Cache-Control', 'private, no-store');
        echo $document->content;
    }
}
