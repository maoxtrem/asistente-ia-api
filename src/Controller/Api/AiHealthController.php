<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Contract\ChatProviderInterface;
use Throwable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AiHealthController
{
    public function __construct(
        private readonly ChatProviderInterface $chatProvider,
    ) {
    }

    #[Route('/api/ia/health', name: 'api_ia_health', methods: ['POST'])]
    #[Route('/api/ai/health', name: 'api_ai_health', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $question = trim((string) ($payload['question'] ?? $payload['message'] ?? ''));
        $tenant = trim((string) ($payload['tenant'] ?? 'test'));
        $locale = $this->normalizeLocale($payload['locale'] ?? 'es');

        if ($question === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'question is required.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $response = $this->chatProvider->chat(
                message: $question,
                context: is_array($payload['context'] ?? null) ? $payload['context'] : [],
                tenant: $tenant,
                locale: $locale,
                history: [],
                vectorContext: ['ok' => true, 'collection' => null, 'matches' => []],
                qdrantHealth: ['ok' => true],
                extraInstruction: 'This is a pure connectivity test endpoint. Answer the question directly and briefly. Return plain text unless the question explicitly requires structured output.',
                systemPrompt: 'You are a minimal connectivity test for an AI microservice. Answer the user question directly, briefly, and without internal details.',
                userPrompt: $this->buildUserPrompt($question, $tenant, $locale),
            );
        } catch (Throwable $exception) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'No fue posible consultar la IA.',
                'raw' => [
                    'error' => $exception->getMessage(),
                ],
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        $content = trim((string) ($response['content'] ?? ''));
        if ($content === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'La IA no devolvio contenido util.',
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        $decoded = $this->decodeJson($content);

        return new JsonResponse([
            'status' => 'success',
            'data' => [
                'ok' => true,
                'question' => $question,
                'tenant' => $tenant,
                'locale' => $locale,
                'parsed' => $decoded !== null,
                'answer' => $decoded['answer'] ?? $content,
                'ai' => $decoded,
                'raw' => $content,
            ],
        ]);
    }

    private function buildUserPrompt(string $question, string $tenant, string $locale): string
    {
        return json_encode([
            'task' => 'connectivity_test',
            'tenant' => $tenant,
            'locale' => $locale,
            'question' => $question,
            'output' => [
                'answer' => 'short direct answer',
                'format' => 'plain text or JSON if explicitly requested by the user',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $question;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($content, $start, $end - $start + 1);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeLocale(mixed $locale): string
    {
        $normalized = strtolower(trim((string) ($locale ?? '')));

        return $normalized !== '' ? str_replace('_', '-', $normalized) : 'es';
    }
}
