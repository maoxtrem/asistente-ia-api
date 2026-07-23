<?php

declare(strict_types=1);

namespace App\MessageHandler\AsistenteIA;

use OSP\Message\AsistenteIA\ChatToolIAPdfResponse;
use OSP\Message\AsistenteIA\ChatToolPdfMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ChatToolPdfMessageHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ChatToolPdfMessage $message): void
    {
        $this->logger->info('[ChatToolPdfMessageHandler] Mensaje recibido.', [
            'chat_id' => $message->getChatId(),
            'user_identifier' => $message->getUserIdentifier(),
            'message' => $message->getMessage(),
            'tool_enabled' => $message->isToolEnabled(),
            'tenant' => $message->getTenant(),
            'locale' => $message->getLocale(),
            'attachment_key' => $message->getAttachmentKey(),
            'session' => $message->getSession(),
            'history' => $message->getHistory(),
            'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);

        $this->messageBus->dispatch(new ChatToolIAPdfResponse(
            chatId: $message->getChatId(),
            userIdentifier: $message->getUserIdentifier(),
            content: "hola soy la ia",
            pdfUrl: null,
            mercureTopic: $message->getMercureTopic(),
            originalNameAttachment: null,
            attachmentPath: $message->getAttachmentKey(),
            createdAt: $message->getCreatedAt(),
        ));
    }
}
