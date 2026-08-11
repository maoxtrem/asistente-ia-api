Revisa exclusivamente la imagen adjunta.

Tu objetivo es determinar si la imagen contiene información útil y relevante para un proyecto de construcción, clasificándola en UNA de estas dos categorías válidas:
1. Un plano arquitectónico, técnico o constructivo (que contenga trazados, cotas, medidas, espacios, materiales, símbolos o anotaciones técnicas).
2. Una cotización, presupuesto, factura o documento estimado con datos numéricos, costos, materiales o ítems de construcción.

Marca la imagen como no relevante únicamente si está vacía, ilegible, es puramente decorativa, una fotografía sin datos técnicos, o cualquier contenido que no sirva para analizar un plano o una cotización.

Responde únicamente con JSON válido, sin texto adicional y usando exactamente estos campos:
{
  "approved": bool,
  "confidence_score": int,
  "reasoning": "string",
  "document_type":"null||string"
}

Reglas estrictas:
- approved debe ser un booleano.
- confidence_score debe ser un entero entre 0 y 10.
- reasoning debe explicar brevemente la evidencia visible que fundamenta la decisión (menciona si encontraste elementos de plano o de datos financieros).
- si se deternina que es un plano o algo financiero como una factura o cotizacion, costos etc. document_type solo puede ser (plano o financiero) de lo contrario es null
- Usa approved=true SI Y SOLO SI la imagen contiene información útil y legible de un plano O de una cotización de construcción válida.
- En cualquier otro caso, usa approved=false.