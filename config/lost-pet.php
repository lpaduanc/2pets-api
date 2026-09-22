<?php

/**
 * Alerta de pet perdido (`POST /api/pet-card/{petId}/mark-lost`).
 *
 * O raio mora aqui, e nao no `default` da coluna `lost_pet_alerts.alert_radius_km`
 * (5.00, herdado da migration original), porque e uma decisao de produto que muda
 * sem deploy de schema: "todo tutor e todo profissional num raio de 30 km recebe o
 * alerta". O default da coluna continua valendo para linhas inseridas fora deste
 * fluxo.
 */
return [
    /*
     * Raio padrao, em km, usado quando o app nao manda um raio explicito.
     */
    'default_radius_km' => (float) env('LOST_PET_ALERT_RADIUS_KM', 30),

    /*
     * Teto aceito no request — evita que um cliente peca um alerta de ambito
     * nacional e dispare notificacao para a base inteira.
     */
    'max_radius_km' => (float) env('LOST_PET_ALERT_MAX_RADIUS_KM', 100),
];
