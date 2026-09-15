<?php

namespace Database\Seeders\Dataset;

use App\Enums\ProfessionalType;

/**
 * Nomes fantasia por tipo de negócio — e, de propósito, uma minoria de nomes que NÃO
 * denunciam o tipo.
 *
 * ── Por que os nomes neutros existem ──────────────────────────────────────────────────
 * Nome não é regra. Uma clínica pode se chamar "Amigo Fiel" e um petshop pode se chamar
 * "Recanto Animal" — nada no nome obriga a dizer o que o estabelecimento é. Se TODO cadastro
 * de teste tivesse o tipo embutido no nome ("Clínica Veterinária X", como na base de
 * benchmark), a busca pareceria funcionar porque estaria acertando pelo motivo errado: o
 * casamento textual do nome cobriria o buraco da evidência de competência, e o dia em que um
 * cadastro real com nome neutro aparecesse, ele simplesmente sumiria da busca.
 * `NEUTRAL_SHARE` garante que uma fatia do dataset só possa ser encontrada pela evidência
 * dura (serviço/especialidade), que é o que a Tarefa 2 precisa exercitar.
 *
 * ── Nome de pessoa vs. nome de negócio ────────────────────────────────────────────────
 * `vet` é pessoa física (CPF + CRMV): o nome é "Dr(a). Fulano de Tal" e `business_name` fica
 * nulo. Os outros seis tipos são PJ: `users.name` e `business_name` carregam o nome fantasia.
 */
final class BusinessNamePools
{
    /** Um em cada seis cadastros PJ recebe nome neutro. */
    public const NEUTRAL_SHARE = 6;

    /** @var list<string> */
    public const NEUTRAL = [
        'Amigo Fiel', 'Recanto Animal', 'Mundo Pet', 'Patas & Cia', 'Bem-Estar Animal',
        'Espaço Aurora', 'Casa dos Bichos', 'Vida Animal', 'Reino dos Bichos', 'Vila Pet',
        'Focinho Feliz', 'Quatro Patas', 'Refúgio Animal', 'Alegria Pet', 'Doce Lar Animal',
    ];

    /** @var list<string> */
    public const QUALIFIERS = [
        'Ipiranga', 'Jardins', 'Vila Nova', 'Central', 'do Bosque', 'Aurora', 'Santa Cruz',
        'São Jorge', 'Bela Vista', 'Paulista', 'do Parque', 'Morumbi', 'Anhanguera',
        'Boa Vista', 'Nova Era', 'Primavera', 'Real', 'Horizonte', 'Guanabara', 'Atlântica',
    ];

    /**
     * Prefixos plausíveis por tipo. Um `laboratory` nunca se chama "Banho & Tosa", um
     * `grooming` nunca se chama "Hospital Veterinário" — coerência de nome é a camada mais
     * visível do cadastro e a primeira que o dono do produto percebe quando está errada.
     *
     * @return list<string>
     */
    public static function prefixesFor(ProfessionalType $type): array
    {
        return match ($type) {
            ProfessionalType::VET => [],
            ProfessionalType::CLINIC => ['Clínica Veterinária', 'Hospital Veterinário', 'Centro Veterinário', 'Policlínica Animal'],
            ProfessionalType::LABORATORY => ['Laboratório Veterinário', 'Diagnóstico Animal', 'Vetlab', 'Centro de Diagnóstico Veterinário'],
            ProfessionalType::PETSHOP => ['Petshop', 'Pet Center', 'Agropecuária', 'Casa Pet'],
            ProfessionalType::PET_HOTEL => ['Hotel Pet', 'Creche Pet', 'Hospedagem Animal', 'Pousada Animal'],
            ProfessionalType::GROOMING => ['Banho & Tosa', 'Estética Animal', 'Espaço Pet Groom', 'Studio Pet'],
            ProfessionalType::TRAINING => ['Adestramento', 'Centro de Adestramento', 'Escola Canina', 'Academia Pet'],
        };
    }

    /**
     * Separado por gênero porque o pronome de tratamento do veterinário volante ("Dr." /
     * "Dra.") precisa CONCORDAR com o primeiro nome. Sorteados de forma independente, o
     * dataset produzia "Dra. Vinícius Lima" e "Dra. Otávio Melo" — erro pequeno e visível na
     * primeira busca por nome que o dono do produto faz. Ver `ProfessionalNaming`.
     *
     * @var list<string>
     */
    public const FEMININE_FIRST_NAMES = [
        'Ana', 'Beatriz', 'Camila', 'Daniela', 'Fernanda', 'Gabriela', 'Helena', 'Juliana',
        'Larissa', 'Mariana', 'Nathalia', 'Patrícia', 'Renata', 'Sofia', 'Vanessa', 'Yasmin',
    ];

    /** @var list<string> */
    public const MASCULINE_FIRST_NAMES = [
        'André', 'Bruno', 'Carlos', 'Eduardo', 'Felipe', 'Gustavo', 'Igor', 'Leonardo',
        'Marcelo', 'Otávio', 'Rafael', 'Rodrigo', 'Thiago', 'Vinícius',
    ];

    /**
     * União das duas listas — para uso onde não há pronome de tratamento envolvido
     * (responsável técnico, nome de tutor).
     *
     * @var list<string>
     */
    public const FIRST_NAMES = [...self::FEMININE_FIRST_NAMES, ...self::MASCULINE_FIRST_NAMES];

    /** @var list<string> */
    public const LAST_NAMES = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira',
        'Lima', 'Gomes', 'Ribeiro', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes',
        'Vieira', 'Barbosa', 'Rocha', 'Dias', 'Freitas', 'Cardoso', 'Ramos', 'Gonçalves',
        'Teixeira', 'Araújo', 'Melo', 'Castro', 'Monteiro', 'Moura',
    ];

    /** @var list<string> */
    public const PET_NAMES = [
        'Bella', 'Luna', 'Thor', 'Mel', 'Nina', 'Simba', 'Bob', 'Amora', 'Zeus', 'Lola',
        'Max', 'Cacau', 'Frida', 'Toby', 'Pipoca', 'Naruto', 'Bidu', 'Maia', 'Rex', 'Pretinha',
        'Jack', 'Nala', 'Fiona', 'Loki', 'Bono', 'Tita', 'Chico', 'Aurora', 'Bento', 'Kiara',
    ];

    /** @var list<string> */
    public const COAT_COLORS = [
        'Branco', 'Preto', 'Caramelo', 'Cinza', 'Tricolor', 'Dourado', 'Rajado', 'Malhado',
    ];
}
