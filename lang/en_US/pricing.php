<?php

/** Pricing messages (docs/gap-petflow/PLANO.md, 1.4). Same keys as `lang/pt_BR/pricing.php`. */
return [
    'item_not_found' => 'Item not found in this clinic\'s catalog.',
    'single_price_target' => 'Provide only one of price, margin and markup.',
    'margin_below_hundred' => 'Margin must be lower than 100%.',
    'max_discount_exceeded' => 'A :effective% discount on ":item" exceeds the maximum allowed of :max%.',
    'below_cost_forbidden' => '":item" is below cost (R$ :net per unit, cost R$ :cost). Fix the price or mark it as a gift, courtesy or follow-up.',
    'line_discount_exceeds_gross' => 'The discount on ":item" exceeds the line amount (R$ :gross). To give the item away, mark it as a gift, courtesy or follow-up.',
    'header_discount_exceeds' => 'The sale discount exceeds the limit (:limit). To give items away, mark them as a gift, courtesy or follow-up.',
    'courtesy_locked' => 'A gift, courtesy or follow-up line cannot change price or discount. Unmark it first.',
    'courtesy_not_marked' => 'This line is not marked as a gift, courtesy or follow-up.',
    'courtesy_forbidden' => 'You are not allowed to mark a gift, courtesy or follow-up on this sale.',
    'courtesy_marked_log' => 'Line marked as gift, courtesy or follow-up',
    'courtesy_unmarked_log' => 'Gift, courtesy or follow-up mark removed',
    'finalize_balance_due' => 'The sale still has a balance due (R$ :due). Register the payment.',
    'fiscal_courtesy_only' => 'A sale with only gift, courtesy or follow-up lines does not issue a tax document.',
];
