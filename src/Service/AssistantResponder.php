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

    public function respond(string $message, array $context, string $tenant, string $locale, string $conversationId, array $history, array $qdrantHealth): array
    {
        $appLocale = $this->normalizeLocale($locale);
        $detectedMessageLocale = $this->detectMessageLocale($message);
        $responseLocale = $detectedMessageLocale !== 'unknown' ? $detectedMessageLocale : $appLocale;
        $context['application_locale'] = $appLocale;
        $context['message_locale'] = $detectedMessageLocale;
        $context['response_locale'] = $responseLocale;
        $contextPath = trim((string) ($context['pathname'] ?? ''));
        $contextNote = $contextPath !== '' ? sprintf('Contexto actual: %s.', $contextPath) : '';

        if ($this->isGreeting($message)) {
            $aiMessage = $this->resolveGreetingMessage($message, $context, $tenant, $responseLocale, $history, $qdrantHealth);

            return [
                'intent' => 'greeting',
                'message' => $aiMessage,
                'message_locale' => $detectedMessageLocale,
                'response_locale' => $responseLocale,
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

        $vectorContext = $this->vectorContextRetriever->retrieve($message, $tenant, 2);

        if (($vectorContext['ok'] ?? false) !== true || ($vectorContext['matches'] ?? []) === []) {
            $aiMessage = $this->resolveClarificationMessage($message, $context, $tenant, $responseLocale, $history, $vectorContext, $qdrantHealth);
        } else {
            $aiMessage = $this->resolveAiMessage($message, $context, $tenant, $responseLocale, $history, $vectorContext, $qdrantHealth, '');
        }

        return [
            'intent' => 'assistant_ai',
            'message' => $aiMessage,
            'message_locale' => $detectedMessageLocale,
            'response_locale' => $responseLocale,
            'links' => $this->extractLinks($vectorContext['matches'] ?? []),
            'context_note' => $contextNote,
            'sources' => $vectorContext,
        ];
    }

    private function resolveGreetingMessage(string $message, array $context, string $tenant, string $locale, array $history, array $qdrantHealth): string
    {
        $promptVectorContext = [
            'ok' => true,
            'collection' => null,
            'tenant' => $tenant,
            'matches' => [],
        ];

        return $this->resolveAiMessage(
            $message,
            $context,
            $tenant,
            $locale,
            $history,
            $promptVectorContext,
            $qdrantHealth,
            $this->greetingInstruction()
        );
    }

    private function resolveClarificationMessage(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth): string
    {
        return $this->resolveAiMessage(
            $message,
            $context,
            $tenant,
            $locale,
            $history,
            $vectorContext,
            $qdrantHealth,
            $this->clarificationInstruction()
        );
    }

    private function resolveAiMessage(string $message, array $context, string $tenant, string $locale, array $history, array $vectorContext, array $qdrantHealth, string $extraInstruction = ''): string
    {
        try {
            $response = $this->chatProvider->chat($message, $context, $tenant, $locale, $history, $vectorContext, $qdrantHealth, $extraInstruction);
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

        $value = strtr($value, $replacements);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value;
    }

    private function greetingInstruction(): string
    {
        return 'Respond to the greeting in a friendly, brief, natural way. Match the language of the user’s latest message. If the language is unclear, use the application locale as fallback. Do not mention internal or technical details.';
    }

    private function clarificationInstruction(): string
    {
        return 'You do not have enough information to answer with confidence. Respond briefly, ask for one concrete clarification, and match the language of the user’s latest message. If the language is unclear, use the application locale as fallback. Do not mention technical or internal errors.';
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        return str_replace('_', '-', $normalized);
    }

    private function detectMessageLocale(string $message): string
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return 'unknown';
        }

        $scores = [
            'en' => $this->scoreLocale($normalized, [
                'hello', 'hi', 'hey', 'help', 'please', 'need', 'how', 'what', 'where', 'when', 'can', 'could',
                'invoice', 'invoices', 'customer', 'customers', 'project', 'projects', 'quote', 'quotes', 'schedule',
                'support', 'thanks', 'thank you', 'good morning', 'good afternoon', 'good evening',
            ]),
            'es' => $this->scoreLocale($normalized, [
                'hola', 'buenas', 'ayuda', 'por favor', 'necesito', 'como', 'que', 'donde', 'cuando', 'puedo',
                'factura', 'facturas', 'cliente', 'clientes', 'proyecto', 'proyectos', 'cotizacion', 'cotizaciones',
                'agenda', 'gracias', 'buen dia', 'buenos dias', 'buenas tardes', 'buenas noches',
            ]),
            'fr' => $this->scoreLocale($normalized, [
                'bonjour', 'salut', 'aide', 's il vous plait', 'besoin', 'comment', 'quoi', 'ou', 'quand',
                'facture', 'factures', 'client', 'clients', 'projet', 'projets', 'devis', 'merci',
            ]),
            'pt' => $this->scoreLocale($normalized, [
                'ola', 'olá', 'ajuda', 'por favor', 'preciso', 'como', 'onde', 'quando', 'fatura', 'faturas',
                'cliente', 'clientes', 'projeto', 'projetos', 'orcamento', 'orçamento', 'obrigado', 'obrigada',
            ]),
            'de' => $this->scoreLocale($normalized, [
                'hallo', 'hilfe', 'bitte', 'brauche', 'wie', 'was', 'wo', 'wann', 'rechnung', 'rechnungen',
                'kunde', 'kunden', 'projekt', 'projekte', 'angebot', 'danke',
            ]),
            'it' => $this->scoreLocale($normalized, [
                'ciao', 'salve', 'aiuto', 'per favore', 'ho bisogno', 'come', 'cosa', 'dove', 'quando', 'fattura',
                'fatture', 'cliente', 'clienti', 'progetto', 'progetti', 'preventivo', 'grazie',
            ]),
        ];

        arsort($scores);
        $bestLocale = (string) array_key_first($scores);
        $bestScore = (int) current($scores);
        $secondScore = (int) (array_values($scores)[1] ?? 0);

        if ($bestScore <= 0 || $bestScore === $secondScore) {
            return 'unknown';
        }

        return $bestLocale;
    }

    /**
     * @param list<string> $hints
     */
    private function scoreLocale(string $normalizedMessage, array $hints): int
    {
        $score = 0;

        foreach ($hints as $hint) {
            if ($hint !== '' && str_contains($normalizedMessage, $hint)) {
                $score++;
            }
        }

        return $score;
    }
}
