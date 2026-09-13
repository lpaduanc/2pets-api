<?php

/**
 * Rótulos usados por `GET /register/professional-schema` — fonte única dos `label()` de
 * `App\Enums\ProfessionalType`, `App\Enums\ServiceCategory`, `App\Enums\PetSpecies`,
 * `App\Enums\SizeCategory` e dos enums em `App\Enums\Registration\*`. Chave = grupo (nome do
 * enum em snake_case) + valor do case. Este arquivo é o pt-BR (padrão/fallback); o par
 * completo mora em `lang/en_US/registration.php` — toda chave nova aqui precisa da mesma
 * chave lá, senão o locale en-US mostra a chave crua em vez do texto.
 */
return [

    'professional_type' => [
        'vet' => 'Veterinário Volante',
        'clinic' => 'Clínica Veterinária',
        'laboratory' => 'Laboratório',
        'petshop' => 'Pet Shop',
        'pet_hotel' => 'Creche e Hotel',
        'grooming' => 'Banho e Tosa',
        'training' => 'Adestramento',
    ],

    'service_category' => [
        'consultation' => 'Consulta',
        'emergency' => 'Emergência',
        'surgery' => 'Cirurgia',
        'vaccination' => 'Vacinação',
        'grooming' => 'Banho e Tosa',
        'training' => 'Adestramento',
        'boarding' => 'Hospedagem',
        'laboratory' => 'Exames Laboratoriais',
        'imaging' => 'Exames de Imagem',
        'dental' => 'Odontologia',
        'nutrition' => 'Nutrição',
        'behavioral' => 'Comportamental',
        'hospitalization' => 'Internação/UTI',
        'rehabilitation' => 'Fisioterapia e Reabilitação',
        'other' => 'Outro',
    ],

    'clinical_equipment' => [
        'xray_machine' => 'Aparelho de Raio-X',
        'ultrasound_machine' => 'Aparelho de Ultrassom',
        'ecg_machine' => 'Eletrocardiógrafo',
        'surgery_room' => 'Centro Cirúrgico',
        'icu' => 'UTI Veterinária',
        'laboratory' => 'Laboratório Próprio',
        'pharmacy' => 'Farmácia',
        'kennels' => 'Canis/Gatil',
        'oxygen_therapy' => 'Oxigenoterapia',
        'dental_equipment' => 'Equipamento Odontológico',
        'anesthesia_machine' => 'Aparelho de Anestesia',
        'ambulance' => 'Ambulância',
        'portable_multiparameter_monitor' => 'Monitor Multiparamétrico Portátil',
        'portable_oxygen_therapy' => 'Oxigenoterapia Portátil',
        'vascular_doppler' => 'Doppler Vascular',
        'point_of_care_analyzer' => 'Analisador Point-of-Care',
    ],

    'professional_facility' => [
        'parking_available' => 'Estacionamento',
        'wheelchair_accessible' => 'Acessibilidade (cadeirante)',
    ],

    'professional_differential' => [
        'accepts_credit_card' => 'Aceita cartão de crédito',
        'accepts_pet_insurance' => 'Aceita plano de saúde pet',
        'home_visit_available' => 'Atendimento domiciliar',
        'online_consultation' => 'Consulta online',
        'emergency_available' => 'Atende emergência',
        'emergency_24h' => 'Atendimento 24 horas',
        'delivery_available' => 'Entrega em domicílio',
        'online_ordering' => 'Pedido online',
        'cage_free_option' => 'Hospedagem sem gaiola',
        'webcam_access' => 'Webcam para tutores',
        'special_diet_accommodation' => 'Acomoda dietas especiais',
        'mobile_service' => 'Atendimento móvel',
        'group_sessions_available' => 'Aulas em grupo',
    ],

    'professional_additional_field' => [
        'exam_rooms_count' => 'Quantidade de salas de atendimento',
        'training_methodology' => 'Metodologia de treinamento',
        'languages_spoken' => 'Idiomas atendidos',
    ],

    'professional_service_item' => [
        'deworming' => 'Vermifugação',
        'microchip' => 'Microchipagem',
        'health_certificate' => 'Atestado de Saúde',
        'pre_anesthetic' => 'Consulta Pré-Anestésica',
        'nutrition_consultation' => 'Consulta Nutricional',
        'emergency_care' => 'Atendimento de Emergência',
        'behavioral_consultation' => 'Comportamental (Medicina Comportamental)',
        'castration' => 'Castração',
        'surgery_general' => 'Cirurgias em Geral',
        'emergency_surgery' => 'Cirurgia de Emergência',
        'orthopedic_surgery' => 'Cirurgia Ortopédica',
        'soft_tissue' => 'Cirurgia de Tecidos Moles',
        'dental_surgery' => 'Cirurgia Odontológica',
        'xray' => 'Raio-X',
        'ultrasound' => 'Ultrassom',
        'blood_test' => 'Exames de Sangue',
        'urine_test' => 'Exame de Urina',
        'fecal_test' => 'Exame de Fezes',
        'ecg' => 'Eletrocardiograma (ECG)',
        'endoscopy' => 'Endoscopia',
        'biopsy' => 'Biópsia',
        'icu' => 'UTI Veterinária',
        'physiotherapy' => 'Fisioterapia',
        'acupuncture' => 'Acupuntura',
        'post_surgery' => 'Acompanhamento Pós-Cirúrgico',
        'bath' => 'Banho',
        'haircut' => 'Tosa',
        'hygienic_grooming' => 'Tosa Higiênica',
        'nail_trim' => 'Corte de Unhas',
        'ear_cleaning' => 'Limpeza de Ouvidos',
        'teeth_cleaning' => 'Escovação Dentária',
        'pet_spa' => 'SPA Pet',
        'basic_training' => 'Adestramento Básico',
        'socialization' => 'Socialização',
        'agility' => 'Agility',
        'guard_training' => 'Cão de Guarda',
        'daycare' => 'Creche (Day Care)',
        'boarding_long' => 'Hospedagem Longa',
        'cat_hotel' => 'Hotel para Gatos',
        'dog_hotel' => 'Hotel para Cães',
    ],

    'pet_species' => [
        'dog' => 'Cão',
        'cat' => 'Gato',
        'bird' => 'Ave',
        'reptile' => 'Réptil',
        'rodent' => 'Roedor',
        'fish' => 'Peixe',
        'other' => 'Outro',
    ],

    'size_category' => [
        'mini' => 'Mini',
        'small' => 'Pequeno',
        'medium' => 'Médio',
        'large' => 'Grande',
        'giant' => 'Gigante',
    ],

];
