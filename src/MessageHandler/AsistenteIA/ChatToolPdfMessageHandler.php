<?php

declare(strict_types=1);

namespace App\MessageHandler\AsistenteIA;

use OSP\Message\AsistenteIA\ChatToolPdfMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Service\ChatToolPdf\ChatToolPdfMessageProcessor;

#[AsMessageHandler]
final readonly class ChatToolPdfMessageHandler
{
    public function __construct(
        private ChatToolPdfMessageProcessor $messageProcessor,
    ) {
    }

    public function __invoke(ChatToolPdfMessage $message): void
    {
        $this->messageProcessor->process($message);
    }
}
