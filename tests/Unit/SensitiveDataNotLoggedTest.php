<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Item 22 do backlog gap-simplesvet — "log não registra segredo". Varre todo model que usa
 * `LogsActivity` e falha se `logOnly`/`$fillable` (quando `logFillable()` é usado) incluir um
 * campo sensível — regressão de código, não de dado em runtime, mas é a única forma barata de
 * garantir isto sem depender de um evento real acontecer em cada model.
 */
class SensitiveDataNotLoggedTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYWORDS = [
        'password', 'token', 'secret', 'api_key', 'api_token', 'certificate',
        'cvv', 'card_number', 'document_content',
    ];

    public function test_no_model_using_logs_activity_exposes_a_sensitive_attribute(): void
    {
        $offenders = [];

        foreach ($this->modelsUsingActivityLog() as $class) {
            $model = new $class;
            $options = $model->getActivitylogOptions();

            $attributesInScope = $options->logAttributes === ['*']
                ? array_keys($model->getAttributes()) + ($model->getFillable())
                : ($options->logFillable ? $model->getFillable() : $options->logAttributes);

            foreach ($attributesInScope as $attribute) {
                if ($this->isSensitive($attribute)) {
                    $offenders[] = "{$class}::{$attribute}";
                }
            }
        }

        $this->assertSame([], $offenders, 'Model(s) logando campo sensível: '.implode(', ', $offenders));
    }

    private function isSensitive(string $attribute): bool
    {
        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($attribute, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function modelsUsingActivityLog(): array
    {
        $classes = [];

        $modelsPath = dirname(__DIR__, 2).'/app/Models';

        foreach (glob($modelsPath.'/*.php') as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! in_array(LogsActivity::class, class_uses_recursive($class), true)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
