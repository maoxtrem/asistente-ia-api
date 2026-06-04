<?php

declare(strict_types=1);

namespace App\Service;

final class AssistantResponder
{
    public function respond(string $message, array $context, array $qdrantHealth): array
    {
        $normalized = $this->normalize($message);
        $contextPath = trim((string) ($context['pathname'] ?? ''));
        $contextNote = $contextPath !== '' ? sprintf('Contexto actual: %s.', $contextPath) : '';

        $intents = [
            'chat' => [
                'keywords' => ['chat', 'mensaje', 'mensajes', 'llamada', 'videollamada', 'video llamada', 'voz'],
                'message' => 'Puedo ayudarte con el modulo de chat, mensajes y llamadas. Si quieres, abre la bandeja del chat para continuar desde ahi.',
                'links' => [
                    ['label' => 'Abrir chat', 'href' => '/chat'],
                    ['label' => 'Ir al dashboard', 'href' => '/web/configuracion/dashboard-v2'],
                ],
            ],
            'facturas' => [
                'keywords' => ['factura', 'facturas', 'cobro', 'cobrar', 'pago', 'pagos'],
                'message' => 'Si necesitas gestionar cobros o revisar pagos, el modulo de facturas es el mejor punto de partida.',
                'links' => [
                    ['label' => 'Ver facturas', 'href' => '/web/operacionales/facturas'],
                    ['label' => 'Ir al dashboard', 'href' => '/web/configuracion/dashboard-v2'],
                ],
            ],
            'cotizaciones' => [
                'keywords' => ['cotizacion', 'cotizaciones', 'estimado', 'estimados', 'presupuesto'],
                'message' => 'Para preparar propuestas o revisar estimados, te conviene entrar al modulo de cotizaciones.',
                'links' => [
                    ['label' => 'Abrir cotizaciones', 'href' => '/web/operacionales/cotizaciones'],
                    ['label' => 'Ver clientes', 'href' => '/web/administrativos/clientes'],
                ],
            ],
            'clientes' => [
                'keywords' => ['cliente', 'clientes', 'contacto', 'contactos'],
                'message' => 'Si la ayuda que necesitas es sobre clientes o contactos, puedo llevarte al modulo donde los administras.',
                'links' => [
                    ['label' => 'Ver clientes', 'href' => '/web/administrativos/clientes'],
                    ['label' => 'Ver proyectos', 'href' => '/web/administrativos/proyectos'],
                ],
            ],
            'agenda' => [
                'keywords' => ['agenda', 'cita', 'citas', 'recordatorio', 'recordatorios', 'evento', 'eventos'],
                'message' => 'Para revisar pendientes, recordatorios o eventos, abre la agenda operativa.',
                'links' => [
                    ['label' => 'Abrir agenda', 'href' => '/web/operacionales/agenda'],
                    ['label' => 'Ir al dashboard', 'href' => '/web/configuracion/dashboard-v2'],
                ],
            ],
            'usuarios' => [
                'keywords' => ['usuario', 'usuarios', 'permiso', 'permisos', 'rol', 'roles', 'acceso'],
                'message' => 'La administracion de usuarios, accesos y permisos se maneja desde configuracion.',
                'links' => [
                    ['label' => 'Gestionar usuarios', 'href' => '/web/configuracion/usuarios'],
                    ['label' => 'Gestionar grupos', 'href' => '/web/configuracion/grupos'],
                ],
            ],
            'proyectos' => [
                'keywords' => ['proyecto', 'proyectos', 'obra', 'obras'],
                'message' => 'Si necesitas revisar obras o proyectos asociados a clientes, te llevo al modulo correspondiente.',
                'links' => [
                    ['label' => 'Ver proyectos', 'href' => '/web/administrativos/proyectos'],
                    ['label' => 'Ver clientes', 'href' => '/web/administrativos/clientes'],
                ],
            ],
            'proveedores' => [
                'keywords' => ['proveedor', 'proveedores', 'gasto', 'gastos', 'cheque', 'cheques'],
                'message' => 'Para proveedores, gastos o emision de cheques, estos modulos suelen ser los mas utiles.',
                'links' => [
                    ['label' => 'Ver proveedores', 'href' => '/web/administrativos/proveedores'],
                    ['label' => 'Ver gastos', 'href' => '/web/operacionales/gastos'],
                    ['label' => 'Emision de cheques', 'href' => '/web/configuracion/emision/cheques'],
                ],
            ],
            'servicios' => [
                'keywords' => ['servicio', 'servicios', 'subservicio', 'subservicios'],
                'message' => 'La administracion del catalogo de servicios esta separada en servicios y subservicios.',
                'links' => [
                    ['label' => 'Ver servicios', 'href' => '/web/administrativos/servicios'],
                    ['label' => 'Ver subservicios', 'href' => '/web/administrativos/subservicios'],
                ],
            ],
            'dashboard' => [
                'keywords' => ['inicio', 'dashboard', 'panel', 'resumen', 'principal'],
                'message' => 'Si quieres una vista general del sistema, el dashboard es el mejor lugar para empezar.',
                'links' => [
                    ['label' => 'Ir al dashboard', 'href' => '/web/configuracion/dashboard-v2'],
                    ['label' => 'Ver agenda', 'href' => '/web/operacionales/agenda'],
                ],
            ],
        ];

        foreach ($intents as $intent => $definition) {
            if ($this->containsAny($normalized, $definition['keywords'])) {
                return [
                    'intent' => $intent,
                    'message' => $this->appendSystemNote($definition['message'], $qdrantHealth),
                    'links' => $definition['links'],
                    'context_note' => $contextNote,
                ];
            }
        }

        return [
            'intent' => 'general_help',
            'message' => $this->appendSystemNote(
                'Puedo orientarte en modulos como clientes, proyectos, cotizaciones, facturas, agenda, usuarios o chat. Si me dices sobre que area necesitas ayuda, te guio con mas precision.',
                $qdrantHealth
            ),
            'links' => [
                ['label' => 'Ir al dashboard', 'href' => '/web/configuracion/dashboard-v2'],
                ['label' => 'Ver clientes', 'href' => '/web/administrativos/clientes'],
                ['label' => 'Abrir chat', 'href' => '/chat'],
            ],
            'context_note' => $contextNote,
        ];
    }

    private function containsAny(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($message, $this->normalize((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    private function appendSystemNote(string $message, array $qdrantHealth): string
    {
        if (($qdrantHealth['ok'] ?? false) !== true) {
            return $message . ' Nota tecnica: la conexion con Qdrant no esta disponible en este momento.';
        }

        $collections = $qdrantHealth['collections'] ?? [];
        if (is_array($collections) && count($collections) > 0) {
            return $message . sprintf(' Nota tecnica: Qdrant esta activo y tiene %d colecciones disponibles para contexto.', count($collections));
        }

        return $message . ' Nota tecnica: Qdrant esta activo, pero aun no hay colecciones cargadas para contexto semantico.';
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
