<?php

namespace Database\Seeders\Benchmark;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Gera volume realista de dados para medir performance (Fase 2 do plano de
 * otimizacao de queries do 2pets-api).
 *
 * NAO e registrado em `DatabaseSeeder` de proposito — e invocado explicitamente:
 *
 *   docker compose exec backend php artisan db:seed --class="Database\Seeders\Benchmark\BenchmarkSeeder"
 *
 * Usa `INSERT ... SELECT ... FROM generate_series(...)` em SQL cru em vez de
 * factories: o projeto tem 4 factories para 82 models, e 200 mil users via
 * Eloquent significariam 200 mil INSERTs + 200 mil hashes bcrypt individuais —
 * horas de execucao. Um hash bcrypt pre-computado e reutilizado em todas as
 * linhas resolve isso em segundos.
 *
 * Tabelas temporarias (`bench_*`) encadeiam as inserções sem precisar adivinhar
 * IDs: cada bloco captura os IDs reais que acabou de inserir via `RETURNING`
 * (ou `row_number()` sobre eles) para o proximo bloco poder referenciar FKs
 * validas. Elas somem sozinhas no fim da sessao — nao precisam de limpeza.
 */
class BenchmarkSeeder extends Seeder
{
    private const TOTAL_USERS = 200_000;

    private const TOTAL_TUTORS = 150_000;

    private const TOTAL_PROFESSIONALS = 45_000;

    private const TOTAL_PETS = 300_000;

    private const TOTAL_SERVICES = 180_000;

    private const TOTAL_AVAILABILITIES = 315_000;

    private const TOTAL_APPOINTMENTS = 2_000_000;

    private const TOTAL_CONVERSATIONS = 100_000;

    private const TOTAL_MESSAGES = 1_000_000;

    private const TOTAL_FAVORITES = 600_000;

    private const TOTAL_REVIEWS = 400_000;

    private const TOTAL_NOTIFICATIONS = 3_000_000;

    private const TOTAL_VACCINATIONS = 500_000;

    private const TOTAL_PRESCRIPTIONS = 500_000;

    private const TOTAL_MEDICAL_RECORDS = 500_000;

    /** ~10% das coordenadas ficam fora dos clusters, para o raio ter cauda longa. */
    private const OUTLIER_RATIO = 0.10;

    public function run(): void
    {
        $this->guardAgainstUnsafeEnvironment();

        $startedAt = microtime(true);

        $this->seedUsers();
        $this->seedProfessionals();
        $this->seedDerivedUserPools();
        $this->seedPets();
        $this->seedServices();
        $this->seedAvailabilities();
        $this->seedAppointments();
        $this->seedConversationsAndMessages();
        $this->seedFavorites();
        $this->seedReviews();
        $this->seedNotifications();
        $this->seedClinicalRecords();
        $this->vacuumAnalyze();

        $elapsedSeconds = round(microtime(true) - $startedAt, 1);
        $this->command?->info("BenchmarkSeeder concluido em {$elapsedSeconds}s.");
    }

    /**
     * Nunca pode rodar por acidente contra o banco de produção nem contra o
     * sqlite dos testes: guard duplo (ambiente `local` E driver `pgsql`).
     */
    private function guardAgainstUnsafeEnvironment(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'BenchmarkSeeder só pode rodar em ambiente local (APP_ENV=local).'
            );
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'BenchmarkSeeder requer PostgreSQL — gera geography/GIST e SQL exclusivo do driver.'
            );
        }
    }

    /**
     * 200 mil users: 150k tutores, 45k profissionais, 5k outros (company/admin).
     * `location` (geography) e calculada e gravada na MESMA statement do
     * INSERT — nunca num UPDATE depois, para nunca existir uma janela de
     * inconsistencia entre latitude/longitude e location.
     *
     * Distribuicao geografica clusterizada (nao uniforme): 8 centroides na
     * Grande SP + 1 em Campinas (controle negativo), ruido quase-normal via
     * soma de tres random() (~5,5 km de desvio), e ~10% espalhados pelo
     * estado inteiro para o raio de busca ter cauda longa.
     */
    private function seedUsers(): void
    {
        $firstNames = NamePools::toTextArrayLiteral(NamePools::FIRST_NAMES);
        $lastNames = NamePools::toTextArrayLiteral(NamePools::LAST_NAMES);
        $centroidLatitudes = NamePools::toFloatArrayLiteral(NamePools::CENTROID_LATITUDES);
        $centroidLongitudes = NamePools::toFloatArrayLiteral(NamePools::CENTROID_LONGITUDES);
        $centroidCount = count(NamePools::CENTROID_LATITUDES);
        $lastUserSeq = self::TOTAL_USERS - 1;
        $tutorLimit = self::TOTAL_TUTORS;
        $professionalLimit = $tutorLimit + self::TOTAL_PROFESSIONALS;
        $companyLimit = $professionalLimit + 4_000;
        $outlierRatio = self::OUTLIER_RATIO;

        $sql = <<<SQL
            CREATE TEMP TABLE bench_users AS
            WITH ins AS (
                INSERT INTO users (
                    name, email, password, role, user_type, phone, address, city, state,
                    zip_code, latitude, longitude, location, email_verified, profile_completed,
                    registration_status, is_suspended, cpf, birth_date, gender,
                    created_at, updated_at, deleted_at
                )
                SELECT
                    names.fn[1 + (s % 40)] || ' ' || names.ln[1 + ((s / 40) % 50)],
                    'bench_user_' || s::text || '@2pets.test',
                    ?,
                    CASE
                        WHEN s < {$tutorLimit} THEN 'tutor'
                        WHEN s < {$professionalLimit} THEN 'professional'
                        WHEN s < {$companyLimit} THEN 'company'
                        ELSE 'admin'
                    END,
                    CASE
                        WHEN s < {$tutorLimit} THEN 'tutor'
                        WHEN s < {$professionalLimit} THEN pools.prof_types[1 + (s % 7)]
                        ELSE 'tutor'
                    END,
                    '11' || lpad(((900000000 + s) % 999999999)::text, 9, '0'),
                    'Rua ' || names.ln[1 + (s % 50)] || ', ' || (100 + (s % 900))::text,
                    pools.cities[1 + (s % 5)],
                    'SP',
                    lpad((s % 99999)::text, 5, '0') || '-000',
                    pt.lat,
                    pt.lng,
                    ST_SetSRID(ST_MakePoint(pt.lng, pt.lat), 4326)::geography,
                    (s % 100) < 90,
                    (s % 100) < 85,
                    CASE
                        WHEN (s % 100) < 80 THEN 'approved'
                        WHEN (s % 100) < 95 THEN 'pending'
                        ELSE 'rejected'
                    END,
                    (s % 100) < 2,
                    lpad((20000000000 + s)::text, 11, '0'),
                    (DATE '1955-01-01' + ((s * 137) % 24000)),
                    pools.genders[1 + (s % 4)],
                    now() - (s % 1000) * INTERVAL '1 hour',
                    now() - (s % 1000) * INTERVAL '1 hour',
                    CASE WHEN (s % 100) < 5 THEN now() - (s % 1000) * INTERVAL '1 hour' ELSE NULL END
                FROM generate_series(0, {$lastUserSeq}) AS s
                CROSS JOIN LATERAL (
                    SELECT {$firstNames} AS fn, {$lastNames} AS ln
                ) names
                CROSS JOIN LATERAL (
                    SELECT
                        ARRAY['vet','clinic','laboratory','petshop','pet_hotel','grooming','training']::text[] AS prof_types,
                        ARRAY['São Paulo','Guarulhos','Osasco','Santo André','Campinas']::text[] AS cities,
                        ARRAY['male','female','other','not_specified']::text[] AS genders
                ) pools
                CROSS JOIN LATERAL (
                    -- "+ (s * 0)" e proposital: sem referenciar `s`, o Postgres 16 hoisteia
                    -- este LATERAL sem correlacao real e avalia random() UMA VEZ para a
                    -- query inteira (mesmo ruido/mesma decisao de outlier em 200 mil linhas),
                    -- em vez de por linha. Confirmado empiricamente durante o seed real —
                    -- ver relatorio da Fase 2. Forcar a dependencia de `s` restaura o
                    -- comportamento por linha.
                    SELECT
                        random() + (s * 0) AS r_outlier,
                        random() + (s * 0) AS r1,
                        random() + (s * 0) AS r2,
                        random() + (s * 0) AS r3
                ) rnd
                CROSS JOIN LATERAL (
                    SELECT
                        CASE WHEN rnd.r_outlier < {$outlierRatio}
                            THEN -25.30 + rnd.r1 * (25.30 - 19.80)
                            ELSE ({$centroidLatitudes})[1 + (s % {$centroidCount})]
                                 + (rnd.r1 + rnd.r2 + rnd.r3 - 1.5) * 0.05
                        END AS lat,
                        CASE WHEN rnd.r_outlier < {$outlierRatio}
                            THEN -53.11 + rnd.r2 * (53.11 - 44.16)
                            ELSE ({$centroidLongitudes})[1 + (s % {$centroidCount})]
                                 + (rnd.r1 + rnd.r2 + rnd.r3 - 1.5) * 0.05
                        END AS lng
                ) pt
                RETURNING id, role, user_type, latitude, longitude, deleted_at
            )
            SELECT * FROM ins;
            SQL;

        $this->runStep('users (200k)', $sql, [Hash::make('password')]);
    }

    /**
     * 45 mil profissionais — um por user com role='professional'. `user_id`
     * aqui É o id real de `users` (bench_users), nao precisa de mapeamento.
     */
    private function seedProfessionals(): void
    {
        $prefixes = NamePools::toTextArrayLiteral(NamePools::BUSINESS_PREFIXES);
        $lastNames = NamePools::toTextArrayLiteral(NamePools::LAST_NAMES);

        $sql = <<<SQL
            INSERT INTO professionals (
                user_id, professional_type, business_name, cnpj, specialties, opening_hours,
                closing_hours, working_days, description, crmv, crmv_state, university,
                graduation_year, experience_years, service_radius_km, services_offered,
                average_rating, total_reviews, is_crmv_verified, created_at, updated_at
            )
            SELECT
                bu.id,
                bu.user_type,
                names.prefixes[1 + (bu.id % 6)] || ' ' || names.ln[1 + (bu.id % 50)],
                lpad(bu.id::text, 14, '0'),
                '["Clínica Geral","Vacinação"]',
                '08:00'::time,
                '18:00'::time,
                '["1","2","3","4","5"]',
                'Atendimento gerado pelo BenchmarkSeeder para testes de carga.',
                CASE WHEN bu.user_type = 'vet' THEN 'CRMV-SP-' || lpad(bu.id::text, 6, '0') ELSE NULL END,
                CASE WHEN bu.user_type = 'vet' THEN 'SP' ELSE NULL END,
                CASE WHEN bu.user_type = 'vet' THEN 'USP' ELSE NULL END,
                CASE WHEN bu.user_type = 'vet' THEN 2000 + (bu.id % 24) ELSE NULL END,
                1 + (bu.id % 20),
                5 + (bu.id % 30),
                '["Consulta","Vacinação","Cirurgia"]',
                round((1 + random() * 4)::numeric, 2),
                (bu.id % 200),
                (bu.id % 100) < 60,
                now(),
                now()
            FROM bench_users bu
            CROSS JOIN LATERAL (SELECT {$prefixes} AS prefixes, {$lastNames} AS ln) names
            WHERE bu.role = 'professional';
            SQL;

        $this->runStep('professionals (45k)', $sql);
    }

    /**
     * Pools indexados por `row_number()` para os blocos seguintes poderem
     * escolher "o n-esimo tutor"/"o n-esimo profissional" com um JOIN por
     * igualdade em vez de subquery correlacionada por linha.
     */
    private function seedDerivedUserPools(): void
    {
        $this->runStep('pool: bench_tutors', <<<'SQL'
            CREATE TEMP TABLE bench_tutors AS
            SELECT row_number() OVER (ORDER BY id) AS rn, id, latitude, longitude
            FROM bench_users WHERE role = 'tutor';
            SQL);
        $this->runStep('index: bench_tutors', 'CREATE INDEX ON bench_tutors (rn);');

        $this->runStep('pool: bench_professional_pool', <<<'SQL'
            CREATE TEMP TABLE bench_professional_pool AS
            SELECT row_number() OVER (ORDER BY id) AS rn, id, user_type
            FROM bench_users WHERE role = 'professional';
            SQL);
        $this->runStep('index: bench_professional_pool', 'CREATE INDEX ON bench_professional_pool (rn);');

        $this->runStep('pool: bench_all_users', <<<'SQL'
            CREATE TEMP TABLE bench_all_users AS
            SELECT row_number() OVER (ORDER BY id) AS rn, id
            FROM bench_users;
            SQL);
        $this->runStep('index: bench_all_users', 'CREATE INDEX ON bench_all_users (rn);');
    }

    /**
     * 300 mil pets, 2 por tutor. Captura (pet_id, tutor_id, pet_slot) via
     * RETURNING para os appointments conseguirem escolher um pet que
     * realmente pertence ao tutor sorteado.
     */
    private function seedPets(): void
    {
        $petNames = NamePools::toTextArrayLiteral([
            'Rex', 'Bella', 'Thor', 'Luna', 'Max', 'Mel', 'Bob', 'Nina', 'Toby', 'Lola',
            'Zeus', 'Mia', 'Simba', 'Amora', 'Bidu', 'Duke', 'Fred', 'Pipoca', 'Preta', 'Spike',
        ]);
        $lastPetSeq = self::TOTAL_PETS - 1;
        $tutorCount = self::TOTAL_TUTORS;

        $sql = <<<SQL
            CREATE TEMP TABLE bench_pets AS
            WITH ins AS (
                INSERT INTO pets (
                    user_id, name, species, birth_date, gender, weight, neutered, size,
                    public_id, created_at, updated_at, deleted_at
                )
                SELECT
                    t.id,
                    pools.pet_names[1 + (s % 20)],
                    pools.species[1 + (s % 11)],
                    (DATE '2012-01-01' + ((s * 97) % 4800)),
                    CASE WHEN s % 2 = 0 THEN 'male' ELSE 'female' END,
                    round((1 + random() * 39)::numeric, 2),
                    (s % 100) < 40,
                    pools.sizes[1 + (s % 5)],
                    gen_random_uuid(),
                    now() - (s % 1000) * INTERVAL '1 hour',
                    now() - (s % 1000) * INTERVAL '1 hour',
                    CASE WHEN (s % 100) < 5 THEN now() ELSE NULL END
                FROM generate_series(0, {$lastPetSeq}) AS s
                JOIN bench_tutors t ON t.rn = (s % {$tutorCount}) + 1
                CROSS JOIN LATERAL (
                    SELECT
                        {$petNames} AS pet_names,
                        ARRAY['dog','dog','dog','cat','cat','cat','bird','reptile','rodent','fish','other']::text[] AS species,
                        ARRAY['mini','small','medium','large','giant']::text[] AS sizes
                ) pools
                RETURNING id AS pet_id, user_id AS tutor_id
            )
            SELECT pet_id, tutor_id, row_number() OVER (PARTITION BY tutor_id ORDER BY pet_id) AS pet_slot
            FROM ins;
            SQL;

        $this->runStep('pets (300k)', $sql);
        $this->runStep('index: bench_pets', 'CREATE INDEX ON bench_pets (tutor_id, pet_slot);');

        $this->runStep('pool: bench_pet_pool', <<<'SQL'
            CREATE TEMP TABLE bench_pet_pool AS
            SELECT row_number() OVER (ORDER BY pet_id) AS rn, pet_id, tutor_id
            FROM bench_pets;
            SQL);
        $this->runStep('index: bench_pet_pool', 'CREATE INDEX ON bench_pet_pool (rn);');
    }

    /** 180 mil services, 4 por profissional. Categoria restrita ao CHECK da tabela. */
    private function seedServices(): void
    {
        $lastServiceSeq = self::TOTAL_SERVICES - 1;
        $professionalCount = self::TOTAL_PROFESSIONALS;

        $sql = <<<SQL
            INSERT INTO services (professional_id, name, description, category, duration, price, active, created_at, updated_at)
            SELECT
                p.id,
                pools.names[1 + (s % 8)],
                'Servico gerado pelo BenchmarkSeeder para testes de carga.',
                pools.categories[1 + (s % 5)],
                pools.durations[1 + (s % 4)],
                round((50 + random() * 450)::numeric, 2),
                (s % 100) < 90,
                now(),
                now()
            FROM generate_series(0, {$lastServiceSeq}) AS s
            JOIN bench_professional_pool p ON p.rn = (s % {$professionalCount}) + 1
            CROSS JOIN LATERAL (
                SELECT
                    ARRAY['Consulta Geral','Vacinação','Banho e Tosa','Exame de Sangue','Cirurgia','Check-up','Emergência','Odontologia']::text[] AS names,
                    ARRAY['consultation','surgery','exam','grooming','other']::text[] AS categories,
                    ARRAY[15,30,45,60]::int[] AS durations
            ) pools;
            SQL;

        $this->runStep('services (180k)', $sql);
    }

    /** 315 mil availabilities: exatamente 7 por profissional (um por dia da semana). */
    private function seedAvailabilities(): void
    {
        $lastAvailabilitySeq = self::TOTAL_AVAILABILITIES - 1;
        $professionalCount = self::TOTAL_PROFESSIONALS;

        $sql = <<<SQL
            INSERT INTO availabilities (professional_id, day_of_week, start_time, end_time, slot_duration, buffer_time, is_active, created_at, updated_at)
            SELECT
                p.id,
                s / {$professionalCount},
                '08:00'::time,
                '18:00'::time,
                30,
                10,
                true,
                now(),
                now()
            FROM generate_series(0, {$lastAvailabilitySeq}) AS s
            JOIN bench_professional_pool p ON p.rn = (s % {$professionalCount}) + 1;
            SQL;

        $this->runStep('availabilities (315k)', $sql);
    }

    /**
     * 2 milhões de appointments. Tutor e profissional sao escolhidos por
     * mistura linear (multiplicador/soma coprimos com o tamanho do pool) em
     * vez de modulo puro, para nao gerar um padrao onde o mesmo bloco de
     * tutores sempre cai com o mesmo bloco de profissionais.
     */
    private function seedAppointments(): void
    {
        $lastAppointmentSeq = self::TOTAL_APPOINTMENTS - 1;
        $tutorCount = self::TOTAL_TUTORS;
        $professionalCount = self::TOTAL_PROFESSIONALS;

        $sql = <<<SQL
            INSERT INTO appointments (
                professional_id, client_id, pet_id, appointment_date, appointment_time, duration,
                type, status, reason, price, booking_source, requires_confirmation, created_at, updated_at
            )
            SELECT
                prof.id,
                t.id,
                bp.pet_id,
                (now() - (s % 730) * INTERVAL '1 day'),
                ('08:00'::time + (s % 20) * INTERVAL '30 minutes'),
                pools.durations[1 + (s % 4)],
                pools.types[1 + (s % 7)],
                pools.statuses[1 + (s % 7)],
                'Consulta gerada pelo BenchmarkSeeder',
                round((50 + random() * 450)::numeric, 2),
                pools.sources[1 + (s % 3)],
                true,
                now() - (s % 730) * INTERVAL '1 day',
                now() - (s % 730) * INTERVAL '1 day'
            FROM generate_series(0, {$lastAppointmentSeq}) AS s
            JOIN bench_tutors t ON t.rn = 1 + ((s * 7 + 13) % {$tutorCount})
            JOIN bench_professional_pool prof ON prof.rn = 1 + ((s * 13 + 41) % {$professionalCount})
            LEFT JOIN bench_pets bp ON bp.tutor_id = t.id AND bp.pet_slot = 1 + (s % 2)
            CROSS JOIN LATERAL (
                SELECT
                    ARRAY[15,30,45,60]::int[] AS durations,
                    ARRAY['consultation','surgery','vaccination','exam','emergency','grooming','checkup']::text[] AS types,
                    ARRAY['completed','completed','scheduled','confirmed','cancelled','pending','no_show']::text[] AS statuses,
                    ARRAY['professional','client','admin']::text[] AS sources
            ) pools;
            SQL;

        $this->runStep('appointments (2M)', $sql);
    }

    /**
     * 100 mil conversas (tutor <-> profissional) e 1 milhão de mensagens.
     * `ON CONFLICT DO NOTHING` porque `(participant_one_id, participant_two_id)`
     * e unico e a mistura linear pode colidir num par ja existente — a conta
     * final de conversas fica um pouco abaixo de 100k por isso, reportado no log.
     */
    private function seedConversationsAndMessages(): void
    {
        $lastConversationSeq = self::TOTAL_CONVERSATIONS - 1;
        $tutorCount = self::TOTAL_TUTORS;
        $professionalCount = self::TOTAL_PROFESSIONALS;

        $conversationSql = <<<SQL
            CREATE TEMP TABLE bench_conversations AS
            WITH ins AS (
                INSERT INTO conversations (participant_one_id, participant_two_id, last_message_at, created_at, updated_at)
                SELECT
                    LEAST(t.id, p.id),
                    GREATEST(t.id, p.id),
                    now() - (s % 365) * INTERVAL '1 day',
                    now() - (s % 365) * INTERVAL '1 day',
                    now() - (s % 365) * INTERVAL '1 day'
                FROM generate_series(0, {$lastConversationSeq}) AS s
                JOIN bench_tutors t ON t.rn = 1 + ((s * 3 + 7) % {$tutorCount})
                JOIN bench_professional_pool p ON p.rn = 1 + ((s * 5 + 11) % {$professionalCount})
                ON CONFLICT (participant_one_id, participant_two_id) DO NOTHING
                RETURNING id, participant_one_id, participant_two_id
            )
            SELECT row_number() OVER (ORDER BY id) AS rn, id AS conversation_id, participant_one_id, participant_two_id
            FROM ins;
            SQL;

        $this->runStep('conversations (~100k)', $conversationSql);
        $this->runStep('index: bench_conversations', 'CREATE INDEX ON bench_conversations (rn);');

        $conversationCount = (int) DB::selectOne('SELECT count(*) AS total FROM bench_conversations')->total;
        $lastMessageSeq = self::TOTAL_MESSAGES - 1;

        $messageSql = <<<SQL
            INSERT INTO messages (conversation_id, sender_id, content, read_at, created_at, updated_at)
            SELECT
                c.conversation_id,
                CASE WHEN s % 2 = 0 THEN c.participant_one_id ELSE c.participant_two_id END,
                pools.bodies[1 + (s % 10)],
                CASE WHEN (s % 100) < 70 THEN now() - (s % 200) * INTERVAL '1 hour' ELSE NULL END,
                now() - (s % 5000) * INTERVAL '1 minute',
                now() - (s % 5000) * INTERVAL '1 minute'
            FROM generate_series(0, {$lastMessageSeq}) AS s
            JOIN bench_conversations c ON c.rn = 1 + (s % {$conversationCount})
            CROSS JOIN LATERAL (
                SELECT ARRAY[
                    'Ola, tudo bem?', 'Gostaria de agendar uma consulta.', 'Qual o horario disponivel?',
                    'Obrigado pelo atendimento!', 'Posso remarcar?', 'O pet esta melhor.',
                    'Enviando os exames.', 'Confirmado para amanha.', 'Precisa de mais alguma informacao?',
                    'Ate breve!'
                ]::text[] AS bodies
            ) pools;
            SQL;

        $this->runStep('messages (1M)', $messageSql);
    }

    /**
     * 600 mil favoritos. `ON CONFLICT DO NOTHING` porque `(user_id, professional_id)`
     * e unico.
     *
     * `tutor_rn = 1 + (k % 150000)` e `block = k / 150000` formam uma BIJECAO com
     * `k = (s * 7) % 600000` (7 e coprimo com 600000): cada par (tutor, block)
     * aparece exatamente uma vez, garantindo 4 favoritos distintos por tutor sem
     * repetir a mesma combinacao. Uma primeira versao usava dois `% ` independentes
     * (um por tutor, um por profissional) e perdia 25% das linhas em colisao —
     * o periodo combinado (`lcm(150000, 45000) = 450000`) era menor que as 600000
     * linhas pedidas, entao o padrao simplesmente se repetia na segunda metade.
     */
    private function seedFavorites(): void
    {
        $lastFavoriteSeq = self::TOTAL_FAVORITES - 1;
        $favoriteCount = self::TOTAL_FAVORITES;
        $tutorCount = self::TOTAL_TUTORS;
        $professionalCount = self::TOTAL_PROFESSIONALS;

        $sql = <<<SQL
            INSERT INTO favorites (user_id, professional_id, created_at, updated_at)
            SELECT
                t.id,
                p.id,
                now() - (s % 700) * INTERVAL '1 day',
                now() - (s % 700) * INTERVAL '1 day'
            FROM generate_series(0, {$lastFavoriteSeq}) AS s
            CROSS JOIN LATERAL (SELECT (s * 7) % {$favoriteCount} AS k) mix
            JOIN bench_tutors t ON t.rn = 1 + (mix.k % {$tutorCount})
            JOIN bench_professional_pool p
                ON p.rn = 1 + (((mix.k / {$tutorCount}) * 13 + t.rn * 17) % {$professionalCount})
            ON CONFLICT (user_id, professional_id) DO NOTHING;
            SQL;

        $this->runStep('favorites (~600k)', $sql);
    }

    /**
     * 400 mil reviews. `appointment_id` fica NULL por simplificação (ver
     * relatório da tarefa) — o índice/volume de `reviews` é o que importa
     * para o benchmark, não o vínculo exato com um appointment específico.
     */
    private function seedReviews(): void
    {
        $lastReviewSeq = self::TOTAL_REVIEWS - 1;
        $tutorCount = self::TOTAL_TUTORS;
        $professionalCount = self::TOTAL_PROFESSIONALS;

        $sql = <<<SQL
            INSERT INTO reviews (
                professional_id, client_id, rating, comment, is_verified, is_visible, is_flagged,
                helpful_count, moderation_status, created_at, updated_at
            )
            SELECT
                p.id,
                t.id,
                pools.ratings[1 + (s % 5)],
                pools.comments[1 + (s % 8)],
                (s % 100) < 70,
                (s % 100) < 95,
                (s % 100) < 3,
                (s % 50),
                CASE
                    WHEN (s % 100) < 80 THEN 'approved'
                    WHEN (s % 100) < 95 THEN 'pending'
                    ELSE 'rejected'
                END,
                now() - (s % 700) * INTERVAL '1 day',
                now() - (s % 700) * INTERVAL '1 day'
            FROM generate_series(0, {$lastReviewSeq}) AS s
            JOIN bench_tutors t ON t.rn = 1 + ((s * 17 + 9) % {$tutorCount})
            JOIN bench_professional_pool p ON p.rn = 1 + ((s * 19 + 23) % {$professionalCount})
            CROSS JOIN LATERAL (
                SELECT
                    ARRAY[1,2,3,4,5]::int[] AS ratings,
                    ARRAY[
                        'Ótimo atendimento!', 'Recomendo muito.', 'Poderia ser melhor.',
                        'Profissional excelente.', 'Atendimento rápido e eficiente.',
                        'Não gostei muito.', 'Superou expectativas.',
                        'Poderia ter mais opções de horário.'
                    ]::text[] AS comments
            ) pools;
            SQL;

        $this->runStep('reviews (400k)', $sql);
    }

    /** 3 milhões de notificações, distribuidas por todos os 200k users. */
    private function seedNotifications(): void
    {
        $lastNotificationSeq = self::TOTAL_NOTIFICATIONS - 1;
        $userCount = self::TOTAL_USERS;

        $sql = <<<SQL
            INSERT INTO notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                'App\\Notifications\\' || pools.types[1 + (s % 8)],
                'App\\Models\\User',
                u.id,
                jsonb_build_object('message', 'Notificação gerada pelo BenchmarkSeeder', 'seq', s),
                CASE WHEN (s % 100) < 60 THEN now() - (s % 500) * INTERVAL '1 hour' ELSE NULL END,
                now() - (s % 20000) * INTERVAL '1 minute',
                now() - (s % 20000) * INTERVAL '1 minute'
            FROM generate_series(0, {$lastNotificationSeq}) AS s
            JOIN bench_all_users u ON u.rn = 1 + (s % {$userCount})
            CROSS JOIN LATERAL (
                SELECT ARRAY[
                    'WelcomeNotification', 'ReviewPublishedNotification', 'PaymentConfirmedNotification',
                    'VaccineReminderNotification', 'DewormingReminderNotification', 'PetVetAccessRequested',
                    'TrialExpiringNotification', 'CrmvApprovedNotification'
                ]::text[] AS types
            ) pools;
            SQL;

        $this->runStep('notifications (3M)', $sql);
    }

    /** 500 mil vaccinations + 500 mil prescriptions + 500 mil medical_records. */
    private function seedClinicalRecords(): void
    {
        $petCount = self::TOTAL_PETS;
        $professionalCount = self::TOTAL_PROFESSIONALS;
        $lastVaccinationSeq = self::TOTAL_VACCINATIONS - 1;
        $lastPrescriptionSeq = self::TOTAL_PRESCRIPTIONS - 1;
        $lastMedicalRecordSeq = self::TOTAL_MEDICAL_RECORDS - 1;

        $vaccinationSql = <<<SQL
            INSERT INTO vaccinations (pet_id, professional_id, vaccine_name, manufacturer, application_date, next_dose_date, dose_number, created_at, updated_at)
            SELECT
                pp.pet_id,
                prof.id,
                pools.vaccines[1 + (s % 6)],
                pools.manufacturers[1 + (s % 4)],
                (CURRENT_DATE - (s % 700)),
                (CURRENT_DATE - (s % 700) + 365),
                1 + (s % 3),
                now() - (s % 700) * INTERVAL '1 day',
                now() - (s % 700) * INTERVAL '1 day'
            FROM generate_series(0, {$lastVaccinationSeq}) AS s
            JOIN bench_pet_pool pp ON pp.rn = 1 + (s % {$petCount})
            JOIN bench_professional_pool prof ON prof.rn = 1 + ((s * 13 + 7) % {$professionalCount})
            CROSS JOIN LATERAL (
                SELECT
                    ARRAY['V10','V8','Antirrábica','Giardia','Gripe Canina','Leishmaniose']::text[] AS vaccines,
                    ARRAY['Zoetis','MSD','Boehringer','Vencofarma']::text[] AS manufacturers
            ) pools;
            SQL;

        $this->runStep('vaccinations (500k)', $vaccinationSql);

        $prescriptionSql = <<<SQL
            INSERT INTO prescriptions (pet_id, professional_id, prescription_date, medications, general_instructions, is_controlled, created_at, updated_at)
            SELECT
                pp.pet_id,
                prof.id,
                (CURRENT_DATE - (s % 700)),
                '[{"name":"Amoxicilina","dosage":"250mg","frequency":"12/12h"}]',
                'Administrar após as refeições.',
                (s % 100) < 5,
                now() - (s % 700) * INTERVAL '1 day',
                now() - (s % 700) * INTERVAL '1 day'
            FROM generate_series(0, {$lastPrescriptionSeq}) AS s
            JOIN bench_pet_pool pp ON pp.rn = 1 + (s % {$petCount})
            JOIN bench_professional_pool prof ON prof.rn = 1 + ((s * 17 + 11) % {$professionalCount});
            SQL;

        $this->runStep('prescriptions (500k)', $prescriptionSql);

        $medicalRecordSql = <<<SQL
            INSERT INTO medical_records (pet_id, professional_id, record_date, weight, temperature, heart_rate, respiratory_rate, diagnosis, treatment_plan, created_at, updated_at)
            SELECT
                pp.pet_id,
                prof.id,
                (CURRENT_DATE - (s % 700)),
                round((1 + random() * 39)::numeric, 2),
                round((37 + random() * 3)::numeric, 1),
                60 + (s % 100),
                10 + (s % 30),
                pools.diagnoses[1 + (s % 6)],
                'Retorno em 15 dias para reavaliação.',
                now() - (s % 700) * INTERVAL '1 day',
                now() - (s % 700) * INTERVAL '1 day'
            FROM generate_series(0, {$lastMedicalRecordSeq}) AS s
            JOIN bench_pet_pool pp ON pp.rn = 1 + (s % {$petCount})
            JOIN bench_professional_pool prof ON prof.rn = 1 + ((s * 19 + 29) % {$professionalCount})
            CROSS JOIN LATERAL (
                SELECT ARRAY[
                    'Otite', 'Dermatite alérgica', 'Gastroenterite',
                    'Check-up de rotina', 'Fratura em recuperação', 'Obesidade'
                ]::text[] AS diagnoses
            ) pools;
            SQL;

        $this->runStep('medical_records (500k)', $medicalRecordSql);
    }

    /**
     * Obrigatorio: sem estatisticas atualizadas o EXPLAIN da Fase 4 e lixo —
     * o planner assume defaults e escolhe planos sem relacao com a realidade.
     *
     * `max_parallel_maintenance_workers = 0` desliga o VACUUM paralelo: o
     * `/dev/shm` padrao do container Postgres tem 64MB, e os workers paralelos
     * tentam alocar segmentos de memoria compartilhada dimensionados por
     * `maintenance_work_mem` (1GB aqui) — estourou o tmpfs com "No space left
     * on device" no dataset de benchmark. `docker-compose.yml` (onde estaria
     * o ajuste de `--shm-size`) e território de outro agente; a correção fica
     * inteira do lado do SQL.
     */
    private function vacuumAnalyze(): void
    {
        $this->runStep('desliga vacuum paralelo', 'SET max_parallel_maintenance_workers = 0;');
        $this->runStep('VACUUM ANALYZE', 'VACUUM ANALYZE;');
    }

    /**
     * Executa uma statement e reporta tempo — o log de duração por bloco é
     * parte do entregável (tempo do seed por tabela).
     *
     * @param  array<int, mixed>  $bindings
     */
    private function runStep(string $label, string $sql, array $bindings = []): void
    {
        $startedAt = microtime(true);

        DB::statement($sql, $bindings);

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);
        $this->command?->info("  [{$elapsedSeconds}s] {$label}");
    }
}
