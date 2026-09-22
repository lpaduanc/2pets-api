<?php

namespace Database\Seeders;

use App\Enums\InventoryCategory;
use App\Enums\OrganizationType;
use App\Enums\ServiceCategory;
use App\Enums\StockMovementType;
use App\Models\Availability;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Commercial\PaymentMethodProvisioner;
use App\Services\Organization\TeamSpecialtyAggregator;
use App\Services\Organization\UserRoleReconciler;
use App\Services\Stock\LegacyInventoryClassifier;
use App\Services\Stock\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Clínica veterinária COMPLETA para demo/QA manual: todos os equipamentos declaráveis,
 * catálogo de exames (laboratório + imagem), vacinas (serviço de aplicação + dose em estoque
 * clínico com lote), produtos de revenda com estoque e lote, insumos, fornecedores, e equipe
 * de 5 pessoas — 2 veterinários + 3 não clínicos — todos operando o PDV.
 *
 * Acesso ao PDV: a API aceita qualquer `organization_members.is_active` (ver
 * `CommercialScopeResolver::canOperateCounter`), mas o app só abre `/professional/*` para
 * `users.role = 'professional'` — por isso os 3 funcionários também têm esse `role`. Eles
 * ficam com `user_type = null`: qualquer `ProfessionalType` daria um papel Spatie próprio
 * (`clinic` → `clinic_owner`), e o papel deles deve vir só do cargo (`petshop_staff`).
 *
 * Papéis Spatie via `UserRoleReconciler` (o mesmo caminho do aceite de convite), não
 * `assignRole` direto. Estoque de produto só via `StockService` (saldo de abertura), e só
 * quando o produto acabou de ser criado — rodar de novo não duplica saldo. Idempotente.
 */
class ClinicaQuatroPatasSeeder extends Seeder
{
    private const CNPJ = '45812763000175';

    private const PASSWORD = 'password';

    private const ADDRESS = [
        'address' => 'Avenida Ibirapuera',
        'number' => '2120',
        'neighborhood' => 'Moema',
        'city' => 'São Paulo',
        'state' => 'SP',
        'zip_code' => '04028-001',
        'latitude' => -23.603400,
        'longitude' => -46.665700,
    ];

    /** Todos os valores de `ClinicalEquipment` — a clínica declara estrutura completa. */
    private const DECLARED_EQUIPMENT = [
        'xray_machine', 'ultrasound_machine', 'ecg_machine', 'surgery_room', 'icu',
        'laboratory', 'pharmacy', 'kennels', 'oxygen_therapy', 'dental_equipment',
        'anesthesia_machine', 'ambulance', 'portable_multiparameter_monitor',
        'portable_oxygen_therapy', 'vascular_doppler', 'point_of_care_analyzer',
    ];

    private const VETERINARIANS = [
        [
            'email' => 'dra.camila.quatropatas@2pets.com',
            'name' => 'Dra. Camila Nogueira',
            'phone' => '(11) 97111-2201',
            'crmv' => '45123-SP',
            'specialties' => ['clinica_geral', 'dermatologia'],
            'description' => 'Clínica geral e dermatologia de cães e gatos. Responsável técnica da clínica.',
            'experience_years' => 12,
            'service' => 'Consulta Clínica Geral',
            'price' => 180.00,
        ],
        [
            'email' => 'dr.rafael.quatropatas@2pets.com',
            'name' => 'Dr. Rafael Tavares',
            'phone' => '(11) 97111-2202',
            'crmv' => '51877-SP',
            'specialties' => ['cirurgia', 'ortopedia'],
            'description' => 'Cirurgia de tecidos moles e ortopedia.',
            'experience_years' => 9,
            'service' => 'Consulta Cirúrgica / Ortopédica',
            'price' => 240.00,
        ],
    ];

    private const STAFF = [
        ['email' => 'juliana.recepcao.quatropatas@2pets.com', 'name' => 'Juliana Alves', 'phone' => '(11) 97111-2203', 'role' => OrganizationMember::ROLE_RECEPTIONIST, 'employee_id' => 'QP-004', 'salary' => 2600.00],
        ['email' => 'marcos.auxiliar.quatropatas@2pets.com', 'name' => 'Marcos Lima', 'phone' => '(11) 97111-2204', 'role' => OrganizationMember::ROLE_ASSISTANT, 'employee_id' => 'QP-005', 'salary' => 2800.00],
        ['email' => 'beatriz.tecnica.quatropatas@2pets.com', 'name' => 'Beatriz Santos', 'phone' => '(11) 97111-2205', 'role' => OrganizationMember::ROLE_TECHNICIAN, 'employee_id' => 'QP-006', 'salary' => 3200.00],
    ];

    private const WEEKLY_SCHEDULE = [
        ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '19:00'],
        ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '19:00'],
        ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '19:00'],
        ['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '19:00'],
        ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '19:00'],
        ['day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '13:00'],
    ];

    /** [nome, categoria, duração (min), preço] — cobrados no PDV pela organização. */
    private const CLINIC_SERVICES = [
        // Consultas e urgência
        ['Consulta de Retorno', ServiceCategory::CONSULTATION, 30, 90.00],
        ['Consulta Dermatológica', ServiceCategory::CONSULTATION, 40, 220.00],
        ['Atendimento de Emergência', ServiceCategory::EMERGENCY, 60, 350.00],
        ['Atendimento de Emergência — Noturno/Feriado', ServiceCategory::EMERGENCY, 60, 480.00],
        // Exames laboratoriais
        ['Hemograma Completo', ServiceCategory::LABORATORY, 15, 75.00],
        ['Perfil Bioquímico (Renal + Hepático)', ServiceCategory::LABORATORY, 15, 160.00],
        ['Perfil Pré-Cirúrgico', ServiceCategory::LABORATORY, 15, 220.00],
        ['Urinálise (EAS)', ServiceCategory::LABORATORY, 15, 60.00],
        ['Relação Proteína/Creatinina Urinária', ServiceCategory::LABORATORY, 15, 90.00],
        ['Exame Parasitológico de Fezes', ServiceCategory::LABORATORY, 15, 50.00],
        ['Citologia', ServiceCategory::LABORATORY, 20, 110.00],
        ['Raspado de Pele / Tricograma', ServiceCategory::LABORATORY, 20, 80.00],
        ['Cultura e Antibiograma', ServiceCategory::LABORATORY, 15, 180.00],
        ['Glicemia (glicosímetro)', ServiceCategory::LABORATORY, 10, 25.00],
        ['T4 Total', ServiceCategory::LABORATORY, 15, 95.00],
        ['Teste Rápido FIV/FeLV', ServiceCategory::LABORATORY, 15, 150.00],
        ['Teste Rápido Cinomose', ServiceCategory::LABORATORY, 15, 120.00],
        ['Teste Rápido Parvovirose', ServiceCategory::LABORATORY, 15, 120.00],
        ['Teste Rápido Leishmaniose', ServiceCategory::LABORATORY, 15, 130.00],
        ['Teste Rápido Erliquiose (4Dx)', ServiceCategory::LABORATORY, 15, 170.00],
        ['Hemogasometria e Eletrólitos', ServiceCategory::LABORATORY, 15, 140.00],
        // Exames de imagem e cardiologia
        ['Raio-X (1 região, 2 incidências)', ServiceCategory::IMAGING, 20, 160.00],
        ['Raio-X de Tórax (3 incidências)', ServiceCategory::IMAGING, 25, 210.00],
        ['Ultrassonografia Abdominal', ServiceCategory::IMAGING, 30, 220.00],
        ['Ultrassonografia Gestacional', ServiceCategory::IMAGING, 30, 180.00],
        ['Ecocardiograma', ServiceCategory::IMAGING, 40, 320.00],
        ['Eletrocardiograma', ServiceCategory::IMAGING, 20, 140.00],
        ['Aferição de Pressão Arterial (Doppler)', ServiceCategory::IMAGING, 15, 60.00],
        // Vacinação (aplicação — a dose sai do estoque clínico)
        ['Vacina V10 (Múltipla Canina)', ServiceCategory::VACCINATION, 15, 110.00],
        ['Vacina V11 (Múltipla Canina)', ServiceCategory::VACCINATION, 15, 125.00],
        ['Vacina Antirrábica', ServiceCategory::VACCINATION, 15, 70.00],
        ['Vacina Gripe Canina (Tosse dos Canis)', ServiceCategory::VACCINATION, 15, 115.00],
        ['Vacina Giárdia', ServiceCategory::VACCINATION, 15, 105.00],
        ['Vacina Leishmaniose', ServiceCategory::VACCINATION, 15, 190.00],
        ['Vacina V4 (Quádrupla Felina)', ServiceCategory::VACCINATION, 15, 120.00],
        ['Vacina V5 (Quíntupla Felina)', ServiceCategory::VACCINATION, 15, 145.00],
        ['Vacina FeLV (Leucemia Felina)', ServiceCategory::VACCINATION, 15, 130.00],
        ['Aplicação de Microchip', ServiceCategory::OTHER, 10, 90.00],
        // Cirurgia, odonto, internação
        ['Castração Macho — Cão', ServiceCategory::SURGERY, 60, 650.00],
        ['Castração Fêmea — Cadela (OSH)', ServiceCategory::SURGERY, 90, 950.00],
        ['Castração Macho — Gato', ServiceCategory::SURGERY, 40, 380.00],
        ['Castração Fêmea — Gata (OSH)', ServiceCategory::SURGERY, 60, 550.00],
        ['Nodulectomia', ServiceCategory::SURGERY, 60, 800.00],
        ['Cistotomia', ServiceCategory::SURGERY, 90, 1400.00],
        ['Correção de Luxação de Patela', ServiceCategory::SURGERY, 120, 2800.00],
        ['Anestesia Inalatória (por hora)', ServiceCategory::SURGERY, 60, 350.00],
        ['Profilaxia Dentária (Tartarectomia)', ServiceCategory::DENTAL, 60, 480.00],
        ['Extração Dentária (por elemento)', ServiceCategory::DENTAL, 20, 90.00],
        ['Diária de Internação', ServiceCategory::HOSPITALIZATION, 1440, 280.00],
        ['Diária de UTI', ServiceCategory::HOSPITALIZATION, 1440, 550.00],
        ['Fluidoterapia (sessão)', ServiceCategory::HOSPITALIZATION, 60, 90.00],
        ['Oxigenoterapia (hora)', ServiceCategory::HOSPITALIZATION, 60, 70.00],
        ['Curativo Simples', ServiceCategory::OTHER, 15, 50.00],
        ['Aplicação de Medicação (SC/IM/IV)', ServiceCategory::OTHER, 10, 35.00],
        ['Remoção de Pontos', ServiceCategory::OTHER, 15, 40.00],
        ['Transporte em Ambulância (até 10 km)', ServiceCategory::OTHER, 60, 150.00],
        ['Consulta Nutricional', ServiceCategory::NUTRITION, 40, 200.00],
        ['Sessão de Fisioterapia', ServiceCategory::REHABILITATION, 45, 130.00],
        ['Eutanásia', ServiceCategory::OTHER, 30, 400.00],
    ];

    private const SUPPLIERS = [
        'DISTRIVET' => ['legal_name' => 'Distrivet Distribuidora de Produtos Veterinários Ltda', 'trade_name' => 'Distrivet', 'document' => '11222333000181', 'phone' => '(11) 3333-1000', 'email' => 'pedidos@distrivet.example', 'lead_time_days' => 3, 'payment_terms' => '30 dias'],
        'PETFOOD' => ['legal_name' => 'Pet Food Atacado Comércio Ltda', 'trade_name' => 'Pet Food Atacado', 'document' => '22333444000181', 'phone' => '(11) 3333-2000', 'email' => 'vendas@petfoodatacado.example', 'lead_time_days' => 5, 'payment_terms' => '28/56 dias'],
        'HOSPVET' => ['legal_name' => 'HospVet Materiais Médico-Hospitalares Ltda', 'trade_name' => 'HospVet', 'document' => '33444555000181', 'phone' => '(11) 3333-3000', 'email' => 'comercial@hospvet.example', 'lead_time_days' => 7, 'payment_terms' => '30/60/90 dias'],
    ];

    private const GROUPS = [
        'Rações', 'Petiscos', 'Antipulgas e Carrapaticidas', 'Vermífugos', 'Medicamentos',
        'Suplementos', 'Higiene e Dermatologia', 'Acessórios', 'Colares e Roupas Pós-Cirúrgicas',
    ];

    /**
     * [sku, nome, grupo, marca, fornecedor, preço, custo, estoque, mín, lote?, validade?]
     * Lote/validade só para o que tem risco sanitário (medicamento, antiparasitário, alimento).
     */
    private const PRODUCTS = [
        ['RAC-RC-MINI-1', 'Ração Royal Canin Mini Adult 1kg', 'Rações', 'Royal Canin', 'PETFOOD', 79.90, 52.00, 24, 6, 'RC24081', '+14 months'],
        ['RAC-RC-MINI-75', 'Ração Royal Canin Mini Adult 7,5kg', 'Rações', 'Royal Canin', 'PETFOOD', 389.90, 262.00, 8, 2, 'RC24082', '+14 months'],
        ['RAC-RC-RENAL-2', 'Ração Royal Canin Renal Cães 2kg', 'Rações', 'Royal Canin', 'PETFOOD', 189.90, 128.00, 6, 2, 'RC24090', '+12 months'],
        ['RAC-RC-GI-2', 'Ração Royal Canin Gastrointestinal Cães 2kg', 'Rações', 'Royal Canin', 'PETFOOD', 179.90, 121.00, 6, 2, 'RC24091', '+12 months'],
        ['RAC-RC-URI-G15', 'Ração Royal Canin Urinary S/O Gatos 1,5kg', 'Rações', 'Royal Canin', 'PETFOOD', 159.90, 108.00, 6, 2, 'RC24092', '+12 months'],
        ['RAC-PP-CAST-3', 'Ração Premier Gatos Castrados 1,5kg', 'Rações', 'Premier', 'PETFOOD', 74.90, 49.00, 12, 4, 'PR24110', '+12 months'],
        ['RAC-HILLS-AD-156', 'Ração Hill\'s a/d Lata 156g', 'Rações', 'Hill\'s', 'PETFOOD', 24.90, 15.50, 30, 10, 'HL24031', '+18 months'],
        ['PET-DREAMIES-40', 'Petisco Dreamies Gatos 40g', 'Petiscos', 'Dreamies', 'PETFOOD', 9.90, 5.80, 40, 10, 'DR24401', '+10 months'],
        ['PET-BIFINHO-65', 'Petisco Bifinho Carne 65g', 'Petiscos', 'Keldog', 'PETFOOD', 8.90, 4.90, 40, 10, 'KD24402', '+10 months'],
        ['APG-NEX-4-10', 'NexGard 4,1 a 10kg (1 tablete)', 'Antipulgas e Carrapaticidas', 'Boehringer Ingelheim', 'DISTRIVET', 79.90, 54.00, 20, 5, 'NG2410A', '+20 months'],
        ['APG-NEXSP-10-25', 'NexGard Spectra 7,6 a 15kg (1 tablete)', 'Antipulgas e Carrapaticidas', 'Boehringer Ingelheim', 'DISTRIVET', 109.90, 76.00, 15, 5, 'NS2410B', '+20 months'],
        ['APG-BRAV-10-20', 'Bravecto 10 a 20kg (1 comprimido)', 'Antipulgas e Carrapaticidas', 'MSD Saúde Animal', 'DISTRIVET', 229.90, 162.00, 12, 4, 'BV2409C', '+24 months'],
        ['APG-SIMP-10-20', 'Simparic 10,1 a 20kg (1 comprimido)', 'Antipulgas e Carrapaticidas', 'Zoetis', 'DISTRIVET', 89.90, 61.00, 15, 5, 'SP2408D', '+20 months'],
        ['APG-REVOL-GATO', 'Revolution Gatos 2,6 a 7,5kg (pipeta)', 'Antipulgas e Carrapaticidas', 'Zoetis', 'DISTRIVET', 84.90, 58.00, 12, 4, 'RV2408E', '+20 months'],
        ['APG-SERESTO-G', 'Coleira Seresto Cães acima de 8kg', 'Antipulgas e Carrapaticidas', 'Elanco', 'DISTRIVET', 259.90, 185.00, 6, 2, 'SR2407F', '+30 months'],
        ['VERM-DRONTAL-P', 'Drontal Plus Cães 10kg (2 comprimidos)', 'Vermífugos', 'Elanco', 'DISTRIVET', 49.90, 31.00, 20, 6, 'DT2410G', '+24 months'],
        ['VERM-DRONTAL-G', 'Drontal Gatos (4 comprimidos)', 'Vermífugos', 'Elanco', 'DISTRIVET', 59.90, 38.00, 15, 5, 'DG2410H', '+24 months'],
        ['VERM-MILBEMAX-C', 'Milbemax Cães 5 a 25kg (2 comprimidos)', 'Vermífugos', 'Elanco', 'DISTRIVET', 69.90, 45.00, 15, 5, 'MB2409I', '+24 months'],
        ['MED-APOQUEL-16', 'Apoquel 16mg (20 comprimidos)', 'Medicamentos', 'Zoetis', 'DISTRIVET', 289.90, 205.00, 8, 3, 'AP2411J', '+18 months'],
        ['MED-MELOX-2', 'Meloxicam 2mg Maxicam (10 comprimidos)', 'Medicamentos', 'Ourofino', 'DISTRIVET', 32.90, 19.00, 20, 6, 'MX2411K', '+18 months'],
        ['MED-AGEM-50', 'Agemoxi CL 50mg (10 comprimidos)', 'Medicamentos', 'Agener União', 'DISTRIVET', 39.90, 24.00, 20, 6, 'AG2410L', '+18 months'],
        ['MED-PREDNI-20', 'Prediderm 20mg (10 comprimidos)', 'Medicamentos', 'Ourofino', 'DISTRIVET', 29.90, 17.50, 20, 6, 'PD2410M', '+18 months'],
        ['MED-OMEP-10', 'Omeprazol Vet 10mg (14 cápsulas)', 'Medicamentos', 'Vetnil', 'DISTRIVET', 34.90, 20.00, 15, 5, 'OM2409N', '+18 months'],
        ['MED-ONDAN-4', 'Ondansetrona Vet 4mg (10 comprimidos)', 'Medicamentos', 'Vetnil', 'DISTRIVET', 42.90, 26.00, 12, 4, 'ON2409O', '+18 months'],
        ['MED-OTO-15', 'Otomax Solução Otológica 15ml', 'Medicamentos', 'MSD Saúde Animal', 'DISTRIVET', 79.90, 52.00, 10, 3, 'OT2408P', '+18 months'],
        ['MED-COLIRIO-5', 'Colírio Epitezan 3,5g', 'Medicamentos', 'Allergan', 'DISTRIVET', 29.90, 17.00, 10, 3, 'EP2408Q', '+18 months'],
        ['SUP-ORGANEW-100', 'Organew Suplemento 100g', 'Suplementos', 'Vetnil', 'DISTRIVET', 59.90, 37.00, 10, 3, 'OG2410R', '+18 months'],
        ['SUP-OMEGA-30', 'Ômega 3 Ograx-3 1000 (30 cápsulas)', 'Suplementos', 'Avert', 'DISTRIVET', 94.90, 63.00, 10, 3, 'OX2410S', '+18 months'],
        ['SUP-CONDRO-60', 'Condroton 1000 (60 comprimidos)', 'Suplementos', 'Vetnil', 'DISTRIVET', 139.90, 93.00, 6, 2, 'CD2410T', '+18 months'],
        ['HIG-CLOREX-230', 'Shampoo Clorexidina Dermatológico 230ml', 'Higiene e Dermatologia', 'Ourofino', 'DISTRIVET', 54.90, 34.00, 12, 4, 'CL2409U', '+24 months'],
        ['HIG-PERIO-250', 'Limpador de Ouvidos Epiotic 100ml', 'Higiene e Dermatologia', 'Virbac', 'DISTRIVET', 69.90, 45.00, 10, 3, 'EO2409V', '+24 months'],
        ['HIG-PASTA-DENT', 'Pasta de Dente Enzimática C.E.T. 70g', 'Higiene e Dermatologia', 'Virbac', 'DISTRIVET', 49.90, 31.00, 10, 3, 'CT2409W', '+24 months'],
        ['HIG-TAPETE-30', 'Tapete Higiênico 60x60 (30 un)', 'Higiene e Dermatologia', 'Super Secão', 'PETFOOD', 69.90, 44.00, 15, 5, null, null],
        ['ACE-GUIA-P', 'Guia Retrátil 5m até 15kg', 'Acessórios', 'Flexi', 'PETFOOD', 99.90, 58.00, 6, 2, null, null],
        ['ACE-PEITORAL-M', 'Peitoral Ajustável Tamanho M', 'Acessórios', 'Zee.Dog', 'PETFOOD', 119.90, 70.00, 6, 2, null, null],
        ['ACE-TRANSP-2', 'Caixa de Transporte N.2', 'Acessórios', 'Plast Pet', 'PETFOOD', 129.90, 78.00, 5, 2, null, null],
        ['ACE-COMED-INOX', 'Comedouro Inox Antiderrapante 500ml', 'Acessórios', 'Chalesco', 'PETFOOD', 34.90, 18.00, 10, 3, null, null],
        ['ACE-MICROCHIP', 'Tag de Identificação com QR Code', 'Acessórios', '2pets', 'PETFOOD', 39.90, 15.00, 20, 5, null, null],
        ['POS-COLAR-EL-M', 'Colar Elizabetano N.3 (M)', 'Colares e Roupas Pós-Cirúrgicas', 'Chalesco', 'HOSPVET', 29.90, 13.00, 15, 5, null, null],
        ['POS-ROUPA-CIR-M', 'Roupa Cirúrgica Pós-Operatória (M)', 'Colares e Roupas Pós-Cirúrgicas', 'Pet Med', 'HOSPVET', 59.90, 32.00, 10, 3, null, null],
    ];

    /**
     * Estoque clínico (`inventories`) — é daqui que vacinação e vermifugação baixam a dose
     * (`ClinicalStockDeductionService`); o PDV não lê esta tabela.
     * [nome, categoria, qtd, unidade, mín, custo, venda, fornecedor, validade?]
     */
    private const CLINICAL_INVENTORY = [
        // Vacinas (doses)
        ['Vacina V10 Vanguard Plus — dose', 'vaccine', 40, 'dose', 10, 38.00, 110.00, 'Distrivet', '+10 months'],
        ['Vacina V11 Nobivac DHPPi+L4 — dose', 'vaccine', 30, 'dose', 10, 46.00, 125.00, 'Distrivet', '+10 months'],
        ['Vacina Antirrábica Defensor — dose', 'vaccine', 50, 'dose', 10, 12.00, 70.00, 'Distrivet', '+12 months'],
        ['Vacina Gripe Canina Bronchi-Shield — dose', 'vaccine', 20, 'dose', 5, 42.00, 115.00, 'Distrivet', '+10 months'],
        ['Vacina Giárdia GiardiaVax — dose', 'vaccine', 20, 'dose', 5, 39.00, 105.00, 'Distrivet', '+10 months'],
        ['Vacina Leishmaniose Leish-Tec — dose', 'vaccine', 15, 'dose', 5, 88.00, 190.00, 'Distrivet', '+9 months'],
        ['Vacina V3 Felocell — dose', 'vaccine', 15, 'dose', 5, 40.00, 110.00, 'Distrivet', '+10 months'],
        ['Vacina V4 Fel-O-Vax IV — dose', 'vaccine', 20, 'dose', 5, 44.00, 120.00, 'Distrivet', '+10 months'],
        ['Vacina V5 Purevax RCPCh FeLV — dose', 'vaccine', 15, 'dose', 5, 62.00, 145.00, 'Distrivet', '+10 months'],
        ['Vacina FeLV Leucogen — dose', 'vaccine', 15, 'dose', 5, 52.00, 130.00, 'Distrivet', '+10 months'],
        // Medicamentos de uso clínico
        ['Dipirona Sódica 500mg/ml — frasco 10ml', 'medication', 20, 'frasco', 5, 6.50, null, 'Distrivet', '+18 months'],
        ['Meloxicam Injetável 0,2% — frasco 10ml', 'medication', 10, 'frasco', 3, 28.00, null, 'Distrivet', '+18 months'],
        ['Tramadol 50mg/ml — ampola 2ml', 'medication', 30, 'ampola', 10, 3.80, null, 'Distrivet', '+18 months'],
        ['Propofol 1% — frasco 20ml', 'medication', 15, 'frasco', 5, 22.00, null, 'HospVet', '+12 months'],
        ['Isoflurano — frasco 100ml', 'medication', 6, 'frasco', 2, 145.00, null, 'HospVet', '+24 months'],
        ['Cetamina 10% — frasco 10ml', 'medication', 8, 'frasco', 3, 38.00, null, 'HospVet', '+18 months'],
        ['Cefalotina 1g — frasco-ampola', 'medication', 20, 'frasco', 5, 9.50, null, 'HospVet', '+18 months'],
        ['Ondansetrona 2mg/ml — ampola 4ml', 'medication', 20, 'ampola', 5, 4.20, null, 'Distrivet', '+18 months'],
        ['Furosemida 10mg/ml — ampola 2ml', 'medication', 15, 'ampola', 5, 2.90, null, 'Distrivet', '+18 months'],
        ['Adrenalina 1mg/ml — ampola', 'medication', 10, 'ampola', 5, 3.50, null, 'HospVet', '+18 months'],
        ['Vermífugo Drontal Plus — comprimido (uso clínico)', 'medication', 30, 'comprimido', 10, 15.50, 25.00, 'Distrivet', '+24 months'],
        // Insumos
        ['Soro Ringer Lactato 500ml', 'supply', 40, 'bolsa', 15, 6.80, null, 'HospVet', '+24 months'],
        ['Soro Fisiológico 0,9% 250ml', 'supply', 40, 'bolsa', 15, 4.90, null, 'HospVet', '+24 months'],
        ['Seringa 3ml (cx 100)', 'supply', 5, 'caixa', 2, 32.00, null, 'HospVet', null],
        ['Agulha 25x7 (cx 100)', 'supply', 5, 'caixa', 2, 18.00, null, 'HospVet', null],
        ['Cateter Intravenoso 22G (cx 50)', 'supply', 4, 'caixa', 2, 95.00, null, 'HospVet', null],
        ['Equipo Macrogotas', 'supply', 40, 'unidade', 15, 2.60, null, 'HospVet', null],
        ['Luva de Procedimento M (cx 100)', 'supply', 10, 'caixa', 4, 38.00, null, 'HospVet', null],
        ['Luva Cirúrgica Estéril 7.5 (par)', 'supply', 50, 'par', 20, 2.80, null, 'HospVet', null],
        ['Fio de Sutura Nylon 3-0 (cx 24)', 'supply', 3, 'caixa', 1, 110.00, null, 'HospVet', null],
        ['Fio Absorvível Vicryl 2-0 (cx 12)', 'supply', 3, 'caixa', 1, 240.00, null, 'HospVet', null],
        ['Compressa de Gaze Estéril (pct 10)', 'supply', 60, 'pacote', 20, 1.90, null, 'HospVet', null],
        ['Atadura de Crepom 10cm', 'supply', 40, 'unidade', 15, 1.40, null, 'HospVet', null],
        ['Clorexidina Degermante 2% — 1L', 'supply', 6, 'frasco', 2, 29.00, null, 'HospVet', '+24 months'],
        ['Álcool 70% — 1L', 'supply', 10, 'frasco', 4, 9.00, null, 'HospVet', '+24 months'],
        ['Tubo EDTA (roxo) (cx 100)', 'supply', 3, 'caixa', 1, 45.00, null, 'HospVet', '+18 months'],
        ['Tubo Seco com Gel (amarelo) (cx 100)', 'supply', 3, 'caixa', 1, 52.00, null, 'HospVet', '+18 months'],
        ['Kit Teste Rápido FIV/FeLV (cx 10)', 'supply', 3, 'caixa', 1, 480.00, null, 'Distrivet', '+12 months'],
        ['Kit Teste Rápido Cinomose Ag (cx 10)', 'supply', 3, 'caixa', 1, 390.00, null, 'Distrivet', '+12 months'],
        ['Kit Teste Rápido Parvovirose Ag (cx 10)', 'supply', 3, 'caixa', 1, 390.00, null, 'Distrivet', '+12 months'],
        ['Tiras de Glicemia (cx 50)', 'supply', 4, 'caixa', 1, 85.00, null, 'HospVet', '+12 months'],
        ['Gel para Ultrassom 1kg', 'supply', 4, 'frasco', 1, 16.00, null, 'HospVet', null],
        ['Microchip de Identificação ISO 11784', 'supply', 30, 'unidade', 10, 18.00, null, 'Distrivet', null],
        // Equipamentos (patrimônio — quantidade = unidades na clínica)
        ['Aparelho de Raio-X Digital (DR) 300mA', 'equipment', 1, 'unidade', 1, 185000.00, null, 'HospVet', null],
        ['Ultrassom com Doppler Colorido (3 transdutores)', 'equipment', 1, 'unidade', 1, 98000.00, null, 'HospVet', null],
        ['Eletrocardiógrafo Digital 12 Derivações', 'equipment', 1, 'unidade', 1, 14500.00, null, 'HospVet', null],
        ['Aparelho de Anestesia Inalatória com Ventilador', 'equipment', 1, 'unidade', 1, 42000.00, null, 'HospVet', null],
        ['Monitor Multiparamétrico (ECG, SpO2, PNI, Capnografia)', 'equipment', 2, 'unidade', 1, 23000.00, null, 'HospVet', null],
        ['Monitor Multiparamétrico Portátil', 'equipment', 1, 'unidade', 1, 9800.00, null, 'HospVet', null],
        ['Doppler Vascular Portátil', 'equipment', 1, 'unidade', 1, 3200.00, null, 'HospVet', null],
        ['Mesa Cirúrgica Pantográfica Inox', 'equipment', 1, 'unidade', 1, 8500.00, null, 'HospVet', null],
        ['Foco Cirúrgico de LED de Teto', 'equipment', 1, 'unidade', 1, 16000.00, null, 'HospVet', null],
        ['Bisturi Elétrico', 'equipment', 1, 'unidade', 1, 7400.00, null, 'HospVet', null],
        ['Autoclave 21 Litros', 'equipment', 1, 'unidade', 1, 6900.00, null, 'HospVet', null],
        ['Bomba de Infusão Volumétrica', 'equipment', 4, 'unidade', 2, 5200.00, null, 'HospVet', null],
        ['Bomba de Seringa', 'equipment', 2, 'unidade', 1, 4800.00, null, 'HospVet', null],
        ['Concentrador de Oxigênio 5L', 'equipment', 2, 'unidade', 1, 4500.00, null, 'HospVet', null],
        ['Cilindro de Oxigênio Portátil com Regulador', 'equipment', 2, 'unidade', 1, 1400.00, null, 'HospVet', null],
        ['Incubadora / Box de UTI com Oxigenoterapia', 'equipment', 2, 'unidade', 1, 18000.00, null, 'HospVet', null],
        ['Analisador Hematológico Automático', 'equipment', 1, 'unidade', 1, 38000.00, null, 'HospVet', null],
        ['Analisador Bioquímico', 'equipment', 1, 'unidade', 1, 45000.00, null, 'HospVet', null],
        ['Hemogasômetro Point-of-Care', 'equipment', 1, 'unidade', 1, 29000.00, null, 'HospVet', null],
        ['Microscópio Binocular', 'equipment', 1, 'unidade', 1, 5600.00, null, 'HospVet', null],
        ['Centrífuga Laboratorial', 'equipment', 1, 'unidade', 1, 2800.00, null, 'HospVet', null],
        ['Glicosímetro Veterinário', 'equipment', 2, 'unidade', 1, 350.00, null, 'HospVet', null],
        ['Aparelho de Ultrassom Odontológico com Micromotor', 'equipment', 1, 'unidade', 1, 9500.00, null, 'HospVet', null],
        ['Otoscópio / Oftalmoscópio', 'equipment', 2, 'unidade', 1, 1900.00, null, 'HospVet', null],
        ['Balança Digital Veterinária 300kg', 'equipment', 1, 'unidade', 1, 2400.00, null, 'HospVet', null],
        ['Leitor de Microchip', 'equipment', 1, 'unidade', 1, 650.00, null, 'HospVet', null],
        ['Tricótomo (Máquina de Tosa Clínica)', 'equipment', 2, 'unidade', 1, 1100.00, null, 'HospVet', null],
        ['Canil de Internação Inox (baia)', 'equipment', 8, 'unidade', 4, 3800.00, null, 'HospVet', null],
        ['Gatil de Internação Inox (baia)', 'equipment', 4, 'unidade', 2, 3400.00, null, 'HospVet', null],
        ['Refrigerador para Vacinas (2 a 8 °C)', 'equipment', 1, 'unidade', 1, 7800.00, null, 'HospVet', null],
        ['Ambulância Veterinária (Fiorino adaptada)', 'equipment', 1, 'unidade', 1, 120000.00, null, 'HospVet', null],
    ];

    private Organization $organization;

    private User $owner;

    public function run(): void
    {
        $this->owner = $this->seedOwner();
        $this->organization = $this->seedOrganization();
        $this->ensureMembership($this->owner, OrganizationMember::ROLE_OWNER, ['employee_id' => 'QP-001']);
        app(UserRoleReconciler::class)->reconcile($this->owner);

        $veterinarians = [];
        foreach (self::VETERINARIANS as $index => $data) {
            $veterinarians[] = $this->seedVeterinarian($data, 'QP-00'.($index + 2));
        }
        foreach (self::STAFF as $data) {
            $this->seedStaff($data);
        }

        // RT da clínica: a primeira veterinária da equipe.
        $this->organization->update([
            'technical_responsible_professional_id' => $veterinarians[0]->professional->id,
            'technical_responsible_name' => $veterinarians[0]->name,
            'technical_responsible_crmv' => '45123',
            'technical_responsible_crmv_state' => 'SP',
        ]);

        $this->seedClinicServices();
        $suppliers = $this->seedSuppliers();
        $this->seedProducts($suppliers);
        $this->seedClinicalProducts($suppliers);

        // Formas de pagamento padrão (Dinheiro, Pix, débito, crédito) já prontas no caixa.
        app(PaymentMethodProvisioner::class)->ensureDefaults($this->owner);

        app(TeamSpecialtyAggregator::class)->syncForOrganization($this->organization);
    }

    private function seedOwner(): User
    {
        $owner = $this->upsertUser('clinica.quatropatas@2pets.com', 'Clínica Veterinária Quatro Patas', '(11) 3456-7800', 'clinic');

        Professional::updateOrCreate(
            ['user_id' => $owner->id],
            [
                'professional_type' => 'clinic',
                'business_name' => 'Clínica Veterinária Quatro Patas',
                'cnpj' => self::CNPJ,
                'description' => 'Clínica veterinária completa em Moema: consultas, exames laboratoriais e de imagem, vacinação, cirurgia, odontologia, internação e UTI.',
                'opening_hours' => '08:00',
                'closing_hours' => '19:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'services_offered' => ['consulta', 'exames', 'vacinacao', 'cirurgia', 'odontologia', 'internacao', 'emergencia'],
                'products_sold' => ['racao', 'medicamentos', 'antiparasitarios', 'acessorios', 'higiene'],
                'equipment' => self::DECLARED_EQUIPMENT,
                'species_served' => ['dog', 'cat'],
                'experience_years' => 15,
                'average_rating' => 4.8,
                'total_reviews' => 212,
                'parking_available' => true,
                'wheelchair_accessible' => true,
                'accepts_credit_card' => true,
                'accepts_pet_insurance' => true,
                'emergency_available' => true,
            ]
        );

        return $owner;
    }

    private function seedOrganization(): Organization
    {
        return Organization::updateOrCreate(
            ['cnpj' => self::CNPJ],
            [
                'organization_type' => OrganizationType::CLINIC,
                'business_name' => 'Clínica Veterinária Quatro Patas',
                'description' => 'Clínica veterinária completa: consultas, exames laboratoriais e de imagem, vacinação, cirurgia, odontologia, internação e UTI.',
                'opening_hours' => '08:00',
                'closing_hours' => '19:00',
                'working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
                'service_radius_km' => 0,
                'services_offered' => ['consulta', 'exames', 'vacinacao', 'cirurgia', 'odontologia', 'internacao', 'emergencia'],
                'products_sold' => ['racao', 'medicamentos', 'antiparasitarios', 'acessorios', 'higiene'],
                ...self::ADDRESS,
            ]
        );
    }

    private function seedVeterinarian(array $data, string $employeeId): User
    {
        $veterinarian = $this->upsertUser($data['email'], $data['name'], $data['phone'], 'vet');

        Professional::updateOrCreate(
            ['user_id' => $veterinarian->id],
            [
                'professional_type' => 'vet',
                'crmv' => $data['crmv'],
                'crmv_state' => 'SP',
                'specialties' => $data['specialties'],
                'description' => $data['description'],
                'experience_years' => $data['experience_years'],
                'species_served' => ['dog', 'cat'],
                'average_rating' => 4.9,
                'total_reviews' => 60,
            ]
        );

        $this->ensureMembership($veterinarian, OrganizationMember::ROLE_VETERINARIAN, [
            'employee_id' => $employeeId,
            'employment_type' => 'full_time',
            'monthly_salary' => 9500.00,
        ]);
        app(UserRoleReconciler::class)->reconcile($veterinarian);

        Service::updateOrCreate(
            ['professional_id' => $veterinarian->id, 'name' => $data['service']],
            [
                'organization_id' => $this->organization->id,
                'category' => ServiceCategory::CONSULTATION->value,
                'duration' => 40,
                'price' => $data['price'],
                'active' => true,
            ]
        );

        Availability::where('professional_id', $veterinarian->id)->whereNull('location_id')->delete();
        foreach (self::WEEKLY_SCHEDULE as $window) {
            Availability::create([
                ...$window,
                'professional_id' => $veterinarian->id,
                'organization_id' => $this->organization->id,
                'location_id' => null,
                'slot_duration' => 40,
                'buffer_time' => 10,
                'is_active' => true,
            ]);
        }

        return $veterinarian->load('professional');
    }

    private function seedStaff(array $data): void
    {
        $member = $this->upsertUser($data['email'], $data['name'], $data['phone'], null);

        $this->ensureMembership($member, $data['role'], [
            'employee_id' => $data['employee_id'],
            'employment_type' => 'full_time',
            'monthly_salary' => $data['salary'],
        ]);
        app(UserRoleReconciler::class)->reconcile($member);
    }

    private function upsertUser(string $email, string $name, string $phone, ?string $userType): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'role' => 'professional',
                'user_type' => $userType,
                'phone' => $phone,
                'profile_completed' => true,
                'registration_status' => 'approved',
                'email_verified_at' => now(),
                'is_suspended' => false,
                ...self::ADDRESS,
            ]
        );
    }

    private function ensureMembership(User $user, string $role, array $attributes = []): void
    {
        OrganizationMember::updateOrCreate(
            ['organization_id' => $this->organization->id, 'user_id' => $user->id],
            [
                'role' => $role,
                'hire_date' => now()->subMonths(8)->toDateString(),
                'termination_date' => null,
                'is_active' => true,
                ...$attributes,
            ]
        );
    }

    /** @return array{organization_id: int, professional_id: int} */
    private function ownership(): array
    {
        return ['organization_id' => $this->organization->id, 'professional_id' => $this->owner->id];
    }

    private function seedClinicServices(): void
    {
        foreach (self::CLINIC_SERVICES as [$name, $category, $duration, $price]) {
            Service::updateOrCreate(
                ['organization_id' => $this->organization->id, 'name' => $name],
                [
                    'professional_id' => $this->owner->id,
                    'category' => $category->value,
                    'duration' => $duration,
                    'price' => $price,
                    'show_in_price_list' => true,
                    'allow_price_override' => true,
                    'active' => true,
                ]
            );
        }
    }

    /** @return array<string, Supplier> */
    private function seedSuppliers(): array
    {
        $suppliers = [];
        foreach (self::SUPPLIERS as $key => $data) {
            $suppliers[$key] = Supplier::updateOrCreate(
                ['organization_id' => $this->organization->id, 'document' => $data['document']],
                [...$this->ownership(), ...$data, 'address_city' => 'São Paulo', 'address_state' => 'SP', 'active' => true]
            );
        }

        return $suppliers;
    }

    /** @param  array<string, Supplier>  $suppliers */
    private function seedProducts(array $suppliers): void
    {
        $stock = app(StockService::class);
        $groups = [];
        foreach (self::GROUPS as $name) {
            $groups[$name] = ProductGroup::updateOrCreate(
                ['organization_id' => $this->organization->id, 'name' => $name],
                ['professional_id' => $this->owner->id, 'default_markup_percent' => 45, 'active' => true]
            );
        }

        $brands = [];
        foreach (self::PRODUCTS as [$sku, $name, $group, $brandName, $supplierKey, $price, $cost, $qty, $min, $batch, $expiry]) {
            $brands[$brandName] ??= Brand::updateOrCreate(
                ['organization_id' => $this->organization->id, 'name' => $brandName],
                ['professional_id' => $this->owner->id, 'active' => true]
            );

            $product = Product::updateOrCreate(
                ['organization_id' => $this->organization->id, 'sku' => $sku],
                [
                    'professional_id' => $this->owner->id,
                    'product_group_id' => $groups[$group]->id,
                    'brand_id' => $brands[$brandName]->id,
                    'last_supplier_id' => $suppliers[$supplierKey]->id,
                    'name' => $name,
                    'unit_of_sale' => 'UN',
                    'purpose' => 'resale',
                    'price' => $price,
                    'average_cost' => $cost,
                    'last_cost' => $cost,
                    'markup_percent' => round(($price / $cost - 1) * 100, 2),
                    'commission_percent' => 3,
                    'track_inventory' => true,
                    'controls_stock' => true,
                    'track_batches' => $batch !== null,
                    'min_stock' => $min,
                    'max_stock' => $qty * 3,
                    'show_in_price_list' => true,
                    'allow_price_override' => true,
                    'is_active' => true,
                ]
            );

            // Saldo só na criação — um novo `run()` não pode empilhar outro saldo de abertura.
            if ($product->wasRecentlyCreated) {
                $stock->in($product, StockMovementType::OPENING_BALANCE, $qty, array_filter([
                    'user' => $this->owner,
                    'unit_cost' => $cost,
                    'batch_code' => $batch,
                    'expires_at' => $expiry !== null ? now()->modify($expiry)->toDateString() : null,
                    'notes' => 'Saldo de abertura (seeder)',
                ], fn ($value) => $value !== null));
            }
        }
    }

    /**
     * Estoque consolidado (docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md):
     * o antigo `Inventory` (insumo clínico) vira `Product` como qualquer outro item do
     * catálogo, só que com `purpose` de uso clínico (`consumable`/`internal_use`) em vez de
     * `resale` — mesmo `StockService`/`ProductGroup`/`Supplier` de `seedProducts()`.
     *
     * @param  array<string, Supplier>  $suppliers  chave = trade_name em maiúsculo (ver `SUPPLIERS`)
     */
    private function seedClinicalProducts(array $suppliers): void
    {
        $classifier = new LegacyInventoryClassifier;
        $stock = app(StockService::class);
        $groups = [];

        foreach (self::CLINICAL_INVENTORY as [$name, $categoryValue, $qty, $unit, $min, $cost, $sell, $supplierName, $expiry]) {
            $category = InventoryCategory::from($categoryValue);
            $groupName = $classifier->groupNameFor($category);
            $groups[$groupName] ??= ProductGroup::updateOrCreate(
                ['organization_id' => $this->organization->id, 'name' => $groupName],
                ['professional_id' => $this->owner->id, 'active' => true]
            );

            $product = Product::updateOrCreate(
                ['organization_id' => $this->organization->id, 'sku' => 'CLIN-'.Str::slug($name)],
                [
                    'professional_id' => $this->owner->id,
                    'product_group_id' => $groups[$groupName]->id,
                    'last_supplier_id' => $suppliers[strtoupper($supplierName)]->id,
                    'name' => $name,
                    'unit_of_sale' => $classifier->unitOfSaleFor($unit),
                    'purpose' => $classifier->purposeFor($category),
                    'price' => $sell ?? 0,
                    'average_cost' => $cost,
                    'last_cost' => $cost,
                    'track_inventory' => true,
                    'controls_stock' => true,
                    'track_batches' => $expiry !== null,
                    'min_stock' => $min,
                    'show_in_price_list' => $sell !== null,
                    'is_active' => true,
                ]
            );

            if ($product->wasRecentlyCreated) {
                $stock->in($product, StockMovementType::OPENING_BALANCE, $qty, array_filter([
                    'user' => $this->owner,
                    'unit_cost' => $cost,
                    'batch_code' => $expiry !== null ? 'CLIN-'.$product->id.'-INICIAL' : null,
                    'expires_at' => $expiry !== null ? now()->modify($expiry)->toDateString() : null,
                    'notes' => 'Saldo de abertura (seeder)',
                ], fn ($value) => $value !== null));
            }
        }
    }
}
