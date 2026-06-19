<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\ChatProviderInterface;
use App\DTO\CanvasGenerationRequest;
use App\DTO\CanvasGenerationResponse;
use App\DTO\CanvasPromptInput;
use App\DTO\IndexDocument;
use App\Service\Ai\Chat\CanvasPromptBuilder;
use JsonException;
use RuntimeException;
use Throwable;

final class CanvasGenerationService
{
    public function __construct(
        private readonly ChatProviderInterface $chatProvider,
        private readonly CanvasPromptBuilder $promptBuilder,
        private readonly VectorContextRetriever $vectorContextRetriever,
        private readonly IndexDocumentProcessor $indexDocumentProcessor,
        private readonly QdrantClient $qdrantClient,
    ) {
    }

    public function generate(CanvasGenerationRequest $request): CanvasGenerationResponse
    {
        $context = $this->buildContext($request);
        $qdrantHealth = $this->qdrantClient->health();
        $vectorContext = $this->vectorContextRetriever->retrieve($request->message, $request->tenant, 2);
        $generationResponse = null;

        try {
            $response = $this->chatProvider->chat(
                message: $request->message,
                context: $context,
                tenant: $request->tenant,
                locale: $request->locale,
                history: $request->history,
                vectorContext: $vectorContext,
                qdrantHealth: $qdrantHealth,
                extraInstruction: '',
                systemPrompt: $this->promptBuilder->buildSystemPrompt(),
                userPrompt: $this->promptBuilder->buildUserPrompt(new CanvasPromptInput(
                    message: $request->message,
                    context: $context,
                    tenant: $request->tenant,
                    locale: $request->locale,
                    mode: $request->mode,
                    canvas: $request->canvas,
                    elements: $request->elements,
                    history: $request->history,
                    vectorContext: $vectorContext,
                    qdrantHealth: $qdrantHealth,
                    metadata: $request->metadata,
                    snapshot: $request->snapshot,
                )),
            );
            $content = trim((string) ($response['content'] ?? ''));
        } catch (Throwable $exception) {
            $generationResponse = new CanvasGenerationResponse(
                ok: false,
                message: 'No fue posible generar la respuesta del canvas.',
                design: null,
                actions: [],
                raw: ['error' => $exception->getMessage()],
            );
            $this->indexCanvasOperation($request, $generationResponse, $vectorContext);

            return $generationResponse;
        }

        $decoded = $this->decodeJson($content);
        if (!is_array($decoded)) {
            $generationResponse = new CanvasGenerationResponse(
                ok: false,
                message: 'El modelo no devolvio JSON valido para canvas.',
                design: null,
                actions: [],
                raw: [
                    'content' => $content,
                    'vector_context' => $vectorContext,
                ],
            );
            $this->indexCanvasOperation($request, $generationResponse, $vectorContext);

            return $generationResponse;
        }

        $ok = $this->normalizeBool($decoded['ok'] ?? true);
        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            $message = $ok ? 'Canvas generado correctamente.' : 'No fue posible generar el canvas.';
        }

        $design = is_array($decoded['design'] ?? null) ? $decoded['design'] : null;
        $actions = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];
        $raw = [
            'assistant_response' => $response,
            'payload' => $decoded,
            'vector_context' => $vectorContext,
            'qdrant_health' => $qdrantHealth,
        ];

        $generationResponse = new CanvasGenerationResponse(
            ok: $ok,
            message: $message,
            design: $design,
            actions: $actions,
            raw: $raw,
        );

        $this->indexCanvasOperation($request, $generationResponse, $vectorContext);

        return $generationResponse;
    }

    private function buildContext(CanvasGenerationRequest $request): array
    {
        return [
            'pathname' => trim((string) ($request->context['pathname'] ?? '')),
            'tenant' => $request->tenant,
            'locale' => $request->locale,
            'application_locale' => (string) ($request->context['application_locale'] ?? $request->locale),
            'message_locale' => (string) ($request->context['message_locale'] ?? 'unknown'),
            'response_locale' => (string) ($request->context['response_locale'] ?? $request->locale),
            'mode' => $request->mode,
        ];
    }

    private function indexCanvasOperation(CanvasGenerationRequest $request, CanvasGenerationResponse $response, array $vectorContext): void
    {
        try {
            $document = IndexDocument::fromArray([
                'id' => sha1(implode('|', [
                    'canvas',
                    $request->tenant,
                    $request->locale,
                    $request->mode,
                    $request->message,
                ])),
                'type' => 'canvas_operation',
                'source' => 'asistente_camvasia',
                'tenant' => $request->tenant,
                'title' => $this->buildTitle($request->message),
                'content' => $this->buildContent($request, $response),
                'metadata' => [
                    'document_kind' => 'canvas_operational',
                    'message' => $request->message,
                    'mode' => $request->mode,
                    'canvas' => $request->canvas,
                    'elements' => $request->elements,
                    'context' => $request->context,
                    'response' => $response->toArray(),
                    'vector_context' => $vectorContext,
                ],
                'operation' => 'upsert',
            ]);

            $this->indexDocumentProcessor->process($document);
        } catch (Throwable) {
            // La indexacion no debe romper la respuesta del canvas.
        }
    }

    private function buildTitle(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Canvas operativo';
        }

        return mb_substr($message, 0, 80);
    }

    private function buildContent(CanvasGenerationRequest $request, CanvasGenerationResponse $response): string
    {
        try {
            return json_encode([
                'request' => $request->toArray(),
                'response' => $response->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('No fue posible serializar el documento canvas a JSON.', 0, $exception);
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function decodeJson(string $content): ?array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
