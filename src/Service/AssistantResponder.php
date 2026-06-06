<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ChatProviderInterface;

final class AssistantResponder
{
    public function __construct(
        private readonly VectorContextRetriever $vectorContextRetriever,
        private readonly ChatProviderInterface $chatProvider,
    ) {
    }

    public function respond(string $message, array $context, string $tenant, array $qdrantHealth): array
    {
        $contextPath = trim((string) ($context['pathname'] ?? ''));
        $contextNote = $contextPath !== '' ? sprintf('Contexto actual: %s.', $contextPath) : '';

        if ($this->isGreeting($message)) {
            $aiMessage = $this->resolveGreetingMessage($message, $context, $tenant, $qdrantHealth);

            return [
                'intent' => 'greeting',
                'message' => $aiMessage,
                'links' => [],
                'context_note' => $contextNote,
                'sources' => [
                    'ok' => true,
                    'collection' => null,
                    'tenant' => $tenant,
                    'matches' => [],
                ],
            ];
        }

        $vectorContext = $this->vectorContextRetriever->retrieve($message, $tenant);

        if (($vectorContext['ok'] ?? false) !== true || ($vectorContext['matches'] ?? []) === []) {
            $aiMessage = $this->resolveClarificationMessage($message, $context, $tenant, $vectorContext, $qdrantHealth);
        } else {
            $aiMessage = $this->resolveAiMessage($message, $context, $tenant, $vectorContext, $qdrantHealth);
        }

        return [
            'intent' => 'assistant_ai',
            'message' => $aiMessage,
            'links' => $this->extractLinks($vectorContext['matches'] ?? []),
            'context_note' => $contextNote,
            'sources' => $vectorContext,
        ];
    }

    private function resolveGreetingMessage(string $message, array $context, string $tenant, array $qdrantHealth): string
    {
        $promptVectorContext = [
            'ok' => true,
            'collection' => null,
            'tenant' => $tenant,
            'matches' => [],
        ];

        return $this->resolveAiMessage($message, $context, $tenant, $promptVectorContext, $qdrantHealth, 'Responde el saludo de forma amable, breve y natural en español. No menciones Qdrant ni contexto vectorial.');
    }

    private function resolveClarificationMessage(string $message, array $context, string $tenant, array $vectorContext, array $qdrantHealth): string
    {
        return $this->resolveAiMessage($message, $context, $tenant, $vectorContext, $qdrantHealth, 'No tengas suficiente informacion para responder con certeza. Responde de forma breve, pide una aclaracion concreta y no menciones aspectos tecnicos ni errores internos.');
    }

    private function resolveAiMessage(string $message, array $context, string $tenant, array $vectorContext, array $qdrantHealth, string $extraInstruction): string
    {
        try {
            $response = $this->chatProvider->chat($message, $context, $tenant, $vectorContext, $qdrantHealth, $extraInstruction);
            $content = trim((string) ($response['content'] ?? ''));

            return $content;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('No fue posible generar la respuesta del asistente: %s', $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     * @return array<int, array{label:string, href:string}>
     */
    private function extractLinks(array $matches): array
    {
        $links = [];

        foreach ($matches as $match) {
            $metadata = is_array($match['metadata'] ?? null) ? $match['metadata'] : [];
            $href = trim((string) ($metadata['route'] ?? $metadata['href'] ?? $metadata['url'] ?? ''));
            if ($href === '' || isset($links[$href])) {
                continue;
            }

            $label = trim((string) ($match['title'] ?? $metadata['label'] ?? $metadata['name'] ?? $match['type'] ?? ''));
            if ($label === '') {
                $label = $href;
            }

            $links[$href] = [
                'label' => $label,
                'href' => $href,
            ];
        }

        return array_values($links);
    }

    private function isGreeting(string $message): bool
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return false;
        }

        $greetingPhrases = [
            'hola',
            'buenas',
            'buenos dias',
            'buenas tardes',
            'buenas noches',
            'que tal',
            'saludos',
        ];

        foreach ($greetingPhrases as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $replacements = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ];

        return strtr($value, $replacements);
    }
}
