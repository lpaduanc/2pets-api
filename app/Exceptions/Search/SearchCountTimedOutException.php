<?php

namespace App\Exceptions\Search;

use RuntimeException;

/**
 * A contagem de um termo estourou o tempo de banco que a busca aceita gastar com ela.
 *
 * Não é erro de sistema: é o resultado esperado de um orçamento de latência. Quem a lança é
 * `ProfessionalSearchService::countFor()`, e o único chamador que a trata é
 * `SearchSuggestionFinder`, que descarta a sugestão correspondente — o "você quis dizer..."
 * é um adendo, e adendo nenhum tem o direito de atrasar a lista que o usuário pediu.
 *
 * É exceção tipada, e não `null` devolvido em silêncio, justamente para que esse descarte
 * seja uma decisão escrita num `catch` visível em vez de um `if` que ninguém lembra por quê.
 */
final class SearchCountTimedOutException extends RuntimeException {}
