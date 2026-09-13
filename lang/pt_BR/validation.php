<?php

/*
 * Tradução pt-BR das mensagens padrão de validação do Laravel — o projeto nunca teve um
 * diretório `lang/` (achado da auditoria de cadastro, Onda 3: "sem `lang/pt_BR/validation.php`
 * no projeto, `Rule::enum()` cai em inglês"). `APP_LOCALE=pt_BR` já está configurado em `.env`
 * desde sempre, então este arquivo passa a valer para QUALQUER Form Request do app a partir de
 * agora — não só cadastro — sem precisar de `messages()` próprio por campo.
 *
 * Prioridade do Laravel (maior para menor), nenhuma delas é afetada por este arquivo:
 *   1. `messages()` do Form Request (ou array passado a `Validator::make`);
 *   2. `validation.custom.<atributo>.<regra>` (bloco `custom` abaixo);
 *   3. `validation.<regra>` (este arquivo — usado quando NADA acima define a mensagem);
 *   4. fallback em inglês do framework (`vendor/laravel/framework/.../lang/en/validation.php`).
 *
 * `attributes` cobre os nomes de campo mais comuns do cadastro/perfil para que a mensagem saia
 * "O CPF é obrigatório" em vez de "O campo cpf é obrigatório" — não é uma lista exaustiva dos
 * 82 models do projeto; campo sem entrada aqui cai no fallback do framework
 * (`Str::replace('_', ' ', $attribute)`), que já é legível o bastante em pt-BR
 * (ex.: "o campo zip code é obrigatório").
 */

return [

    'accepted' => 'O campo :attribute deve ser aceito.',
    'accepted_if' => 'O campo :attribute deve ser aceito quando :other for :value.',
    'active_url' => 'O campo :attribute deve ser uma URL válida.',
    'after' => 'O campo :attribute deve ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute deve ser uma data posterior ou igual a :date.',
    'alpha' => 'O campo :attribute deve conter apenas letras.',
    'alpha_dash' => 'O campo :attribute deve conter apenas letras, números, traços e sublinhados.',
    'alpha_num' => 'O campo :attribute deve conter apenas letras e números.',
    'any_of' => 'O campo :attribute é inválido.',
    'array' => 'O campo :attribute deve ser uma lista.',
    'ascii' => 'O campo :attribute deve conter apenas caracteres alfanuméricos e símbolos de um único byte.',
    'before' => 'O campo :attribute deve ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute deve ser uma data anterior ou igual a :date.',
    'between' => [
        'array' => 'O campo :attribute deve ter entre :min e :max itens.',
        'file' => 'O campo :attribute deve ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute deve estar entre :min e :max.',
        'string' => 'O campo :attribute deve ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação do campo :attribute não corresponde.',
    'contains' => 'Falta um valor obrigatório no campo :attribute.',
    'current_password' => 'A senha está incorreta.',
    'date' => 'O campo :attribute deve ser uma data válida.',
    'date_equals' => 'O campo :attribute deve ser uma data igual a :date.',
    'date_format' => 'O campo :attribute deve estar no formato :format.',
    'decimal' => 'O campo :attribute deve ter :decimal casas decimais.',
    'declined' => 'O campo :attribute deve ser recusado.',
    'declined_if' => 'O campo :attribute deve ser recusado quando :other for :value.',
    'different' => 'Os campos :attribute e :other devem ser diferentes.',
    'digits' => 'O campo :attribute deve ter :digits dígitos.',
    'digits_between' => 'O campo :attribute deve ter entre :min e :max dígitos.',
    'dimensions' => 'O campo :attribute tem dimensões de imagem inválidas.',
    'distinct' => 'O campo :attribute tem um valor duplicado.',
    'doesnt_contain' => 'O campo :attribute não pode conter nenhum dos seguintes: :values.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com nenhum dos seguintes: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar com nenhum dos seguintes: :values.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'encoding' => 'O campo :attribute deve estar codificado em :encoding.',
    'ends_with' => 'O campo :attribute deve terminar com um dos seguintes: :values.',
    'enum' => 'O valor selecionado para :attribute é inválido.',
    'exists' => 'O valor selecionado para :attribute é inválido.',
    'extensions' => 'O campo :attribute deve ter uma das seguintes extensões: :values.',
    'file' => 'O campo :attribute deve ser um arquivo.',
    'filled' => 'O campo :attribute deve ter um valor.',
    'gt' => [
        'array' => 'O campo :attribute deve ter mais de :value itens.',
        'file' => 'O campo :attribute deve ser maior que :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser maior que :value.',
        'string' => 'O campo :attribute deve ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute deve ter :value itens ou mais.',
        'file' => 'O campo :attribute deve ser maior ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser maior ou igual a :value.',
        'string' => 'O campo :attribute deve ter :value caracteres ou mais.',
    ],
    'hex_color' => 'O campo :attribute deve ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute deve ser uma imagem.',
    'in' => 'O valor selecionado para :attribute é inválido.',
    'in_array' => 'O campo :attribute deve existir em :other.',
    'in_array_keys' => 'O campo :attribute deve conter ao menos uma das seguintes chaves: :values.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'ip' => 'O campo :attribute deve ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute deve ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute deve ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute deve ser uma string JSON válida.',
    'list' => 'O campo :attribute deve ser uma lista.',
    'lowercase' => 'O campo :attribute deve estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute deve ter menos de :value itens.',
        'file' => 'O campo :attribute deve ser menor que :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser menor que :value.',
        'string' => 'O campo :attribute deve ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não deve ter mais de :value itens.',
        'file' => 'O campo :attribute deve ser menor ou igual a :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser menor ou igual a :value.',
        'string' => 'O campo :attribute não deve ter mais de :value caracteres.',
    ],
    'mac_address' => 'O campo :attribute deve ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não deve ter mais de :max itens.',
        'file' => 'O campo :attribute não deve ser maior que :max kilobytes.',
        'numeric' => 'O campo :attribute não deve ser maior que :max.',
        'string' => 'O campo :attribute não deve ter mais de :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não deve ter mais de :max dígitos.',
    'mimes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'mimetypes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute deve ter pelo menos :min itens.',
        'file' => 'O campo :attribute deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute deve ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute deve estar ausente.',
    'missing_if' => 'O campo :attribute deve estar ausente quando :other for :value.',
    'missing_unless' => 'O campo :attribute deve estar ausente a menos que :other seja :value.',
    'missing_with' => 'O campo :attribute deve estar ausente quando :values estiver presente.',
    'missing_with_all' => 'O campo :attribute deve estar ausente quando :values estiverem presentes.',
    'multiple_of' => 'O campo :attribute deve ser um múltiplo de :value.',
    'not_in' => 'O valor selecionado para :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute deve ser um número.',
    'password' => [
        'letters' => 'O campo :attribute deve conter pelo menos uma letra.',
        'mixed' => 'O campo :attribute deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo :attribute deve conter pelo menos um número.',
        'symbols' => 'O campo :attribute deve conter pelo menos um símbolo.',
        'uncompromised' => 'O :attribute informado apareceu em um vazamento de dados. Escolha um(a) :attribute diferente.',
    ],
    'present' => 'O campo :attribute deve estar presente.',
    'present_if' => 'O campo :attribute deve estar presente quando :other for :value.',
    'present_unless' => 'O campo :attribute deve estar presente a menos que :other seja :value.',
    'present_with' => 'O campo :attribute deve estar presente quando :values estiver presente.',
    'present_with_all' => 'O campo :attribute deve estar presente quando :values estiverem presentes.',
    'prohibited' => 'O campo :attribute não é permitido para o tipo de cadastro selecionado.',
    'prohibited_if' => 'O campo :attribute não é permitido quando :other for :value.',
    'prohibited_if_accepted' => 'O campo :attribute não é permitido quando :other for aceito.',
    'prohibited_if_declined' => 'O campo :attribute não é permitido quando :other for recusado.',
    'prohibited_unless' => 'O campo :attribute não é permitido a menos que :other esteja em :values.',
    'prohibits' => 'O campo :attribute impede que :other esteja presente.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute deve conter entradas para: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other for :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other for aceito.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other for recusado.',
    'required_unless' => 'O campo :attribute é obrigatório a menos que :other esteja em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values estiver presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estiverem presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não estiver presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values estiver presente.',
    'same' => 'Os campos :attribute e :other devem corresponder.',
    'size' => [
        'array' => 'O campo :attribute deve conter :size itens.',
        'file' => 'O campo :attribute deve ter :size kilobytes.',
        'numeric' => 'O campo :attribute deve ser :size.',
        'string' => 'O campo :attribute deve ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute deve começar com um dos seguintes: :values.',
    'string' => 'O campo :attribute deve ser um texto.',
    'timezone' => 'O campo :attribute deve ser um fuso horário válido.',
    'unique' => 'O :attribute informado já está em uso.',
    'uploaded' => 'Falha ao enviar o arquivo do campo :attribute.',
    'uppercase' => 'O campo :attribute deve estar em maiúsculas.',
    'url' => 'O campo :attribute deve ser uma URL válida.',
    'ulid' => 'O campo :attribute deve ser um ULID válido.',
    'uuid' => 'O campo :attribute deve ser um UUID válido.',

    'custom' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Nomes de atributo legíveis (pt-BR)
    |--------------------------------------------------------------------------
    |
    | Cobre os campos mais comuns dos fluxos de cadastro/perfil. Não é exaustivo — campo
    | ausente aqui cai no fallback do framework (`str_replace('_', ' ', $attribute)`), que já
    | é uma frase legível em português, só não tem acento/preposição correta.
    |
    */

    'attributes' => [
        'name' => 'nome',
        'email' => 'e-mail',
        'password' => 'senha',
        'phone' => 'telefone',
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'crmv' => 'CRMV',
        'crmv_state' => 'UF do CRMV',
        'birth_date' => 'data de nascimento',
        'gender' => 'gênero',
        'occupation' => 'profissão',
        'address' => 'endereço',
        'number' => 'número',
        'complement' => 'complemento',
        'neighborhood' => 'bairro',
        'city' => 'cidade',
        'state' => 'estado',
        'zip_code' => 'CEP',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'business_name' => 'nome da empresa',
        'company_name' => 'nome da empresa',
        'website' => 'site',
        'university' => 'universidade',
        'graduation_year' => 'ano de formatura',
        'experience_years' => 'anos de experiência',
        'specialties' => 'especialidades',
        'courses' => 'cursos',
        'service_radius_km' => 'raio de atendimento (km)',
        'opening_hours' => 'horário de abertura',
        'closing_hours' => 'horário de fechamento',
        'working_days' => 'dias de atendimento',
        'description' => 'descrição',
        'services_offered' => 'serviços oferecidos',
        'products_sold' => 'produtos vendidos',
        'equipment' => 'equipamentos',
        'certifications' => 'certificações',
        'species_served' => 'espécies atendidas',
        'sizes_served' => 'portes atendidos',
        'technical_responsible_name' => 'nome do responsável técnico',
        'technical_responsible_crmv' => 'CRMV do responsável técnico',
        'technical_responsible_crmv_state' => 'UF do CRMV do responsável técnico',
        'contact_name' => 'nome de contato',
        'contact_position' => 'cargo do contato',
        'employee_count' => 'quantidade de funcionários',
        'benefit_type' => 'tipo de benefício',
        'notes' => 'observações',
        'legal_representative_name' => 'nome do representante legal',
        'legal_representative_cpf' => 'CPF do representante legal',
        'legal_representative_birth_date' => 'data de nascimento do representante legal',
        'legal_representative_phone' => 'telefone do representante legal',
        'industry_sector' => 'setor da empresa',
        'estimated_pet_owners' => 'faixa estimada de tutores',
        'preferred_communication' => 'canal de comunicação preferido',
        'budget_range' => 'faixa de orçamento',
        'start_date_preference' => 'data de início preferida',
        'interested_services' => 'serviços de interesse',
    ],

];
