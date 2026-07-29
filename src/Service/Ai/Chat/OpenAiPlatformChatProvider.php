<?php

declare(strict_types=1);

namespace App\Service\Ai\Chat;

use App\Contract\ChatProviderInterface;
use App\DTO\ChatPromptInput;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OpenAiPlatformChatProvider implements ChatProviderInterface
{
    public function __construct(
        #[Autowire(service: 'ai.traceable_platform.openai')]
        private readonly PlatformInterface $platform,
        private readonly ChatPromptBuilder $promptBuilder,
        #[Autowire('%app.chat_model%')]
        private readonly string $chatModel,
    ) {
    }

    public function chat(
        string $message,
        array $context,
        string $tenant,
        string $locale,
        array $history,
        array $vectorContext,
        array $qdrantHealth,
        string $extraInstruction = '',
        ?string $systemPrompt = null,
        ?string $userPrompt = null,
    ): array {
        $input = new ChatPromptInput(
            $message,
            $context,
            $tenant,
            $locale,
            $history,
            $vectorContext,
            $qdrantHealth,
            $extraInstruction,
        );

        $messages = new MessageBag(
            new SystemMessage($systemPrompt ?? $this->promptBuilder->buildSystemPrompt()),
            ...$this->normalizeHistoryMessages($input->history),
        );

        $resolvedUserPrompt = trim((string) ($userPrompt ?? ''));
        if ($resolvedUserPrompt === '') {
            $resolvedUserPrompt = $this->promptBuilder->buildUserPrompt($input);
        }

        if ($resolvedUserPrompt !== '') {
            $messages->add(new UserMessage(new Text($resolvedUserPrompt)));
        }

        $result = (new Agent($this->platform, $this->chatModel))->call($messages);
        $content = trim((string) $result->getContent());

        if ($content === '') {
            throw new \RuntimeException('El servicio de chat no devolvio contenido util.');
        }

        $rawResult = $result->getRawResult();

        return [
            'content' => $content,
            'raw' => $rawResult?->getData() ?? [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     * @return array<int, SystemMessage|UserMessage|\Symfony\AI\Platform\Message\AssistantMessage>
     */
    private function normalizeHistoryMessages(array $history): array
    {
        $normalized = [];

        foreach (array_slice($history, -6) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = trim((string) ($item['role'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));

            if ($role === '' || $content === '') {
                continue;
            }

            if ($role === 'system') {
                $normalized[] = new SystemMessage($content);
                continue;
            }

            if ($role === 'assistant') {
                $normalized[] = new \Symfony\AI\Platform\Message\AssistantMessage(new Text($content));
                continue;
            }

            $normalized[] = new UserMessage(new Text($content));
        }

        return $normalized;
    }
}
