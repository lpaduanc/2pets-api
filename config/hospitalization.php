<?php

/**
 * Contrato docs/atendimento-veterinario/12-modulo-clinico-internacao.md §1.2: o limiar de
 * "evolução atrasada" (`hours_since_last_progress_note` > este valor) é uma LEITURA da
 * palavra "diária" no nome da exigência regulatória (Res. CFMV 1.321/2020 alt. 1.653/2025),
 * não um número que a norma escreve — o dono do produto pode querer outro valor, inclusive
 * diferenciado por gravidade do paciente no futuro (pergunta 2 do doc, ainda em aberto).
 * Fica em config, não em código, para trocar sem deploy.
 */
return [
    'progress_note_overdue_hours' => (int) env('HOSPITALIZATION_PROGRESS_NOTE_OVERDUE_HOURS', 24),
];
