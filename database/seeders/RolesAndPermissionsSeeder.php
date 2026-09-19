<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates all roles and granular permissions for the 2pets platform.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ---------------------------------------------------------------
        // PERMISSIONS — grouped by module
        // ---------------------------------------------------------------

        $permissions = [
            // --- Users / Profile ---
            'users.view',
            'users.view.any',
            'users.update',
            'users.update.any',
            'users.delete',
            'users.delete.any',
            'users.suspend',
            'users.activate',

            // --- Pets ---
            'pets.view.own',
            'pets.view.any',
            'pets.create',
            'pets.update.own',
            'pets.update.any',
            'pets.delete.own',
            'pets.delete.any',

            // --- Appointments ---
            'appointments.view.own',
            'appointments.view.any',
            'appointments.create',
            'appointments.update.own',
            'appointments.update.any',
            'appointments.cancel.own',
            'appointments.cancel.any',

            // --- Medical Records ---
            'medical-records.view.own',
            'medical-records.view.any',
            'medical-records.create',
            'medical-records.update.own',
            'medical-records.update.any',
            'medical-records.delete',

            // --- Vaccinations ---
            'vaccinations.view.own',
            'vaccinations.view.any',
            'vaccinations.create',
            'vaccinations.update',
            'vaccinations.delete',

            // --- Prescriptions ---
            'prescriptions.view.own',
            'prescriptions.view.any',
            'prescriptions.create',
            'prescriptions.update',
            'prescriptions.delete',

            // --- Exams ---
            'exams.view.own',
            'exams.view.any',
            'exams.create',
            'exams.update',
            'exams.delete',

            // --- Hospitalizations ---
            'hospitalizations.view.own',
            'hospitalizations.view.any',
            'hospitalizations.create',
            'hospitalizations.update',
            'hospitalizations.discharge',

            // --- Surgeries ---
            'surgeries.view.own',
            'surgeries.view.any',
            'surgeries.create',
            'surgeries.update',
            'surgeries.cancel',

            // --- Services ---
            'services.view',
            'services.create',
            'services.update',
            'services.delete',

            // --- Inventory ---
            'inventory.view',
            'inventory.create',
            'inventory.update',
            'inventory.delete',

            // --- Invoices ---
            'invoices.view.own',
            'invoices.view.any',
            'invoices.create',
            'invoices.update',
            'invoices.delete',

            // --- Reviews ---
            'reviews.view',
            'reviews.create',
            'reviews.respond',
            'reviews.moderate',

            // --- Payments ---
            'payments.view.own',
            'payments.view.any',
            'payments.create',
            'payments.refund',

            // --- Documents ---
            'documents.upload',
            'documents.view.own',
            'documents.view.any',
            'documents.verify',
            'documents.reject',

            // --- Notifications ---
            'notifications.view.own',
            'notifications.manage.preferences',

            // --- Messages ---
            'messages.view.own',
            'messages.send',

            // --- Reports ---
            'reports.view.own',
            'reports.view.any',
            'reports.download',

            // --- Subscriptions ---
            'subscriptions.view.own',
            'subscriptions.manage',
            'subscriptions.view.any',

            // --- Admin ---
            'admin.dashboard',
            'admin.users.manage',
            'admin.companies.manage',
            'admin.documents.manage',
            'admin.reviews.moderate',
            'admin.settings',

            // --- Professional Profile ---
            'professional.profile.view',
            'professional.profile.update',
            'professional.dashboard',
            'professional.clients.view',
            'professional.clients.manage',

            // --- Pet Card ---
            'pet-card.view',
            'pet-card.generate-qr',
            'pet-card.mark-lost',

            // --- Video Consultations ---
            'video-consultations.create',
            'video-consultations.join',
            'video-consultations.manage',

            // --- Reminders ---
            'reminders.view.own',
            'reminders.manage',

            // --- Staff ---
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',
            'staff.schedules.manage',
        ];

        // Create all permissions for the 'web' guard
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Segunda invalidação, de propósito: as 113 linhas acima podem ter sido
        // criadas com os eventos de model mudos (o trait `RefreshesPermissionCache`
        // do spatie só limpa o cache por `saved`/`deleted`). Sem isto, o
        // `syncPermissions` abaixo lê a coleção vazia que sobrou no cache e
        // estoura com "There is no permission named `users.view`".
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ---------------------------------------------------------------
        // ROLES
        // ---------------------------------------------------------------

        // 1. Tutor
        $tutor = Role::findOrCreate('tutor', 'web');
        $tutor->syncPermissions([
            'users.view', 'users.update', 'users.delete',
            'pets.view.own', 'pets.create', 'pets.update.own', 'pets.delete.own',
            'appointments.view.own', 'appointments.create', 'appointments.cancel.own',
            'vaccinations.view.own',
            'prescriptions.view.own',
            'exams.view.own',
            'hospitalizations.view.own',
            'surgeries.view.own',
            'invoices.view.own',
            'reviews.view', 'reviews.create',
            'payments.view.own', 'payments.create',
            'documents.upload', 'documents.view.own',
            'notifications.view.own', 'notifications.manage.preferences',
            'messages.view.own', 'messages.send',
            'reports.view.own', 'reports.download',
            'subscriptions.view.own', 'subscriptions.manage',
            'pet-card.view', 'pet-card.generate-qr', 'pet-card.mark-lost',
            'reminders.view.own', 'reminders.manage',
            'medical-records.view.own',
            'video-consultations.create', 'video-consultations.join',
        ]);

        // 2. Vet Freelancer
        $vetFreelancer = Role::findOrCreate('vet_freelancer', 'web');
        $vetFreelancer->syncPermissions([
            'users.view', 'users.update',
            'pets.view.any', 'pets.create', 'pets.update.any',
            'appointments.view.any', 'appointments.create', 'appointments.update.own', 'appointments.cancel.own',
            'medical-records.view.any', 'medical-records.create', 'medical-records.update.own',
            'vaccinations.view.any', 'vaccinations.create', 'vaccinations.update',
            'prescriptions.view.any', 'prescriptions.create', 'prescriptions.update',
            'exams.view.any', 'exams.create', 'exams.update',
            'hospitalizations.view.any', 'hospitalizations.create', 'hospitalizations.update', 'hospitalizations.discharge',
            'surgeries.view.any', 'surgeries.create', 'surgeries.update', 'surgeries.cancel',
            'services.view', 'services.create', 'services.update', 'services.delete',
            'invoices.view.any', 'invoices.create', 'invoices.update',
            'reviews.view', 'reviews.respond',
            'payments.view.any', 'payments.create',
            'documents.upload', 'documents.view.own',
            'notifications.view.own', 'notifications.manage.preferences',
            'messages.view.own', 'messages.send',
            'reports.view.own', 'reports.download',
            'subscriptions.view.own', 'subscriptions.manage',
            'professional.profile.view', 'professional.profile.update', 'professional.dashboard',
            'professional.clients.view', 'professional.clients.manage',
            'video-consultations.create', 'video-consultations.join', 'video-consultations.manage',
            'reminders.view.own', 'reminders.manage',
        ]);

        // 3. Clinic Owner
        //
        // Dono de clínica é papel ADMINISTRATIVO, não clínico. Criar prontuário, receita,
        // vacina, exame, internação e cirurgia é ato privativo de médico-veterinário pessoa
        // física com CRMV ativo — Lei 5.517/1968 art. 1º, Res. CFMV 1.318/2020 (prescrição) e
        // Res. CFMV 1.321/2020 alterada pela 1.653/2025 (cada evolução do prontuário exige nome
        // e CRMV do autor). Uma conta `clinic_owner` pode não ter CRMV nenhum: conceder `*.create`
        // aqui autorizaria um CNPJ a assinar ato clínico.
        //
        // O dono que TAMBÉM clinica recebe essas permissões pelo papel de veterinário
        // (`vet_freelancer`/`clinic_vet`) acumulado, nunca por ser dono. Ver a decisão completa
        // em `docs/rbac-clinica-autoria-e-staff.md`.
        $clinicOwner = Role::findOrCreate('clinic_owner', 'web');
        $clinicOwner->syncPermissions([
            'users.view', 'users.update',
            'pets.view.any', 'pets.create', 'pets.update.any',
            'appointments.view.any', 'appointments.create', 'appointments.update.any', 'appointments.cancel.any',
            // Dado clínico é LEITURA para o dono. Editar também é ato clínico — a Res. CFMV
            // 1.321/2020 (alterada pela 1.653/2025) exige nome e CRMV do responsável em CADA
            // evolução, então não existe edição anônima ou assinada por CNPJ. E apagar é pior:
            // prontuário tem guarda obrigatória, não se apaga na operação do dia a dia.
            // `*.delete` e `medical-records.update.any` ficam só com `super_admin`, de propósito.
            'medical-records.view.any',
            'vaccinations.view.any',
            'prescriptions.view.any',
            'exams.view.any',
            'hospitalizations.view.any',
            'surgeries.view.any',
            'services.view', 'services.create', 'services.update', 'services.delete',
            'inventory.view', 'inventory.create', 'inventory.update', 'inventory.delete',
            'invoices.view.any', 'invoices.create', 'invoices.update', 'invoices.delete',
            'reviews.view', 'reviews.respond',
            'payments.view.any', 'payments.create', 'payments.refund',
            'documents.upload', 'documents.view.own',
            'notifications.view.own', 'notifications.manage.preferences',
            'messages.view.own', 'messages.send',
            'reports.view.any', 'reports.download',
            'subscriptions.view.own', 'subscriptions.manage',
            'professional.profile.view', 'professional.profile.update', 'professional.dashboard',
            'professional.clients.view', 'professional.clients.manage',
            'video-consultations.create', 'video-consultations.join', 'video-consultations.manage',
            'reminders.view.own', 'reminders.manage',
            'staff.view', 'staff.create', 'staff.update', 'staff.delete', 'staff.schedules.manage',
        ]);

        // 4. Clinic Vet (employee)
        $clinicVet = Role::findOrCreate('clinic_vet', 'web');
        $clinicVet->syncPermissions([
            'users.view', 'users.update',
            'pets.view.any', 'pets.create', 'pets.update.any',
            'appointments.view.any', 'appointments.create', 'appointments.update.own',
            'medical-records.view.any', 'medical-records.create', 'medical-records.update.own',
            'vaccinations.view.any', 'vaccinations.create', 'vaccinations.update',
            'prescriptions.view.any', 'prescriptions.create', 'prescriptions.update',
            'exams.view.any', 'exams.create', 'exams.update',
            'hospitalizations.view.any', 'hospitalizations.create', 'hospitalizations.update', 'hospitalizations.discharge',
            'surgeries.view.any', 'surgeries.create', 'surgeries.update', 'surgeries.cancel',
            'services.view',
            'invoices.view.any', 'invoices.create',
            'reviews.view', 'reviews.respond',
            'documents.upload', 'documents.view.own',
            'notifications.view.own', 'notifications.manage.preferences',
            'messages.view.own', 'messages.send',
            'reports.view.own', 'reports.download',
            'professional.profile.view', 'professional.dashboard',
            'professional.clients.view',
            'video-consultations.create', 'video-consultations.join', 'video-consultations.manage',
            'reminders.view.own', 'reminders.manage',
        ]);

        // 5. Petshop Owner
        $petshopOwner = Role::findOrCreate('petshop_owner', 'web');
        $petshopOwner->syncPermissions([
            'users.view', 'users.update',
            'pets.view.any',
            'appointments.view.any', 'appointments.create', 'appointments.update.any', 'appointments.cancel.any',
            'services.view', 'services.create', 'services.update', 'services.delete',
            'inventory.view', 'inventory.create', 'inventory.update', 'inventory.delete',
            'invoices.view.any', 'invoices.create', 'invoices.update', 'invoices.delete',
            'reviews.view', 'reviews.respond',
            'payments.view.any', 'payments.create', 'payments.refund',
            'documents.upload', 'documents.view.own',
            'notifications.view.own', 'notifications.manage.preferences',
            'messages.view.own', 'messages.send',
            'reports.view.any', 'reports.download',
            'subscriptions.view.own', 'subscriptions.manage',
            'professional.profile.view', 'professional.profile.update', 'professional.dashboard',
            'professional.clients.view', 'professional.clients.manage',
            'reminders.view.own', 'reminders.manage',
            'staff.view', 'staff.create', 'staff.update', 'staff.delete', 'staff.schedules.manage',
        ]);

        // 6. Petshop Staff
        $petshopStaff = Role::findOrCreate('petshop_staff', 'web');
        $petshopStaff->syncPermissions([
            'users.view',
            'pets.view.any',
            'appointments.view.any', 'appointments.create', 'appointments.update.own',
            'services.view',
            'inventory.view', 'inventory.update',
            'invoices.view.any', 'invoices.create',
            'reviews.view',
            'documents.view.own',
            'notifications.view.own',
            'messages.view.own', 'messages.send',
            'professional.profile.view', 'professional.dashboard',
            'professional.clients.view',
        ]);

        // 7. Admin
        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions([
            'admin.dashboard',
            'admin.users.manage',
            'admin.companies.manage',
            'admin.documents.manage',
            'admin.reviews.moderate',
            'users.view.any', 'users.update.any', 'users.delete.any',
            'users.suspend', 'users.activate',
            'pets.view.any',
            'appointments.view.any',
            'medical-records.view.any',
            'vaccinations.view.any',
            'prescriptions.view.any',
            'exams.view.any',
            'hospitalizations.view.any',
            'surgeries.view.any',
            'services.view',
            'invoices.view.any',
            'reviews.view', 'reviews.moderate',
            'payments.view.any', 'payments.refund',
            'documents.view.any', 'documents.verify', 'documents.reject',
            'reports.view.any', 'reports.download',
            'subscriptions.view.any',
        ]);

        // 8. Super Admin — gets ALL permissions
        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());
    }
}
