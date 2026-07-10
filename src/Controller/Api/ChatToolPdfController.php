<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\ChatToolPdf\ChatToolPdfGenerationService;
use App\Service\ChatToolPdf\PdfClient;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ChatToolPdfController
{
    public function __construct(
        private readonly ChatToolPdfGenerationService $generationService,
        private readonly PdfClient $servicePdfClient,
        private readonly LoggerInterface $logger,
        private readonly string $assistantName,
        private readonly string $chattoolpdfEnvironment,
    ) {
    }

    #[Route('/api/chattoolpdf', name: 'api_chattoolpdf', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El body debe ser un JSON valido.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $message = trim((string) ($payload['message'] ?? ($payload['question'] ?? '')));
        $conversationId = trim((string) ($payload['conversation_id'] ?? ''));
        $clientKey = trim((string) ($payload['client_key'] ?? ''));
        $tool = $this->normalizeBool($payload['tool'] ?? false);
        $tenant = trim((string) ($payload['tenant'] ?? ''));
        $usuario = trim((string) ($payload['usuario'] ?? ''));
        $locale = $this->normalizeLocale($payload['locale'] ?? '');
        $requestContext = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];

        if ($message === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El campo message es obligatorio.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($conversationId !== '' && (strlen($conversationId) !== 32 || !ctype_xdigit($conversationId))) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'El identificador de conversacion no es valido.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversationId = $this->resolveConversationId($conversationId);

        if ($tool && ($tenant === '' || $usuario === '')) {
            $missingFields = [];
            if ($tenant === '') {
                $missingFields[] = 'tenant';
            }
            if ($usuario === '') {
                $missingFields[] = 'usuario';
            }

            return new JsonResponse([
                'status' => 'pending',
                'message' => $this->buildIdentityClarificationMessage($missingFields),
                'missing_fields' => $missingFields,
                'assistant' => $this->assistantName,
                'conversation_id' => $conversationId,
                'client_key' => $clientKey,
                'tool' => $tool,
            ]);
        }

        try {
            if ($tool) {
                $aiResponse = $this->generationService->generateDocument(
                    message: $message,
                    tenant: $tenant,
                    usuario: $usuario,
                    entorno: $this->chattoolpdfEnvironment,
                    locale: $locale,
                    history: $history,
                    context: [
                        ...$requestContext,
                        'client_key' => $clientKey,
                        'metadata' => $metadata,
                    ]
                );
            } else {
                $aiResponse = $this->generationService->answerQuestion(
                    message: $message,
                    tenant: $tenant,
                    usuario: $usuario,
                    entorno: $this->chattoolpdfEnvironment,
                    locale: $locale,
                    history: $history,
                    context: [
                        ...$requestContext,
                        'client_key' => $clientKey,
                        'metadata' => $metadata,
                    ]
                );
            }
        } catch (RuntimeException $exception) {
            $this->logger->error('No fue posible procesar chattoolpdf.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
                'tool' => $tool,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            $this->logger->error('No fue posible procesar chattoolpdf.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
                'tool' => $tool,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Ocurrio un error interno al procesar la solicitud.',
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        if ($tool === false) {
            return new JsonResponse([
                'status' => 'success',
                'message' => (string) ($aiResponse['message'] ?? ''),
            ]);
        }

        if (($aiResponse['status'] ?? '') === 'needs_clarification') {
            $assistantMessage = trim((string) ($aiResponse['message'] ?? ''));
            $missingFields = is_array($aiResponse['missing_fields'] ?? null) ? array_values(array_map('strval', $aiResponse['missing_fields'])) : [];

            return new JsonResponse([
                'status' => 'pending',
                'message' => $assistantMessage,
                'missing_fields' => $missingFields,
            ]);
        }

        $pdfPayload = [
            'tenant' => $tenant,
            'usuario' => $usuario,
            'entorno' => $this->chattoolpdfEnvironment,
            'html' => (string) ($aiResponse['html'] ?? ''),
            'json' => is_array($aiResponse['json'] ?? null) ? $aiResponse['json'] : [],
            'paper_size' => (string) ($aiResponse['paper_size'] ?? 'A4'),
            'orientation' => (string) ($aiResponse['orientation'] ?? 'portrait'),
        ];

        if (trim($pdfPayload['html']) === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'La IA no devolvio un HTML valido para generar el PDF.',
                'raw' => $aiResponse,
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        try {
            $pdfResponse = $this->servicePdfClient->generate($pdfPayload);
        } catch (RuntimeException $exception) {
            $this->logger->error('No fue posible invocar service-pdf.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        } catch (Throwable $exception) {
            $this->logger->error('No fue posible completar la generacion del PDF.', [
                'exception' => $exception,
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'No fue posible completar la generacion del PDF.',
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        if (($pdfResponse['ok'] ?? false) !== true) {
            $statusCode = (int) ($pdfResponse['status_code'] ?? JsonResponse::HTTP_BAD_GATEWAY);
            $errorMessage = (string) ($pdfResponse['body']['error'] ?? $pdfResponse['message'] ?? 'service-pdf rechazo la solicitud.');

            $this->logger->error('service-pdf rechazo la solicitud.', [
                'conversation_id' => $conversationId,
                'tenant' => $tenant,
                'usuario' => $usuario,
                'status_code' => $statusCode,
                'response' => $pdfResponse,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $errorMessage,
                'raw' => $pdfResponse,
            ], $statusCode);
        }

        $assistantMessage = trim((string) ($aiResponse['message'] ?? ''));
        if ($assistantMessage === '') {
            $assistantMessage = (string) ($pdfResponse['message'] ?? 'PDF generado correctamente.');
        }

        $pdfUrl = (string) ($pdfResponse['pdf_url'] ?? '');

        return new JsonResponse([
            'status' => 'success',
            'message' => $assistantMessage,
            'pdf_url' => $pdfUrl,
        ]);
    }

    private function resolveConversationId(string $conversationId): string
    {
        if ($conversationId === '') {
            return bin2hex(random_bytes(16));
        }

        return strtolower($conversationId);
    }

    /**
     * @param array<int, string> $missingFields
     */
    private function buildIdentityClarificationMessage(array $missingFields): string
    {
        $missingFields = array_values(array_filter($missingFields, static fn (string $field): bool => $field !== ''));

        if ($missingFields === []) {
            return 'Necesito tenant y usuario para continuar.';
        }

        if ($missingFields === ['tenant', 'usuario']) {
            return 'Necesito tenant y usuario para poder generar el PDF.';
        }

        if ($missingFields === ['tenant']) {
            return 'Necesito el tenant para poder generar el PDF.';
        }

        if ($missingFields === ['usuario']) {
            return 'Necesito el usuario para poder generar el PDF.';
        }

        return 'Necesito completar los datos requeridos para generar el PDF.';
    }

    private function normalizeLocale(mixed $locale): string
    {
        $normalized = strtolower(trim((string) ($locale ?? '')));

        return str_replace('_', '-', $normalized);
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
}
