<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ChatProviderInterface;
use App\Service\Ai\Chat\ChatPromptBuilder;
use JsonException;

final class AssistantResponder
{
    public function __construct(
        private readonly VectorContextRetriever $vectorContextRetriever,
        private readonly ChatProviderInterface $chatProvider,
        private readonly ChatPromptBuilder $promptBuilder,
        private readonly int $defaultRetrieveLimit,
        private readonly string $greetingInstruction,
        private readonly string $clarificationInstruction,
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

        $searchPlan = $this->planSearch($message, $context, $tenant, $appLocale, $responseLocale, $history, $qdrantHealth);
        $plannedSearchQuery = trim((string) ($searchPlan['search_query'] ?? ''));
        $plannedResponseLocale = $this->normalizeLocale((string) ($searchPlan['response_locale'] ?? $responseLocale));

        if ($plannedSearchQuery === '') {
            $plannedSearchQuery = $message;
        }

        if ($plannedResponseLocale !== '') {
            $responseLocale = $plannedResponseLocale;
            $context['response_locale'] = $responseLocale;
        }

        $context['search_plan'] = $searchPlan;

        if (($searchPlan['should_search'] ?? true) !== true) {
            $vectorContext = [
                'ok' => true,
                'collection' => null,
                'tenant' => $tenant,
                'search_trace' => [],
                'matches' => [],
                'search_plan' => $searchPlan,
            ];
            $aiMessage = $this->resolveAiMessage($message, $context, $tenant, $responseLocale, $history, $vectorContext, $qdrantHealth, 'Usa la planificación de búsqueda como referencia, pero responde sin depender del RAG.');
        } else {
            $vectorContext = $this->vectorContextRetriever->retrieve(
                $plannedSearchQuery,
                $tenant,
                max(1, $this->defaultRetrieveLimit)
            );

            if (($vectorContext['ok'] ?? false) !== true || ($vectorContext['matches'] ?? []) === []) {
                $aiMessage = $this->resolveClarificationMessage($message, $context, $tenant, $responseLocale, $history, $vectorContext, $qdrantHealth);
            } else {
                $aiMessage = $this->resolveAiMessage($message, $context, $tenant, $responseLocale, $history, $vectorContext, $qdrantHealth, '');
            }
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
            $this->greetingInstruction
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
            $this->clarificationInstruction
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

    /**
     * @return array<string, mixed>
     */
    private function planSearch(string $message, array $context, string $tenant, string $appLocale, string $responseLocale, array $history, array $qdrantHealth): array
    {
        try {
            $response = $this->chatProvider->chat(
                message: $message,
                context: $context,
                tenant: $tenant,
                locale: $appLocale,
                history: $history,
                vectorContext: ['ok' => true, 'collection' => null, 'tenant' => $tenant, 'matches' => []],
                qdrantHealth: $qdrantHealth,
                extraInstruction: 'Devuelve solo JSON válido.',
                systemPrompt: $this->promptBuilder->buildSearchPlanSystemPrompt(),
                userPrompt: $this->promptBuilder->buildSearchPlanUserPrompt($message, $context, $tenant, $appLocale, $history)
            );

            $content = trim((string) ($response['content'] ?? ''));
            $decoded = $this->extractJson($content);
            if (is_array($decoded)) {
                return $this->normalizeSearchPlan($decoded, $appLocale, $responseLocale);
            }
        } catch (\Throwable) {
            // Si el planner falla, seguimos con un plan conservador.
        }

        return $this->normalizeSearchPlan([], $appLocale, $responseLocale);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function normalizeSearchPlan(array $plan, string $appLocale, string $fallbackResponseLocale): array
    {
        $responseLocale = $this->normalizeLocale((string) ($plan['response_locale'] ?? $fallbackResponseLocale));
        if ($responseLocale === '') {
            $responseLocale = $fallbackResponseLocale !== '' ? $fallbackResponseLocale : $appLocale;
        }

        $searchLocale = $this->normalizeLocale((string) ($plan['search_locale'] ?? 'es'));
        if ($searchLocale === '') {
            $searchLocale = 'es';
        }

        $searchQuery = trim((string) ($plan['search_query'] ?? ''));
        $shouldSearch = $this->normalizeBool($plan['should_search'] ?? true);
        $reason = trim((string) ($plan['reason'] ?? ''));

        return [
            'should_search' => $shouldSearch,
            'search_query' => $searchQuery,
            'search_locale' => $searchLocale,
            'response_locale' => $responseLocale,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
    }

    private function isGreeting(string $message): bool
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return false;
        }

        if (mb_strlen($normalized) > 20) {
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

        return in_array($normalized, $greetingPhrases, true);
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

        if ($bestScore < 2 || $bestScore === $secondScore) {
            return 'unknown';
        }

        return $bestLocale;
    }

    /**
     * @param list<string> $hints
     */
    private function scoreLocale(string $normalizedMessage, array $hints): int
    {
        $words = $this->tokenize($normalizedMessage);
        if ($words === []) {
            return 0;
        }

        $hintWords = [];
        foreach ($hints as $hint) {
            $hintWords = array_merge($hintWords, $this->tokenize($hint));
        }

        if ($hintWords === []) {
            return 0;
        }

        return count(array_intersect($words, array_values(array_unique($hintWords))));
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $value), static fn (string $word): bool => $word !== ''));
    }
}
