Actúa como un ingeniero de costos y especialista en materialidad de proyectos. Analiza la imagen del plano adjunto con una visión global y abierta. 

Tu objetivo no es buscar una lista predefinida de palabras, sino ANALIZAR e INTERPRETAR el documento en su totalidad. Observa las texturas gráficas, los sombreados (hatching), los cerramientos, las divisiones espaciales, las redes y cualquier apunte para deducir de qué materiales, sistemas o componentes físicos está compuesto este proyecto. 

Identifica todo el espectro de la construcción: desde la obra negra y estructura, hasta acabados, sistemas técnicos y urbanismo/paisajismo, según lo que el plano sugiera visual y textualmente.

### DIRECTRICES DE EXTRACCIÓN Y EJEMPLOS
Para llenar el esquema de datos, guíate por estos criterios:
* categoria_constructiva: Define libremente la agrupación lógica del elemento (Ejemplos: Estructura, Envolvente, Interiores, Urbanismo, Redes, etc.).
* elemento: Nombra el componente físico que estás analizando (Ejemplos: Muros perimetrales, cubierta, vías de acceso, canalizaciones).
* material_o_especificacion: Describe de qué está hecho o cuál es su acabado basándote en evidencia visual o textual. Si es una deducción lógica, indícalo.
* referencia_en_plano: Indica dónde lo ubicaste visualmente o en qué nota te basaste.
* analisis_de_materialidad_indefinida: Menciona componentes que requieren construcción, pero cuya materialidad exacta no se puede deducir.

### ESQUEMA DE RESPUESTA
Responde ÚNICAMENTE con un objeto JSON válido, sin texto adicional ni bloques de markdown externos, utilizando estrictamente la siguiente estructura y tipos de datos:

{
  "materiales_y_sistemas": [
    {
      "categoria_constructiva": "string",
      "elemento": "string",
      "material_o_especificacion": "string",
      "referencia_en_plano": "string"
    }
  ],
  "analisis_de_materialidad_indefinida": [
    "string"
  ]
}