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
        $requestIndexation = $this->indexCanvasOperation($request);
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
                    incomingVectorContext: $request->vectorContext,
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

            return $generationResponse;
        }

        $ok = $this->normalizeBool($decoded['ok'] ?? true);
        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            $message = $ok ? 'Canvas generado correctamente.' : 'No fue posible generar el canvas.';
        }

        $designCandidate = is_array($decoded['design'] ?? null) ? $decoded['design'] : $decoded;
        $design = $this->normalizeDesignExport($designCandidate, $request->snapshot);
        $actions = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];

        $generationResponse = new CanvasGenerationResponse(
            ok: $ok,
            message: $message,
            design: $design,
            actions: $actions,
            raw: [
                'assistant_response' => $response,
                'payload' => $decoded,
                'vector_context' => $vectorContext,
                'qdrant_health' => $qdrantHealth,
                'request_indexation' => $requestIndexation,
            ],
        );

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

    /**
     * @return array{ok:bool, collection:?string, point_id:?string, error:?string}
     */
    private function indexCanvasOperation(CanvasGenerationRequest $request): array
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
                'content' => $this->buildContent($request),
                'metadata' => [
                    'document_kind' => 'canvas_operational',
                    'message' => $request->message,
                    'mode' => $request->mode,
                    'canvas' => $request->canvas,
                    'elements' => $request->elements,
                    'context' => $request->context,
                    'incoming_vector_context' => $request->vectorContext,
                    'vector_context' => $request->vectorContext,
                ],
                'operation' => 'upsert',
            ]);

            $indexResponse = $this->indexDocumentProcessor->process($document);

            return [
                'ok' => true,
                'collection' => $indexResponse->collection,
                'point_id' => $indexResponse->pointId,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'collection' => null,
                'point_id' => null,
                'error' => $exception->getMessage(),
            ];
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

    private function buildContent(CanvasGenerationRequest $request): string
    {
        try {
            return json_encode([
                'summary' => $this->buildCanvasSummary($request),
                'request' => $request->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('No fue posible serializar el documento canvas a JSON.', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCanvasSummary(CanvasGenerationRequest $request): array
    {
        $canvas = $request->canvas;
        $snapshot = $request->snapshot;
        $elements = array_values(is_array($snapshot['designElements'] ?? null)
            ? $snapshot['designElements']
            : (is_array($request->elements) ? $request->elements : []));

        $elementSummaries = array_values(array_filter(array_map(static function (array $element): ?array {
            $type = trim((string) ($element['type'] ?? ''));
            if ($type === '') {
                return null;
            }

            $label = trim((string) ($element['content'] ?? $element['title'] ?? $element['text'] ?? ''));
            if ($label === '' && isset($element['dataset']['snapshotType'])) {
                $label = trim((string) $element['dataset']['snapshotType']);
            }

            return [
                'type' => $type,
                'label' => $label,
                'id' => trim((string) ($element['id'] ?? '')),
            ];
        }, $elements)));

        return [
            'tenant' => $request->tenant,
            'locale' => $request->locale,
            'mode' => $request->mode,
            'message' => $request->message,
            'canvas_size' => $this->resolveCanvasSize($canvas, is_array($snapshot['design'] ?? null) ? $snapshot['design'] : []),
            'background_type' => $request->snapshot['backgroundType'] ?? ($canvas['backgroundType'] ?? null),
            'background_image' => $request->snapshot['backgroundImage'] ?? ($canvas['backgroundImage'] ?? null),
            'element_count' => count($elementSummaries),
            'elements' => $elementSummaries,
        ];
    }

    private function normalizeBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalizeDesignExport(array $candidate, array $snapshot): array
    {
        $template = $this->buildDesignTemplate($snapshot);
        $normalizedCandidate = $this->normalizeCandidateDesign($candidate, $snapshot);
        $normalized = $this->applyTemplateShape($normalizedCandidate, $template);

        return is_array($normalized) ? $normalized : $template;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildDesignTemplate(array $snapshot): array
    {
        $design = is_array($snapshot['design'] ?? null) ? $snapshot['design'] : [];
        $canvas = is_array($snapshot['canvas'] ?? null) ? $snapshot['canvas'] : [];
        $elements = array_values(is_array($snapshot['designElements'] ?? null)
            ? $snapshot['designElements']
            : (is_array($snapshot['elements'] ?? null) ? $snapshot['elements'] : []));

        return [
            'id' => $snapshot['id'] ?? null,
            'name_usar_medida' => $snapshot['name_usar_medida'] ?? ($snapshot['usarMedidas'] ?? null),
            'token' => $snapshot['token'] ?? null,
            'url' => $snapshot['url'] ?? null,
            'backgroundType' => $snapshot['backgroundType'] ?? ($canvas['backgroundType'] ?? null),
            'borderStyle' => $snapshot['borderStyle'] ?? ($design['borde'] ?? null),
            'canvasSize' => $snapshot['canvasSize'] ?? $this->resolveCanvasSize($canvas, $design),
            'nombreCampana' => $snapshot['nombreCampana'] ?? null,
            'primaryColor' => $snapshot['primaryColor'] ?? ($design['fondo'] ?? ($canvas['colors']['primary'] ?? null)),
            'secondaryColor' => $snapshot['secondaryColor'] ?? ($design['acento'] ?? ($canvas['colors']['secondary'] ?? null)),
            'designElements' => $elements,
            'backgroundImage' => $snapshot['backgroundImage'] ?? ($canvas['backgroundImage'] ?? null),
            'fotoMostrar' => $snapshot['fotoMostrar'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalizeCandidateDesign(array $candidate, array $snapshot): array
    {
        $normalized = $candidate;
        $designMeta = is_array($candidate['design'] ?? null) ? $candidate['design'] : [];

        if (!array_key_exists('designElements', $normalized) && array_key_exists('elements', $normalized)) {
            $normalized['designElements'] = $normalized['elements'];
        }

        if (!array_key_exists('backgroundType', $normalized) && array_key_exists('canvas', $normalized) && is_array($normalized['canvas'])) {
            $normalized['backgroundType'] = $normalized['canvas']['backgroundType'] ?? ($snapshot['backgroundType'] ?? null);
        }

        if (!array_key_exists('backgroundImage', $normalized) && array_key_exists('canvas', $normalized) && is_array($normalized['canvas'])) {
            $normalized['backgroundImage'] = $normalized['canvas']['backgroundImage'] ?? ($snapshot['backgroundImage'] ?? null);
        }

        if (!array_key_exists('primaryColor', $normalized)) {
            $normalized['primaryColor'] = $candidate['primaryColor'] ?? ($designMeta['fondo'] ?? ($snapshot['primaryColor'] ?? null));
        }

        if (!array_key_exists('secondaryColor', $normalized)) {
            $normalized['secondaryColor'] = $candidate['secondaryColor'] ?? ($designMeta['acento'] ?? ($snapshot['secondaryColor'] ?? null));
        }

        if (!array_key_exists('borderStyle', $normalized)) {
            $normalized['borderStyle'] = $candidate['borderStyle'] ?? ($designMeta['borde'] ?? ($snapshot['borderStyle'] ?? null));
        }

        if (!array_key_exists('canvasSize', $normalized)) {
            $normalized['canvasSize'] = $candidate['canvasSize'] ?? ($designMeta['lienzo'] ?? ($snapshot['canvasSize'] ?? null));
        }

        if (!array_key_exists('name_usar_medida', $normalized)) {
            $normalized['name_usar_medida'] = $candidate['name_usar_medida'] ?? ($snapshot['name_usar_medida'] ?? null);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $canvas
     * @param array<string, mixed> $design
     */
    private function resolveCanvasSize(array $canvas, array $design): ?string
    {
        $width = $canvas['width'] ?? null;
        $height = $canvas['height'] ?? null;

        if (is_numeric($width) && is_numeric($height) && (int) $width > 0 && (int) $height > 0) {
            return (int) $width . 'x' . (int) $height;
        }

        $size = $design['lienzo'] ?? null;
        if (is_string($size) && preg_match('/^\s*\d+\s*x\s*\d+\s*$/i', $size)) {
            return trim($size);
        }

        return null;
    }

    /**
     * @param mixed $candidate
     * @param mixed $template
     * @return mixed
     */
    private function applyTemplateShape(mixed $candidate, mixed $template): mixed
    {
        if (is_array($template)) {
            if (array_is_list($template)) {
                if (!is_array($candidate)) {
                    return $template;
                }

                if ($template === []) {
                    return array_values($candidate);
                }

                $candidateList = array_values($candidate);
                $normalizedList = [];
                $templateCount = count($template);

                foreach ($candidateList as $index => $item) {
                    $shape = $template[$index] ?? $template[$templateCount - 1];
                    $normalizedList[] = $this->applyTemplateShape($item, $shape);
                }

                return $normalizedList;
            }

            $candidateArray = is_array($candidate) ? $candidate : [];
            $normalized = [];

            foreach ($template as $key => $templateValue) {
                $normalized[$key] = array_key_exists($key, $candidateArray)
                    ? $this->applyTemplateShape($candidateArray[$key], $templateValue)
                    : $templateValue;
            }

            return $normalized;
        }

        return $candidate ?? $template;
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
