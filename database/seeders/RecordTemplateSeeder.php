<?php

namespace Database\Seeders;

use App\Models\RecordTemplate;
use Illuminate\Database\Seeder;

/**
 * Modelos GLOBAIS de prontuário (PLANO 3.8; doc 14). Idempotente: o modelo é achado pela
 * `system_key` e só é criado se faltar — rodar de novo não duplica nem sobrescreve o conteúdo
 * (se o 2pets mudar o texto, troque a chave ou faça migração de dados explícita).
 *
 * Anamnese estruturada por sistemas: conteúdo do PetFlow trazido para o modelo de texto do 2pets
 * (HTML do editor, mesmo formato de `document_templates.body_html`). Placeholders: os mesmos que
 * o `DocumentTemplateRenderer` já substitui (`{{pet.nome}}`, `{{tutor.nome}}`, `{{data}}`).
 */
class RecordTemplateSeeder extends Seeder
{
    public const ANAMNESIS_BY_SYSTEMS = 'anamnesis_by_systems';

    /** Sistema → perguntas-guia. */
    private const SYSTEMS = [
        'Tegumentar (pele e anexos)' => 'Prurido, lesões, alopecia, descamação, odor, ectoparasitas, banho/tosa recentes.',
        'Digestório' => 'Apetite, vômito, regurgitação, diarreia, fezes (cor/consistência), disfagia, alimentação e mudança de dieta.',
        'Respiratório' => 'Tosse, espirro, secreção nasal, dispneia, cansaço, ruído respiratório.',
        'Cardiovascular' => 'Intolerância ao exercício, síncope, cianose, tosse noturna, edema.',
        'Urinário' => 'Frequência e volume urinário, disúria, hematúria, ingestão de água, incontinência.',
        'Reprodutor' => 'Castrado(a)? Último cio, gestações, secreção vulvar/prepucial, alterações mamárias ou testiculares.',
        'Nervoso' => 'Convulsões, alteração de comportamento ou consciência, andar em círculos, inclinação de cabeça, paresia.',
        'Musculoesquelético' => 'Claudicação (membro), dor ao movimento, dificuldade para levantar ou subir, trauma recente.',
        'Oftálmico' => 'Secreção ocular, blefarospasmo, opacidade, vermelhidão, alteração de visão.',
        'Auditivo' => 'Prurido auricular, sacudir a cabeça, secreção ou odor no ouvido, dor ao manipular.',
    ];

    public function run(): void
    {
        $template = RecordTemplate::withTrashed()->firstOrNew(['system_key' => self::ANAMNESIS_BY_SYSTEMS]);

        if ($template->exists) {
            return;
        }

        $template->forceFill([
            'system_key' => self::ANAMNESIS_BY_SYSTEMS,
            'organization_id' => null,
            'name' => 'Anamnese por sistemas',
            'description' => 'Queixa principal, histórico e revisão dos 10 sistemas orgânicos.',
            'body_html' => $this->body(),
            'active' => true,
        ])->save();
    }

    private function body(): string
    {
        $systems = implode("\n", array_map(
            fn (string $system, string $guide): string => "<h3>{$system}</h3>\n<p><em>{$guide}</em></p>\n<p></p>",
            array_keys(self::SYSTEMS),
            self::SYSTEMS,
        ));

        return <<<HTML
        <h2>Anamnese — {{pet.nome}}</h2>
        <p>Tutor: {{tutor.nome}} · Data: {{data}}</p>
        <h3>Queixa principal</h3>
        <p><em>Motivo da consulta, início, evolução e o que já foi feito.</em></p>
        <p></p>
        <h3>Histórico</h3>
        <p><em>Vacinas e vermífugo, doenças e cirurgias anteriores, medicamentos em uso, alergias, ambiente (casa/quintal, contato com outros animais), manejo e alimentação.</em></p>
        <p></p>
        <h2>Revisão por sistemas</h2>
        {$systems}
        HTML;
    }
}
